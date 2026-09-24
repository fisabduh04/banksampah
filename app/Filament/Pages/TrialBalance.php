<?php

namespace App\Filament\Pages;

use App\Services\FinancialReportingService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
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

    /** Ambil laporan dari service; cache hanya selama siklus render ini. */
    public function getTrialBalanceData(): array
    {
        if ($this->trialBalanceSnapshotCache !== null) {
            return $this->trialBalanceSnapshotCache;
        }

        return $this->trialBalanceSnapshotCache =
            app(FinancialReportingService::class)->trialBalance(
                $this->startDate,
                $this->endDate
            );
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
