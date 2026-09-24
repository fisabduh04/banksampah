<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\InventoryMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use RuntimeException;

class CashFlowReportingService
{
    private const SOURCE_LABELS = [
        'sale_payment' => 'Penerimaan pembayaran pengepul',
        'sale_payment_cancellation' => 'Pembatalan pembayaran pengepul',
        'withdrawal' => 'Penarikan tabungan nasabah',
        'withdrawal_cancellation' => 'Pembatalan penarikan tabungan',
        'manual_receipt' => 'Penerimaan manual',
        'manual_expense' => 'Pengeluaran manual',
    ];

    public function __construct(private FinancialReportingService $financialReporting) {}

    /**
     * Mutasi kas adalah sumber arus uang; GL hanya menjadi pembanding.
     * Transfer dipisahkan dari arus eksternal, tetapi netonya tetap mengubah saldo.
     * Pembatalan dihitung pada tanggal mutasinya, tanpa menyaring status sumber saat ini.
     *
     * @return array{valid: bool, start_date: ?string, end_date: ?string, rows: Collection,
     *     groups: array, totals: array, balanced: bool, trial_balance_balanced: bool,
     *     transfer_balanced: bool, operations: Collection}
     */
    public function cashFlow(?string $startDate, ?string $endDate): array
    {
        $trialBalance = $this->financialReporting->trialBalance($startDate, $endDate);
        $report = [
            'valid' => $trialBalance['valid'], 'start_date' => $startDate, 'end_date' => $endDate,
            'rows' => collect(), 'groups' => [], 'totals' => [], 'balanced' => false,
            'trial_balance_balanced' => $trialBalance['balanced'], 'transfer_balanced' => false,
            'operations' => collect(),
        ];
        if (! $report['valid']) {
            return $report;
        }

        $fields = ['opening', 'receipts', 'payments', 'transfer_in', 'transfer_out',
            'opening_entries_in', 'opening_entries_out', 'closing', 'gl_opening', 'gl_closing'];
        foreach (['cash', 'bank'] as $type) {
            $report['groups'][$type] = array_fill_keys($fields, BigDecimal::of('0.00'));
        }
        $accounts = CashAccount::query()->get()->keyBy('id');
        foreach ($accounts as $account) {
            if (! in_array($account->account_type, ['cash', 'bank'], true)) {
                throw new RuntimeException('Terdapat jenis rekening kas/bank tidak valid.');
            }
        }

        foreach (CashMutation::query()->where('transaction_date', '<=', $endDate)->cursor() as $mutation) {
            $account = $accounts->get($mutation->cash_account_id);
            if ($account === null || ! in_array($mutation->mutation_type, ['in', 'out'], true)) {
                throw new RuntimeException('Terdapat mutasi kas dengan rekening atau jenis tidak valid.');
            }
            $amount = $this->decimal($mutation->amount, 2, false);
            $signed = $mutation->mutation_type === 'in' ? $amount : $amount->negated();
            $group = &$report['groups'][$account->account_type];
            $group['closing'] = $group['closing']->plus($signed);
            if ($mutation->transaction_date->toDateString() < $startDate) {
                $group['opening'] = $group['opening']->plus($signed);
                unset($group);

                continue;
            }

            if (in_array($mutation->reference_type, ['transfer', 'opening_balance'], true)) {
                $prefix = $mutation->reference_type === 'transfer' ? 'transfer' : 'opening_entries';
                $field = $prefix.'_'.$mutation->mutation_type;
                $group[$field] = $group[$field]->plus($amount);
            } else {
                $field = $mutation->mutation_type === 'in' ? 'receipts' : 'payments';
                $group[$field] = $group[$field]->plus($amount);
                $source = $mutation->reference_type;
                $row = $report['rows']->get($source, [
                    'id' => $source, 'label' => self::SOURCE_LABELS[$source] ?? 'Belum diklasifikasikan: '.$source,
                    'receipts' => '0.00', 'payments' => '0.00',
                ]);
                $row[$field] = (string) BigDecimal::of($row[$field])->plus($amount);
                $report['rows']->put($source, $row);
            }
            unset($group);
        }

        $report['totals'] = array_fill_keys($fields, BigDecimal::of('0.00'));
        foreach (['cash', 'bank'] as $type) {
            $account = Account::query()->where('system_key', $type)->sole();
            $row = $trialBalance['rows']->get($account->id);
            $group = &$report['groups'][$type];
            foreach (['opening', 'closing'] as $period) {
                $group['gl_'.$period] = BigDecimal::of($row[$period.'_debit'] ?? '0.00')
                    ->minus($row[$period.'_credit'] ?? '0.00');
            }
            foreach ($fields as $field) {
                $report['totals'][$field] = $report['totals'][$field]->plus($group[$field]);
            }
            $group = $this->balances($group);
            unset($group);
        }
        $report['totals'] = $this->balances($report['totals']);
        $report['transfer_balanced'] = BigDecimal::of($report['totals']['transfer_net'])->isZero();
        $report['balanced'] = $trialBalance['balanced'] && $report['transfer_balanced']
            && $report['groups']['cash']['balanced'] && $report['groups']['bank']['balanced'];
        $report['operations'] = $this->inventoryOperations($startDate, $endDate);

        return $report;
    }

    /**
     * @param  array<string, BigDecimal>  $amounts
     * @return array<string, string|bool>
     */
    private function balances(array $amounts): array
    {
        $amounts['external_net'] = $amounts['receipts']->minus($amounts['payments']);
        $amounts['transfer_net'] = $amounts['transfer_in']->minus($amounts['transfer_out']);
        $amounts['opening_entries_net'] = $amounts['opening_entries_in']->minus($amounts['opening_entries_out']);
        $amounts['net_change'] = $amounts['external_net']->plus($amounts['transfer_net'])->plus($amounts['opening_entries_net']);
        $amounts['opening_difference'] = $amounts['opening']->minus($amounts['gl_opening']);
        $amounts['difference'] = $amounts['closing']->minus($amounts['gl_closing']);
        $balanced = $amounts['opening_difference']->isZero() && $amounts['difference']->isZero();
        foreach ($amounts as &$amount) {
            $amount = (string) $amount->toScale(2);
        }

        return [...$amounts, 'balanced' => $balanced];
    }

    /**
     * Berat dan nilai biaya tercatat, bukan omzet. Koreksi bernilai/berkuantitas nol sah.
     * Semua referensi dipertahankan, termasuk koreksi biaya historis.
     *
     * @return Collection<string, array{id: string, label: string, quantity_in: string,
     *     quantity_out: string, value_in: string, value_out: string}>
     */
    private function inventoryOperations(string $startDate, string $endDate): Collection
    {
        $labels = [
            'deposit' => 'Setoran sampah (non-tunai)',
            'deposit_cancellation' => 'Pembatalan setoran sampah',
            'sale' => 'Sampah terjual (nilai biaya/HPP)',
            'sale_cancellation' => 'Pembatalan penjualan sampah',
            'cost_reconciliation' => 'Koreksi biaya persediaan',
            'cost_reconciliation_reversal' => 'Pembalik koreksi biaya persediaan',
        ];
        $rows = collect();
        foreach (InventoryMovement::query()->whereBetween('transaction_date', [$startDate, $endDate])->cursor() as $movement) {
            if (! in_array($movement->movement_type, ['in', 'out'], true)) {
                throw new RuntimeException('Terdapat jenis mutasi persediaan tidak valid.');
            }
            $quantity = $this->decimal($movement->quantity, 3, true);
            $value = $this->decimal($movement->total_cost, 2, true);
            $source = $movement->reference_type;
            $row = $rows->get($source, [
                'id' => $source, 'label' => $labels[$source] ?? 'Mutasi lain: '.$source,
                'quantity_in' => '0.000', 'quantity_out' => '0.000', 'value_in' => '0.00', 'value_out' => '0.00',
            ]);
            $quantityField = 'quantity_'.$movement->movement_type;
            $valueField = 'value_'.$movement->movement_type;
            $row[$quantityField] = (string) BigDecimal::of($row[$quantityField])->plus($quantity);
            $row[$valueField] = (string) BigDecimal::of($row[$valueField])->plus($value);
            $rows->put($source, $row);
        }

        return $rows;
    }

    private function decimal(string $value, int $scale, bool $allowZero): BigDecimal
    {
        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,'.$scale.'})?$/D', $value)) {
            throw new RuntimeException('Terdapat nominal atau kuantitas mutasi tidak valid.');
        }
        $amount = BigDecimal::of($value)->toScale($scale);
        if (! $allowZero && $amount->isZero()) {
            throw new RuntimeException('Terdapat nominal mutasi kas nol yang tidak valid.');
        }

        return $amount;
    }
}
