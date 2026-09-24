<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class ReconciliationService
{
    /**
     * Bandingkan nilai mutasi persediaan dengan akun kontrol GL pada tanggal laporan.
     * Biaya nol pada histori dan kuantitas nol pada koreksi biaya tetap sah.
     * Gunakan total_cost tersimpan, bukan quantity dikalikan unit_cost yang dibulatkan.
     *
     * @return array{
     *     as_of_date: string, account_id: int, account_code: string,
     *     gl_balance: string, inventory_balance: string, difference: string, balanced: bool
     * }
     */
    public function inventoryAsOf(string $asOfDate): array
    {
        if (Validator::make(
            ['date' => $asOfDate],
            ['date' => ['required', 'date_format:Y-m-d']]
        )->fails()) {
            throw new InvalidArgumentException('Tanggal laporan tidak valid.');
        }

        $account = Account::query()->where('system_key', 'inventory')->sole();
        $report = app(FinancialReportingService::class)->trialBalance($asOfDate, $asOfDate);
        $row = $report['rows']->get($account->id);
        $glBalance = BigDecimal::of($row['closing_debit'] ?? '0.00')
            ->minus($row['closing_credit'] ?? '0.00');
        $inventoryBalance = BigDecimal::of('0.00');

        $movements = DB::table('inventory_movements')
            ->select('movement_type', 'quantity', 'unit_cost', 'total_cost')
            ->where('transaction_date', '<=', $asOfDate)
            ->orderBy('id')
            ->cursor();

        foreach ($movements as $movement) {
            if (
                ! in_array($movement->movement_type, ['in', 'out'], true)
                || ! preg_match('/^[0-9]{1,9}(\.[0-9]{1,3})?$/D', (string) $movement->quantity)
                || ! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', (string) $movement->unit_cost)
                || ! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', (string) $movement->total_cost)
            ) {
                throw new RuntimeException('Terdapat mutasi persediaan dengan jenis, kuantitas, atau biaya tidak valid.');
            }

            $inventoryBalance = $movement->movement_type === 'in'
                ? $inventoryBalance->plus((string) $movement->total_cost)
                : $inventoryBalance->minus((string) $movement->total_cost);
        }

        $difference = $inventoryBalance->minus($glBalance);

        return [
            'as_of_date' => $asOfDate,
            'account_id' => $account->id,
            'account_code' => $account->code,
            'gl_balance' => (string) $glBalance->toScale(2),
            'inventory_balance' => (string) $inventoryBalance->toScale(2),
            'difference' => (string) $difference->toScale(2),
            'balanced' => $difference->isZero(),
        ];
    }

    /**
     * Bandingkan jumlah rekening fisik per jenis dengan akun kontrol GL.
     * Rincian rekening hanya berisi saldo subledger, bukan rekonsiliasi GL individual.
     *
     * @return array{
     *     as_of_date: string,
     *     groups: array<string, array{
     *         account_id: int, account_code: string, gl_balance: string,
     *         cash_balance: string, difference: string, balanced: bool,
     *         cash_accounts: list<array{
     *             id: int, code: string, name: string, is_active: bool, balance: string
     *         }>
     *     }>,
     *     balanced: bool
     * }
     */
    public function cashAndBankAsOf(string $asOfDate): array
    {
        if (Validator::make(
            ['date' => $asOfDate],
            ['date' => ['required', 'date_format:Y-m-d']]
        )->fails()) {
            throw new InvalidArgumentException('Tanggal laporan tidak valid.');
        }

        $accountTypes = [CashAccount::TYPE_CASH, CashAccount::TYPE_BANK];
        $cashAccounts = CashAccount::query()->orderBy('code')->get()->keyBy('id');
        $balances = [];

        foreach ($cashAccounts as $cashAccount) {
            if (! in_array($cashAccount->account_type, $accountTypes, true)) {
                throw new RuntimeException('Terdapat akun Kas/Bank dengan jenis tidak valid.');
            }

            $balances[$cashAccount->id] = BigDecimal::of('0.00');
        }

        $mutations = DB::table('cash_mutations')
            ->select('id', 'cash_account_id', 'mutation_type', 'amount')
            ->where('transaction_date', '<=', $asOfDate)
            ->orderBy('id')
            ->cursor();

        foreach ($mutations as $mutation) {
            if (! $cashAccounts->has($mutation->cash_account_id)) {
                throw new RuntimeException('Akun Kas/Bank untuk mutasi tidak ditemukan.');
            }

            if (
                ! in_array($mutation->mutation_type, [CashMutation::TYPE_IN, CashMutation::TYPE_OUT], true)
                || ! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', (string) $mutation->amount)
                || BigDecimal::of((string) $mutation->amount)->isLessThanOrEqualTo(0)
            ) {
                throw new RuntimeException('Terdapat mutasi Kas/Bank dengan jenis atau nominal tidak valid.');
            }

            $balance = $balances[$mutation->cash_account_id];
            $balances[$mutation->cash_account_id] = $mutation->mutation_type === CashMutation::TYPE_IN
                ? $balance->plus((string) $mutation->amount)
                : $balance->minus((string) $mutation->amount);
        }

        $report = app(FinancialReportingService::class)
            ->trialBalance($asOfDate, $asOfDate);
        $groups = [];
        $balanced = true;

        foreach ($accountTypes as $accountType) {
            $account = Account::query()->where('system_key', $accountType)->sole();
            $row = $report['rows']->get($account->id);
            $glBalance = BigDecimal::of($row['closing_debit'] ?? '0.00')
                ->minus($row['closing_credit'] ?? '0.00');
            $cashBalance = BigDecimal::of('0.00');
            $details = [];

            foreach ($cashAccounts as $cashAccount) {
                if ($cashAccount->account_type !== $accountType) {
                    continue;
                }

                $balance = $balances[$cashAccount->id];
                $cashBalance = $cashBalance->plus($balance);
                $details[] = [
                    'id' => $cashAccount->id,
                    'code' => $cashAccount->code,
                    'name' => $cashAccount->name,
                    'is_active' => $cashAccount->is_active,
                    'balance' => (string) $balance->toScale(2),
                ];
            }

            $difference = $cashBalance->minus($glBalance);
            $groups[$accountType] = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'gl_balance' => (string) $glBalance->toScale(2),
                'cash_balance' => (string) $cashBalance->toScale(2),
                'difference' => (string) $difference->toScale(2),
                'balanced' => $difference->isZero(),
                'cash_accounts' => $details,
            ];
            $balanced = $balanced && $difference->isZero();
        }

        return [
            'as_of_date' => $asOfDate,
            'groups' => $groups,
            'balanced' => $balanced,
        ];
    }

    /**
     * Bandingkan tabungan nasabah dengan akun GL pada tanggal yang sama.
     * Selisih = saldo mutasi nasabah dikurangi saldo GL.
     * Metode ini hanya membaca data dan tidak membuat jurnal penyesuaian.
     */
    public function customerSavingsAsOf(string $asOfDate): array
    {
        if (Validator::make(
            ['date' => $asOfDate],
            ['date' => ['required', 'date_format:Y-m-d']]
        )->fails()) {
            throw new InvalidArgumentException('Tanggal laporan tidak valid.');
        }

        $account = Account::query()
            ->where('system_key', 'customer_savings')
            ->sole();

        // Sisi kredit normal untuk kewajiban; saldo debit tetap bernilai negatif.
        $report = app(FinancialReportingService::class)
            ->trialBalance($asOfDate, $asOfDate);
        $row = $report['rows']->get($account->id);

        $glBalance = BigDecimal::of($row['closing_credit'] ?? '0.00')
            ->minus($row['closing_debit'] ?? '0.00');

        // Mutasi sesudah tanggal laporan tidak ikut dihitung.
        $totals = DB::table('balance_mutations')
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credits"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debits"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type NOT IN ('credit', 'debit') OR amount < 0 "
                .'THEN 1 ELSE 0 END), 0) AS invalid_count'
            )
            ->first();

        if ((int) $totals->invalid_count > 0) {
            throw new RuntimeException(
                'Terdapat mutasi tabungan dengan jenis atau nominal tidak valid.'
            );
        }

        $customerBalance = BigDecimal::of((string) $totals->credits)
            ->minus((string) $totals->debits);

        $difference = $customerBalance->minus($glBalance);

        return [
            'as_of_date' => $asOfDate,
            'account_id' => $account->id,
            'account_code' => $account->code,
            'gl_balance' => (string) $glBalance->toScale(2),
            'customer_balance' => (string) $customerBalance->toScale(2),
            'difference' => (string) $difference->toScale(2),
            'balanced' => $difference->isZero(),
        ];
    }
}
