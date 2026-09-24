<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FinancialOverview;
use App\Filament\Widgets\MonthlyActivityChart;
use App\Services\DashboardReportingService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard';

    protected array $extraBodyAttributes = ['class' => 'bank-sampah-dashboard'];

    protected ?array $snapshot = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Perbarui')->icon('heroicon-o-arrow-path')
                ->color('gray')->url(static::getUrl()),
            ActionGroup::make([
                Action::make('cashFlow')->label('Arus Kas')->icon('heroicon-o-arrows-right-left')
                    ->url(CashFlow::getUrl()),
                Action::make('trialBalance')->label('Neraca Saldo')->icon('heroicon-o-book-open')
                    ->url(TrialBalance::getUrl()),
                Action::make('incomeStatement')->label('Laba Rugi')->icon('heroicon-o-chart-bar')
                    ->url(IncomeStatement::getUrl()),
                Action::make('balanceSheet')->label('Neraca')->icon('heroicon-o-scale')
                    ->url(BalanceSheet::getUrl()),
            ])->label('Laporan')->icon('heroicon-o-document-chart-bar')->button()->color('gray'),
        ];
    }

    public function getWidgets(): array
    {
        return [
            FinancialOverview::class,
            MonthlyActivityChart::make(['category' => 'savings']),
            MonthlyActivityChart::make(['category' => 'sales']),
        ];
    }

    public function getWidgetData(): array
    {
        return ['snapshot' => $this->snapshot ??= app(DashboardReportingService::class)->snapshot(now()->toDateString())];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, '@4xl' => 2];
    }

    public function getWidgetsContentComponent(): Component
    {
        return parent::getWidgetsContentComponent()
            ->gridContainer()
            ->extraAttributes(['class' => 'bank-sampah-dashboard-widgets']);
    }
}
