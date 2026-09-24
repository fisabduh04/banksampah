<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\JournalEntry;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use UnitEnum;

class TrialBalance extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.trial-balance';

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static ?string $title = 'Neraca Saldo';

    protected static string|UnitEnum|null $navigationGroup =
        'Keuangan';

    protected static ?int $navigationSort = 31;

    /** Tanggal awal dan akhir periode laporan. */
    public ?string $startDate = null;

    public ?string $endDate = null;

    /** Simpan hasil perhitungan selama satu siklus render halaman. */
    protected ?array $trialBalanceSnapshotCache = null;

    /** Ikuti periode awal bawaan pada halaman Buku Besar. */
    public function mount(): void
    {
        $this->startDate = now()
            ->startOfMonth()
            ->toDateString();

        $this->endDate = now()->toDateString();
    }

    /**
     * Pastikan periode siap dipakai untuk membaca jurnal.
     * Tanggal awal dan akhir termasuk dalam laporan.
     */
    private function validPeriod(): ?array
    {
        $dates = [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];

        $validator = Validator::make($dates, [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return null;
        }

        if ($this->startDate > $this->endDate) {
            return null;
        }

        return $dates;
    }

    /**
     * Jumlahkan debit dan kredit setiap akun sebelum dan selama periode.
     * Jurnal asal yang direversal tetap dihitung bersama jurnal pembaliknya.
     */
    private function aggregateJournalLines(array $period): Collection
    {
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
            ->where(
                'journal_entries.transaction_date',
                '<=',
                $period['end_date']
            )
            ->select('journal_lines.account_id')
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date < ? '
                .'THEN journal_lines.debit ELSE 0 END) AS opening_debit',
                [$period['start_date']]
            )
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date < ? '
                .'THEN journal_lines.credit ELSE 0 END) AS opening_credit',
                [$period['start_date']]
            )
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date >= ? '
                .'THEN journal_lines.debit ELSE 0 END) AS period_debit',
                [$period['start_date']]
            )
            ->selectRaw(
                'SUM(CASE WHEN journal_entries.transaction_date >= ? '
                .'THEN journal_lines.credit ELSE 0 END) AS period_credit',
                [$period['start_date']]
            )
            ->groupBy('journal_lines.account_id')
            ->get();
    }

    /**
     * Saldo bersih D − K ditampilkan pada sisi sebenarnya.
     * Saldo negatif berada di kolom kredit.
     */
    private function splitBalance(BigDecimal $balance): array
    {
        $zero = BigDecimal::of('0.00');

        if ($balance->isNegative()) {
            return [
                'debit' => '0.00',
                'credit' => (string) $zero
                    ->minus($balance)
                    ->toScale(2),
            ];
        }

        return [
            'debit' => (string) $balance->toScale(2),
            'credit' => '0.00',
        ];
    }

    /**
     * Susun saldo tiap akun yang memiliki jurnal sampai akhir periode.
     * Akun historis tetap masuk meskipun sekarang tidak aktif.
     */
    private function buildRows(): Collection
    {
        $period = $this->validPeriod();

        if ($period === null) {
            return collect();
        }

        $aggregates = $this->aggregateJournalLines($period)
            ->keyBy('account_id');

        return Account::query()
            ->whereIn('id', $aggregates->keys())
            ->orderBy('code')
            ->get()
            ->mapWithKeys(function (Account $account) use ($aggregates): array {
                $amounts = $aggregates->get($account->id);

                $openingBalance = BigDecimal::of(
                    (string) $amounts->opening_debit
                )->minus(
                    (string) $amounts->opening_credit
                );

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

    /**
     * Total dihitung dari semua akun sebelum tabel melakukan pencarian
     * atau pagination.
     */
    public function getTrialBalanceData(): array
    {
        if ($this->trialBalanceSnapshotCache !== null) {
            return $this->trialBalanceSnapshotCache;
        }
        $valid = $this->validPeriod() !== null;
        $rows = $valid ? $this->buildRows() : collect();

        $fields = [
            'opening_debit',
            'opening_credit',
            'period_debit',
            'period_credit',
            'closing_debit',
            'closing_credit',
        ];

        $totals = [];

        foreach ($fields as $field) {
            $totals[$field] = BigDecimal::of('0.00');
        }

        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $totals[$field] = $totals[$field]
                    ->plus($row[$field]);
            }
        }

        $balanced = $valid
            && $totals['opening_debit']
                ->isEqualTo($totals['opening_credit'])
            && $totals['period_debit']
                ->isEqualTo($totals['period_credit'])
            && $totals['closing_debit']
                ->isEqualTo($totals['closing_credit']);

        foreach ($fields as $field) {
            $totals[$field] = (string) $totals[$field]
                ->toScale(2);
        }

        return $this->trialBalanceSnapshotCache = [
            'valid' => $valid,
            'balanced' => $balanced,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    public function updatedStartDate(): void
    {
        $this->trialBalanceSnapshotCache = null;
    }

    public function updatedEndDate(): void
    {
        $this->trialBalanceSnapshotCache = null;
    }

    /**
     * Tampilkan saldo awal, mutasi, dan saldo akhir setiap akun.
     * ID akun menjadi kunci baris yang stabil.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(
                fn (): array => $this->getTrialBalanceData()['rows']->all()
            )
            ->heading('Rincian per Akun')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode Akun'),

                TextColumn::make('name')
                    ->label('Nama Akun'),

                TextColumn::make('opening_debit')
                    ->label('Saldo Awal Debit')
                    ->money('IDR', locale: 'id')
                    ->alignEnd(),

                TextColumn::make('opening_credit')
                    ->label('Saldo Awal Kredit')
                    ->money('IDR', locale: 'id')
                    ->alignEnd(),

                TextColumn::make('period_debit')
                    ->label('Mutasi Debit')
                    ->money('IDR', locale: 'id')
                    ->alignEnd(),

                TextColumn::make('period_credit')
                    ->label('Mutasi Kredit')
                    ->money('IDR', locale: 'id')
                    ->alignEnd(),

                TextColumn::make('closing_debit')
                    ->label('Saldo Akhir Debit')
                    ->money('IDR', locale: 'id')
                    ->alignEnd(),

                TextColumn::make('closing_credit')
                    ->label('Saldo Akhir Kredit')
                    ->money('IDR', locale: 'id')
                    ->alignEnd(),
            ])
            ->emptyStateHeading('Belum ada jurnal')
            ->emptyStateDescription(
                'Tidak ada jurnal sampai tanggal akhir yang dipilih.'
            );
    }
}
