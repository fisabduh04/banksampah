<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class FinancialReportingService
{
    /** Kolom nominal untuk baris dan total Neraca Saldo. */
    private const BALANCE_FIELDS = [
        'opening_debit',
        'opening_credit',
        'period_debit',
        'period_credit',
        'closing_debit',
        'closing_credit',
    ];

    /**
     * Laba rugi menggunakan mutasi periode, tanpa membawa saldo awal pendapatan/beban.
     *
     * @return array{valid: bool, balanced: bool, start_date: ?string, end_date: ?string,
     *     rows: Collection, totals: array{revenue: string, expense: string, net_profit: string}}
     */
    public function incomeStatement(?string $startDate, ?string $endDate): array
    {
        $report = $this->trialBalance($startDate, $endDate);
        $groups = $this->classifiedBalances($report, 'period');
        $revenue = $groups[Account::TYPE_REVENUE];
        $expense = $groups[Account::TYPE_EXPENSE];

        return [
            'valid' => $report['valid'],
            'balanced' => $report['balanced'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rows' => $revenue['rows']->union($expense['rows']),
            'totals' => [
                'revenue' => $revenue['total'],
                'expense' => $expense['total'],
                'net_profit' => (string) BigDecimal::of($revenue['total'])
                    ->minus($expense['total'])->toScale(2),
            ],
        ];
    }

    /**
     * Saldo kumulatif sampai tanggal laporan, termasuk seluruh saldo awal.
     * Laba belum ditutup adalah saldo pendapatan dikurangi beban yang masih tersisa;
     * pemindahan laba ke ekuitas melalui jurnal tidak boleh dihitung dua kali.
     *
     * @return array{valid: bool, as_of_date: ?string, rows: Collection,
     *     totals: array{assets: string, liabilities: string, equity: string,
     *         unclosed_earnings: string, equity_including_earnings: string, liabilities_and_equity: string},
     *     difference: string, balanced: bool}
     */
    public function balanceSheet(?string $asOfDate): array
    {
        $report = $this->trialBalance($asOfDate, $asOfDate);
        $groups = $this->classifiedBalances($report, 'closing');
        $assets = $groups[Account::TYPE_ASSET];
        $liabilities = $groups[Account::TYPE_LIABILITY];
        $equity = $groups[Account::TYPE_EQUITY];
        $earnings = BigDecimal::of($groups[Account::TYPE_REVENUE]['total'])
            ->minus($groups[Account::TYPE_EXPENSE]['total']);
        $totalEquity = BigDecimal::of($equity['total'])->plus($earnings);
        $liabilitiesAndEquity = $totalEquity->plus($liabilities['total']);
        $difference = BigDecimal::of($assets['total'])->minus($liabilitiesAndEquity);

        return [
            'valid' => $report['valid'],
            'as_of_date' => $asOfDate,
            'rows' => $assets['rows']->union($liabilities['rows'])->union($equity['rows']),
            'totals' => [
                'assets' => $assets['total'],
                'liabilities' => $liabilities['total'],
                'equity' => $equity['total'],
                'unclosed_earnings' => (string) $earnings->toScale(2),
                'equity_including_earnings' => (string) $totalEquity->toScale(2),
                'liabilities_and_equity' => (string) $liabilitiesAndEquity->toScale(2),
            ],
            'difference' => (string) $difference->toScale(2),
            'balanced' => $report['valid'] && $difference->isZero(),
        ];
    }

    /**
     * Klasifikasi berasal dari master akun. Saldo kontra tetap mengurangi kelompoknya.
     * Akun postable tanpa transaksi tampil nol; akun induk tidak dijumlahkan ulang.
     *
     * @param  array{valid: bool, rows: Collection}  $report
     * @return array<string, array{rows: Collection, total: string}>
     */
    private function classifiedBalances(array $report, string $balanceType): array
    {
        $groups = [];
        foreach (Account::accountTypes() as $type => $label) {
            $groups[$type] = ['rows' => collect(), 'total' => BigDecimal::of('0.00')];
        }

        if ($report['valid']) {
            foreach (Account::query()->orderBy('code')->get() as $account) {
                if (! $account->is_postable && ! $report['rows']->has($account->id)) {
                    continue;
                }

                if (! array_key_exists($account->account_type, $groups)) {
                    throw new RuntimeException('Klasifikasi akun laporan keuangan tidak valid.');
                }

                $row = $report['rows']->get($account->id);
                $balance = BigDecimal::of($row[$balanceType.'_debit'] ?? '0.00')
                    ->minus($row[$balanceType.'_credit'] ?? '0.00');

                if (in_array($account->account_type, [Account::TYPE_LIABILITY, Account::TYPE_EQUITY, Account::TYPE_REVENUE], true)) {
                    $balance = $balance->negated();
                }

                $groups[$account->account_type]['rows']->put($account->id, [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type,
                    'balance' => (string) $balance->toScale(2),
                ]);
                $groups[$account->account_type]['total'] = $groups[$account->account_type]['total']->plus($balance);
            }
        }

        foreach ($groups as &$group) {
            $group['total'] = (string) $group['total']->toScale(2);
        }

        return $groups;
    }

    /**
     * Baca jurnal tanpa mengubah data akuntansi.
     * Bentuk hasil tetap sesuai kontrak halaman Neraca Saldo.
     */
    public function trialBalance(?string $startDate, ?string $endDate): array
    {
        $valid = Validator::make(
            ['start_date' => $startDate, 'end_date' => $endDate],
            [
                'start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d'],
            ]
        )->passes()
            && $startDate <= $endDate;

        $rows = $valid
            ? $this->buildRows($startDate, $endDate)
            : collect();

        $totals = [];

        foreach (self::BALANCE_FIELDS as $field) {
            $totals[$field] = BigDecimal::of('0.00');
        }

        // Jumlahkan semua akun sebelum tabel melakukan pencarian atau pagination.
        foreach ($rows as $row) {
            foreach (self::BALANCE_FIELDS as $field) {
                $totals[$field] = $totals[$field]->plus($row[$field]);
            }
        }

        $balanced = $valid
            && $totals['opening_debit']->isEqualTo($totals['opening_credit'])
            && $totals['period_debit']->isEqualTo($totals['period_credit'])
            && $totals['closing_debit']->isEqualTo($totals['closing_credit']);

        foreach (self::BALANCE_FIELDS as $field) {
            $totals[$field] = (string) $totals[$field]->toScale(2);
        }

        return [
            'valid' => $valid,
            'balanced' => $balanced,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Kelompokkan mutasi pada tanggal transaksi; saldo awal berada sebelum
     * tanggal awal dan mutasi periode mencakup tanggal akhir.
     */
    private function aggregateJournalLines(
        string $startDate,
        string $endDate
    ): Collection {
        return DB::table('journal_lines')
            ->join(
                'journal_entries',
                'journal_entries.id',
                '=',
                'journal_lines.journal_entry_id'
            )
            ->whereIn('journal_entries.status', [
                JournalEntry::STATUS_POSTED,
                JournalEntry::STATUS_REVERSED,
            ])
            ->where('journal_entries.transaction_date', '<=', $endDate)
            ->select('journal_lines.account_id')
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date < ? '
                .'THEN journal_lines.debit ELSE 0 END) AS opening_debit',
                [$startDate]
            )
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date < ? '
                .'THEN journal_lines.credit ELSE 0 END) AS opening_credit',
                [$startDate]
            )
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date >= ? '
                .'THEN journal_lines.debit ELSE 0 END) AS period_debit',
                [$startDate]
            )
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date >= ? '
                .'THEN journal_lines.credit ELSE 0 END) AS period_credit',
                [$startDate]
            )
            ->groupBy('journal_lines.account_id')
            ->get();
    }

    /** Saldo negatif debit dikembalikan sebagai saldo kredit. */
    private function splitBalance(BigDecimal $balance): array
    {
        if ($balance->isNegative()) {
            return [
                'debit' => '0.00',
                'credit' => (string) BigDecimal::of('0.00')
                    ->minus($balance)
                    ->toScale(2),
            ];
        }

        return [
            'debit' => (string) $balance->toScale(2),
            'credit' => '0.00',
        ];
    }

    /** Sertakan akun yang pernah dijurnal walaupun kini nonaktif. */
    private function buildRows(string $startDate, string $endDate): Collection
    {
        $aggregates = $this->aggregateJournalLines($startDate, $endDate)
            ->keyBy('account_id');

        return Account::query()
            ->whereIn('id', $aggregates->keys())
            ->orderBy('code')
            ->get()
            ->mapWithKeys(function (Account $account) use ($aggregates): array {
                $amounts = $aggregates->get($account->id);

                $openingBalance = BigDecimal::of(
                    (string) $amounts->opening_debit
                )->minus((string) $amounts->opening_credit);

                $periodDebit = BigDecimal::of(
                    (string) $amounts->period_debit
                )->toScale(2);

                $periodCredit = BigDecimal::of(
                    (string) $amounts->period_credit
                )->toScale(2);

                $closingBalance = $openingBalance
                    ->plus($periodDebit)
                    ->minus($periodCredit);

                $opening = $this->splitBalance($openingBalance);
                $closing = $this->splitBalance($closingBalance);

                return [
                    $account->id => [
                        'id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'opening_debit' => $opening['debit'],
                        'opening_credit' => $opening['credit'],
                        'period_debit' => (string) $periodDebit,
                        'period_credit' => (string) $periodCredit,
                        'closing_debit' => $closing['debit'],
                        'closing_credit' => $closing['credit'],
                    ],
                ];
            });
    }
}
