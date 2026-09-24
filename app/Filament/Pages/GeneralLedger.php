<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

class GeneralLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.general-ledger';

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static ?string $title = 'Buku Besar';

    protected static string|UnitEnum|null $navigationGroup =
        'Keuangan';

    protected static ?int $navigationSort = 30;

    /**
     * Filter akun Buku Besar.
     */
    public int|string|null $accountId = null;

    /**
     * Filter periode.
     */
    public ?string $startDate = null;

    public ?string $endDate = null;

    /**
     * Cache hanya untuk satu siklus render Livewire.
     *
     * Tujuannya agar summary card dan Filament Table
     * memakai hasil perhitungan yang sama tanpa
     * menjalankan query Buku Besar berulang-ulang.
     */
    protected ?array $ledgerSnapshotCache = null;

    /**
     * Nilai awal halaman.
     */
    public function mount(): void
    {
        $this->startDate = now()
            ->startOfMonth()
            ->toDateString();

        $this->endDate = now()->toDateString();

        /**
         * Pilih akun aktif dan postable pertama.
         */
        $this->accountId = Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->value('id');

        /**
         * Jika seluruh akun nonaktif,
         * akun historis tetap boleh dilihat.
         */
        if ($this->accountId === null) {
            $this->accountId = $this->selectableAccounts()
                ->orderBy('code')
                ->value('id');
        }
    }

    /**
     * Jika akun berubah, hapus cache perhitungan
     * dan reset pagination tabel.
     */
    public function updatedAccountId(): void
    {
        $this->ledgerSnapshotCache = null;
        $this->resetTable();
    }

    public function updatedStartDate(): void
    {
        $this->ledgerSnapshotCache = null;
        $this->resetTable();
    }

    public function updatedEndDate(): void
    {
        $this->ledgerSnapshotCache = null;
        $this->resetTable();
    }

    /**
     * Daftar akun untuk filter.
     *
     * Akun nonaktif tetap ditampilkan
     * karena histori akuntansinya tetap sah.
     */
    public function getAccounts(): Collection
    {
        return $this->selectableAccounts()
            ->orderBy('code')
            ->get();
    }

    /**
     * Akun yang dapat dipilih: akun postable atau akun historis
     * yang memiliki baris jurnal, termasuk jika kini non-postable.
     */
    private function selectableAccounts(): Builder
    {
        return Account::query()
            ->where(function (Builder $query): void {
                $query->where('is_postable', true)
                    ->orWhereIn(
                        'id',
                        JournalLine::query()->select('account_id')
                    );
            });
    }

    /**
     * Konfigurasi native Filament Table.
     */
    public function table(Table $table): Table
    {
        return $table
            /**
             * Buku Besar menghasilkan custom data karena
             * saldo berjalan dihitung terlebih dahulu.
             *
             * Filament tetap menangani render tabel,
             * search box, pagination, badge, responsive,
             * dan empty state.
             */
            ->records(
                function (
                    ?string $search,
                    int $page,
                    int $recordsPerPage
                ): LengthAwarePaginator {
                    $rows = $this->getLedgerData()['rows'];

                    /**
                     * Search dilakukan setelah saldo berjalan
                     * dihitung agar pencarian tidak mengubah
                     * nilai saldo akuntansi.
                     */
                    if (filled($search)) {
                        $needle = Str::lower(
                            trim((string) $search)
                        );

                        $rows = $rows->filter(
                            function (array $row) use (
                                $needle
                            ): bool {
                                $haystack = Str::lower(
                                    implode(' ', [
                                        $row['date'],
                                        $row['entry_number'],
                                        $row['reference'],
                                        $row['description'],
                                        $row['status'],
                                    ])
                                );

                                return str_contains(
                                    $haystack,
                                    $needle
                                );
                            }
                        );
                    }

                    $total = $rows->count();

                    $pageItems = $rows
                        ->forPage(
                            $page,
                            $recordsPerPage
                        );

                    return new LengthAwarePaginator(
                        items: $pageItems,
                        total: $total,
                        perPage: $recordsPerPage,
                        currentPage: $page
                    );
                }
            )

            ->heading('Detail Transaksi')

            ->description(
                'Riwayat debit, kredit, dan saldo berjalan akun yang dipilih.'
            )

            ->columns([
                TextColumn::make('date')
                    ->label('Tanggal')
                    ->icon(
                        Heroicon::OutlinedCalendarDays
                    )
                    ->color('gray'),

                TextColumn::make('entry_number')
                    ->label('No. Jurnal')
                    ->weight(
                        FontWeight::SemiBold
                    ),

                TextColumn::make('reference')
                    ->label('Referensi')
                    ->limit(28)
                    ->tooltip(
                        fn (array $record): string => $record['reference']
                    )
                    ->color('gray'),

                TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(42)
                    ->tooltip(
                        fn (array $record): string => $record['description']
                    )
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(
                        fn (string $state): string => match ($state) {
                            'Posted' => 'success',
                            'Reversal' => 'warning',
                            'Direversal' => 'danger',
                            default => 'gray',
                        }
                    ),

                TextColumn::make('debit')
                    ->label('Debit')
                    ->money(
                        'IDR',
                        locale: 'id'
                    )
                    ->alignEnd(),

                TextColumn::make('credit')
                    ->label('Kredit')
                    ->money(
                        'IDR',
                        locale: 'id'
                    )
                    ->alignEnd(),

                TextColumn::make('balance')
                    ->label('Saldo')
                    ->money(
                        'IDR',
                        locale: 'id'
                    )
                    ->alignEnd()
                    ->weight(
                        FontWeight::Bold
                    )
                    ->color('primary'),
            ])

            /**
             * Search global native Filament.
             */
            ->searchable()

            /**
             * Pagination native Filament.
             */
            ->paginated([
                10,
                25,
                50,
                100,
            ])

            ->defaultPaginationPageOption(25)

            ->extremePaginationLinks()

            /**
             * Sedikit pembeda baris untuk membantu
             * pembacaan laporan keuangan.
             */
            ->striped()

            ->emptyStateIcon(
                Heroicon::OutlinedBookOpen
            )

            ->emptyStateHeading(
                'Belum ada transaksi'
            )

            ->emptyStateDescription(
                'Tidak ditemukan transaksi jurnal pada akun dan periode yang dipilih.'
            )

            /**
             * Hindari bentrok query string apabila
             * nanti halaman mempunyai tabel lain.
             */
            ->queryStringIdentifier(
                'general-ledger'
            );
    }

    /**
     * Menghasilkan snapshot Buku Besar.
     *
     * Snapshot dipakai oleh:
     * - summary card
     * - Filament Table
     */
    public function getLedgerData(): array
    {
        if ($this->ledgerSnapshotCache !== null) {
            return $this->ledgerSnapshotCache;
        }

        return $this->ledgerSnapshotCache =
            $this->buildLedgerData();
    }

    /**
     * Perhitungan inti Buku Besar.
     */
    private function buildLedgerData(): array
    {
        $empty = [
            'valid' => false,
            'account' => null,
            'rows' => collect(),
            'opening_balance' => '0.00',
            'total_debit' => '0.00',
            'total_credit' => '0.00',
            'closing_balance' => '0.00',
            'transaction_count' => 0,
        ];

        if ($this->accountId === null) {
            return $empty;
        }

        $account = $this->selectableAccounts()
            ->whereKey($this->accountId)
            ->first();

        if ($account === null) {
            return $empty;
        }

        /**
         * Validasi periode.
         */
        $validator = Validator::make(
            [
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
            ],
            [
                'start_date' => [
                    'required',
                    'date_format:Y-m-d',
                ],
                'end_date' => [
                    'required',
                    'date_format:Y-m-d',
                ],
            ]
        );

        if ($validator->fails()) {
            return [
                ...$empty,
                'account' => $account,
            ];
        }

        if ($this->startDate > $this->endDate) {
            return [
                ...$empty,
                'account' => $account,
            ];
        }

        /**
         * Jurnal reversed tetap dimasukkan.
         *
         * Alasannya:
         * jurnal asal tetap merupakan histori,
         * sedangkan jurnal reversal membalik efeknya.
         */
        $validStatuses = [
            JournalEntry::STATUS_POSTED,
            JournalEntry::STATUS_REVERSED,
        ];

        /**
         * =====================================================
         * SALDO AWAL
         * =====================================================
         *
         * Seluruh transaksi sebelum tanggal awal.
         */
        $openingTotals = JournalLine::query()
            ->join(
                'journal_entries',
                'journal_entries.id',
                '=',
                'journal_lines.journal_entry_id'
            )
            ->where(
                'journal_lines.account_id',
                $account->id
            )
            ->whereIn(
                'journal_entries.status',
                $validStatuses
            )
            ->whereDate(
                'journal_entries.transaction_date',
                '<',
                $this->startDate
            )
            ->selectRaw(
                '
                    COALESCE(
                        SUM(journal_lines.debit),
                        0
                    ) AS total_debit,
                    COALESCE(
                        SUM(journal_lines.credit),
                        0
                    ) AS total_credit
                '
            )
            ->first();

        $openingDebit = BigDecimal::of(
            (string) (
                $openingTotals->total_debit
                ?? '0.00'
            )
        );

        $openingCredit = BigDecimal::of(
            (string) (
                $openingTotals->total_credit
                ?? '0.00'
            )
        );

        $openingBalance =
            $this->calculateBalance(
                account: $account,
                debit: $openingDebit,
                credit: $openingCredit
            );

        /**
         * =====================================================
         * DETAIL TRANSAKSI PERIODE
         * =====================================================
         */
        $lines = JournalLine::query()
            ->select('journal_lines.*')
            ->join(
                'journal_entries',
                'journal_entries.id',
                '=',
                'journal_lines.journal_entry_id'
            )
            ->with('journalEntry')
            ->where(
                'journal_lines.account_id',
                $account->id
            )
            ->whereIn(
                'journal_entries.status',
                $validStatuses
            )
            ->whereBetween(
                'journal_entries.transaction_date',
                [
                    $this->startDate,
                    $this->endDate,
                ]
            )
            ->orderBy(
                'journal_entries.transaction_date'
            )
            ->orderBy(
                'journal_entries.id'
            )
            ->orderBy(
                'journal_lines.line_number'
            )
            ->get();

        $runningBalance =
            $openingBalance;

        $periodDebit =
            BigDecimal::of('0.00');

        $periodCredit =
            BigDecimal::of('0.00');

        /**
         * Gunakan line ID sebagai key stabil
         * untuk record Filament Table.
         */
        $rows = collect();

        foreach ($lines as $line) {
            $entry = $line->journalEntry;

            if ($entry === null) {
                throw new RuntimeException(
                    'Jurnal detail tidak memiliki header.'
                );
            }

            $debit = BigDecimal::of(
                (string) $line->debit
            )->toScale(2);

            $credit = BigDecimal::of(
                (string) $line->credit
            )->toScale(2);

            $periodDebit =
                $periodDebit->plus($debit);

            $periodCredit =
                $periodCredit->plus($credit);

            /**
             * Gerakan saldo mengikuti normal balance.
             */
            $movement =
                $this->calculateBalance(
                    account: $account,
                    debit: $debit,
                    credit: $credit
                );

            $runningBalance =
                $runningBalance->plus(
                    $movement
                );

            $status = match (true) {
                $entry->reversal_of_id !== null => 'Reversal',

                $entry->status
                    === JournalEntry::STATUS_REVERSED => 'Direversal',

                default => 'Posted',
            };

            $rows->put(
                $line->id,
                [
                    'id' => $line->id,

                    'date' => $entry
                        ->transaction_date
                        ->format('d/m/Y'),

                    'entry_number' => $entry->entry_number,

                    'reference' => $entry->reference_number
                        ?: $entry->reference_type
                            .' #'
                            .$entry->reference_id,

                    'description' => $line->description
                        ?: $entry->description
                        ?: '-',

                    'status' => $status,

                    'debit' => (string) $debit,

                    'credit' => (string) $credit,

                    'balance' => (string) $runningBalance
                        ->toScale(2),
                ]
            );
        }

        return [
            'valid' => true,

            'account' => $account,

            'rows' => $rows,

            'opening_balance' => (string) $openingBalance
                ->toScale(2),

            'total_debit' => (string) $periodDebit
                ->toScale(2),

            'total_credit' => (string) $periodCredit
                ->toScale(2),

            'closing_balance' => (string) $runningBalance
                ->toScale(2),

            'transaction_count' => $rows->count(),
        ];
    }

    /**
     * Saldo berdasarkan normal balance akun.
     *
     * Normal debit:
     * Debit - Kredit
     *
     * Normal kredit:
     * Kredit - Debit
     */
    private function calculateBalance(
        Account $account,
        BigDecimal $debit,
        BigDecimal $credit
    ): BigDecimal {
        if (
            $account->normal_balance
            === Account::NORMAL_DEBIT
        ) {
            return $debit->minus(
                $credit
            );
        }

        if (
            $account->normal_balance
            === Account::NORMAL_CREDIT
        ) {
            return $credit->minus(
                $debit
            );
        }

        throw new RuntimeException(
            'Normal balance akun tidak valid.'
        );
    }

    /**
     * Format angka untuk summary card.
     *
     * Tabel sendiri menggunakan money()
     * bawaan Filament.
     */
    public function formatAmount(
        string|int|float|null $amount
    ): string {
        return number_format(
            (float) ($amount ?? 0),
            2,
            ',',
            '.'
        );
    }

    public function normalBalanceLabel(
        ?Account $account
    ): string {
        if ($account === null) {
            return '-';
        }

        return match (
            $account->normal_balance
        ) {
            Account::NORMAL_DEBIT => 'Debit',

            Account::NORMAL_CREDIT => 'Kredit',

            default => '-',
        };
    }
}
