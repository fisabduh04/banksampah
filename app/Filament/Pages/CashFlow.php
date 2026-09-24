<?php

namespace App\Filament\Pages;

use App\Services\CashFlowReportingService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use UnitEnum;

class CashFlow extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.cash-flow';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Arus Kas';

    protected static ?string $title = 'Arus Kas dan Ringkasan Operasional';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 34;

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
        return $this->reportCache ??= app(CashFlowReportingService::class)
            ->cashFlow($this->startDate, $this->endDate);
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
            ->heading('Penerimaan dan Pengeluaran Eksternal menurut Sumber')
            ->paginated(false)
            ->columns([
                TextColumn::make('label')->label('Sumber')->wrap(),
                TextColumn::make('receipts')->label('Kas Masuk')->alignEnd()
                    ->formatStateUsing(fn (string $state): View => view('filament.financial-amount', ['amount' => $state])),
                TextColumn::make('payments')->label('Kas Keluar')->alignEnd()
                    ->formatStateUsing(fn (string $state): View => view('filament.financial-amount', ['amount' => $state])),
            ])
            ->emptyStateHeading('Tidak ada arus kas eksternal dalam periode ini');
    }
}
