<?php

namespace App\Filament\Widgets;

use Carbon\CarbonImmutable;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Locked;

class FinancialOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|array|null $columns = [
        'default' => 1,
        '@lg' => 2,
        '@4xl' => 4,
    ];

    #[Locked]
    public array $snapshot = [];

    public function getSectionContentComponent(): Component
    {
        $isBalanced = $this->snapshot['balanced'] ?? false;
        $warning = null;

        if (! ($this->snapshot['trial_balance_balanced'] ?? false)) {
            $warning = 'Neraca Saldo tidak seimbang. Periksa jurnal melalui menu Laporan > Neraca Saldo.';
        } elseif (! $isBalanced) {
            $warning = 'Ada saldo yang berbeda dengan Buku Besar. Periksa nominal selisih pada kartu.';
        }

        return parent::getSectionContentComponent()
            ->extraAttributes(['class' => 'bank-sampah-reconciliation'])
            ->afterHeader([
                Text::make($isBalanced ? 'Rekonsiliasi seimbang' : 'Rekonsiliasi perlu diperiksa')
                    ->badge()
                    ->size(Size::Small)
                    ->icon($isBalanced ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                    ->color($isBalanced ? 'success' : 'danger'),
            ])
            ->footer([
                ...($warning === null ? [] : [
                    Text::make($warning)->size(TextSize::Small)->color('danger'),
                ]),
                Text::make('Tabungan adalah kewajiban; persediaan dinilai pada biaya.')
                    ->size(TextSize::ExtraSmall)
                    ->color('gray'),
            ]);
    }

    protected function getHeading(): ?string
    {
        return 'Ringkasan saldo';
    }

    protected function getDescription(): ?string
    {
        $asOfDate = $this->snapshot['as_of_date'] ?? null;

        return $asOfDate === null
            ? null : 'Per '.CarbonImmutable::parse($asOfDate)->locale('id')->translatedFormat('j M Y');
    }

    protected function getStats(): array
    {
        $stats = [];
        foreach ($this->snapshot['cards'] ?? [] as $key => $card) {
            $balance = view('filament.financial-amount', ['amount' => $card['balance']]);

            $stats[] = Stat::make($card['label'], $balance)
                ->extraAttributes([
                    'class' => 'bank-sampah-balance-stat'.($card['balanced'] ? ' bank-sampah-balance-stat-balanced' : ''),
                ])
                ->description($card['balanced'] ? 'Sesuai GL' : view('filament.dashboard-difference', ['amount' => $card['difference']]))
                ->descriptionIcon($card['balanced'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($card['balanced'] ? 'success' : 'danger')
                ->icon(match ($key) {
                    'cash' => 'heroicon-o-banknotes', 'bank' => 'heroicon-o-building-library',
                    'savings' => 'heroicon-o-users', default => 'heroicon-o-cube',
                });
        }

        return $stats;
    }
}
