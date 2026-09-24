<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
