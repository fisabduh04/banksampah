<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Services\FinancialReportingService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use UnitEnum;

class IncomeStatement extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.income-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laba Rugi';

    protected static ?string $title = 'Laba Rugi';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 32;

    public ?string $startDate = null;

    public ?string $endDate = null;

    protected ?array $reportCache = null;

    public function mount(): void
    {
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = now()->toDateString();
    }

    public function getReportData(): array
    {
        return $this->reportCache ??= app(FinancialReportingService::class)
            ->incomeStatement($this->startDate, $this->endDate);
    }

    public function updatedStartDate(): void
    {
        $this->reportCache = null;
    }

    public function updatedEndDate(): void
    {
        $this->reportCache = null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->getReportData()['rows']->all())
            ->heading('Pendapatan dan Beban')
            ->paginated(false)
            ->columns([
                TextColumn::make('account_type')->label('Kelompok')
                    ->formatStateUsing(fn (string $state): string => Account::accountTypes()[$state]),
                TextColumn::make('code')->label('Kode Akun'),
                TextColumn::make('name')->label('Nama Akun')->wrap(),
                TextColumn::make('balance')->label('Nilai Periode')->alignEnd()
                    ->formatStateUsing(fn (string $state): View => view('filament.financial-amount', ['amount' => $state])),
            ])
            ->emptyStateHeading('Tidak ada akun untuk ditampilkan');
    }
}
