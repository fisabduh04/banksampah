<?php

namespace App\Services;

use App\Models\JournalEntry;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class DashboardReportingService
{
    public function __construct(private ReconciliationService $reconciliation, private FinancialReportingService $financialReporting) {}

    /**
     * Satu snapshot dibagikan ke semua widget. Saldo tetap berasal dari service
     * rekonsiliasi; grafik dibaca sekaligus untuk enam bulan, bukan per bulan.
     *
     * @return array{as_of_date: string, start_date: string, cards: array, balanced: bool,
     *     trial_balance_balanced: bool, months: list<string>, labels: list<string>,
     *     series: array<string, list<string>>, activity: array<string, bool>}
     */
    public function snapshot(string $asOfDate): array
    {
        if (Validator::make(['date' => $asOfDate], ['date' => ['required', 'date_format:Y-m-d']])->fails()) {
            throw new InvalidArgumentException('Tanggal dashboard tidak valid.');
        }
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate);
        $start = $end->startOfMonth()->subMonths(5);
        $cash = $this->reconciliation->cashAndBankAsOf($asOfDate);
        $savings = $this->reconciliation->customerSavingsAsOf($asOfDate);
        $inventory = $this->reconciliation->inventoryAsOf($asOfDate);
        $trialBalance = $this->financialReporting->trialBalance($asOfDate, $asOfDate);
        $cards = [];
        foreach (['cash' => 'Kas Tunai', 'bank' => 'Rekening Bank'] as $type => $label) {
            $cards[$type] = [
                'label' => $label, 'balance' => $cash['groups'][$type]['cash_balance'],
                'gl_balance' => $cash['groups'][$type]['gl_balance'],
                'difference' => $cash['groups'][$type]['difference'], 'balanced' => $cash['groups'][$type]['balanced'],
            ];
        }
        foreach (['savings' => ['Tabungan Nasabah', $savings, 'customer_balance'],
            'inventory' => ['Nilai Persediaan', $inventory, 'inventory_balance']] as $key => [$label, $report, $field]) {
            $cards[$key] = [
                'label' => $label, 'balance' => $report[$field], 'gl_balance' => $report['gl_balance'],
                'difference' => $report['difference'], 'balanced' => $report['balanced'],
            ];
        }

        $months = [];
        $labels = [];
        for ($index = 0; $index < 6; $index++) {
            $month = $start->addMonths($index);
            $months[] = $month->format('Y-m');
            $labels[] = $month->locale('id')->translatedFormat('M Y');
        }
        $series = array_fill_keys(['deposits', 'withdrawals', 'sales', 'payments'], array_fill_keys($months, '0.00'));
        $activity = array_fill_keys(array_keys($series), false);

        $balances = DB::table('balance_mutations')
            ->whereBetween('transaction_date', [$start->toDateString(), $asOfDate])
            ->whereIn('reference_type', ['deposit', 'deposit_cancellation', 'withdrawal', 'withdrawal_cancellation'])
            ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') AS month, reference_type, type, SUM(amount) AS amount")
            ->groupBy('month', 'reference_type', 'type')->get();
        foreach ($balances as $row) {
            $key = in_array($row->reference_type, ['deposit', 'deposit_cancellation'], true) ? 'deposits' : 'withdrawals';
            $positiveType = $key === 'deposits' ? 'credit' : 'debit';
            $amount = BigDecimal::of((string) $row->amount);
            $series[$key][$row->month] = (string) BigDecimal::of($series[$key][$row->month])
                ->plus($row->type === $positiveType ? $amount : $amount->negated())->toScale(2);
            $activity[$key] = true;
        }

        /** Nilai penjualan adalah kredit pendapatan bersih pada jurnal bisnis, bukan HPP atau pembayaran. */
        $sales = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.system_key', 'sales_revenue')
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereIn('journal_entries.reference_type', ['sale', 'sale_cancellation'])
            ->whereBetween('journal_entries.transaction_date', [$start->toDateString(), $asOfDate])
            ->selectRaw("DATE_FORMAT(journal_entries.transaction_date, '%Y-%m') AS month, SUM(journal_lines.credit) AS credits, SUM(journal_lines.debit) AS debits")
            ->groupBy('month')->get();
        foreach ($sales as $row) {
            $series['sales'][$row->month] = (string) BigDecimal::of((string) $row->credits)->minus((string) $row->debits)->toScale(2);
            $activity['sales'] = true;
        }

        $payments = DB::table('cash_mutations')
            ->whereBetween('transaction_date', [$start->toDateString(), $asOfDate])
            ->whereIn('reference_type', ['sale_payment', 'sale_payment_cancellation'])
            ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') AS month, mutation_type, SUM(amount) AS amount")
            ->groupBy('month', 'mutation_type')->get();
        foreach ($payments as $row) {
            $amount = BigDecimal::of((string) $row->amount);
            $series['payments'][$row->month] = (string) BigDecimal::of($series['payments'][$row->month])
                ->plus($row->mutation_type === 'in' ? $amount : $amount->negated())->toScale(2);
            $activity['payments'] = true;
        }
        foreach ($series as &$values) {
            $values = array_values($values);
        }

        return [
            'as_of_date' => $asOfDate, 'start_date' => $start->toDateString(), 'cards' => $cards,
            'balanced' => $cash['balanced'] && $savings['balanced'] && $inventory['balanced'] && $trialBalance['balanced'],
            'trial_balance_balanced' => $trialBalance['balanced'],
            'months' => $months, 'labels' => $labels, 'series' => $series, 'activity' => $activity,
        ];
    }
}
