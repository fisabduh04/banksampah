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

class BalanceSheet extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.balance-sheet';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Neraca';

    protected static ?string $title = 'Neraca';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 33;

    public ?string $asOfDate = null;

    protected ?array $reportCache = null;

    public function mount(): void
    {
        $this->asOfDate = now()->toDateString();
    }

    public function getReportData(): array
    {
        return $this->reportCache ??= app(FinancialReportingService::class)->balanceSheet($this->asOfDate);
    }

    public function updatedAsOfDate(): void
    {
        $this->reportCache = null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->getReportData()['rows']->all())
            ->heading('Aset, Liabilitas, dan Ekuitas')
            ->paginated(false)
            ->columns([
                TextColumn::make('account_type')->label('Kelompok')
                    ->formatStateUsing(fn (string $state): string => Account::accountTypes()[$state]),
                TextColumn::make('code')->label('Kode Akun'),
                TextColumn::make('name')->label('Nama Akun')->wrap(),
                TextColumn::make('balance')->label('Saldo')->alignEnd()
                    ->formatStateUsing(fn (string $state): View => view('filament.financial-amount', ['amount' => $state])),
            ])
            ->emptyStateHeading('Tidak ada akun untuk ditampilkan');
    }
}
