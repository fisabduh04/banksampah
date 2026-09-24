<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

class MonthlyActivityChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'Belum ada aktivitas pada periode ini';

    protected ?string $emptyStateDescription = null;

    #[Locked]
    public array $snapshot = [];

    #[Locked]
    public string $category = 'savings';

    public function getHeading(): string
    {
        return $this->category === 'savings' ? 'Setoran dan Penarikan' : 'Penjualan dan Pembayaran Pengepul';
    }

    public function getDescription(): string
    {
        return $this->category === 'savings'
            ? 'Enam bulan (Rp neto), termasuk pembatalan sesuai bulannya; setoran bukan pendapatan.'
            : 'Penjualan berjurnal dan pembayaran kas enam bulan (Rp neto), termasuk pembatalan.';
    }

    public function getEmptyState(): View
    {
        return view('filament.widgets.dashboard-chart-empty', [
            'heading' => $this->category === 'sales'
                ? 'Belum ada penjualan atau pembayaran.'
                : $this->emptyStateHeading,
        ]);
    }

    protected function getData(): array
    {
        $definitions = $this->category === 'savings'
            ? ['deposits' => ['Setoran non-tunai (Rp)', '#0d9488'], 'withdrawals' => ['Penarikan tabungan (Rp)', '#d97706']]
            : ['sales' => ['Nilai penjualan (Rp)', '#2563eb'], 'payments' => ['Pembayaran diterima (Rp)', '#9333ea']];
        $datasets = [];
        foreach ($definitions as $key => [$label, $color]) {
            $datasets[] = [
                'label' => $label, 'data' => $this->snapshot['series'][$key] ?? [],
                'borderColor' => $color, 'backgroundColor' => $color,
                'borderWidth' => 2, 'pointRadius' => 3, 'tension' => 0,
                'borderDash' => count($datasets) === 0 ? [] : [6, 4],
                'pointStyle' => count($datasets) === 0 ? 'circle' : 'triangle',
            ];
        }

        return ['labels' => $this->snapshot['labels'] ?? [], 'datasets' => $datasets];
    }

    public function isEmpty(): bool
    {
        $keys = $this->category === 'savings' ? ['deposits', 'withdrawals'] : ['sales', 'payments'];

        return ! ($this->snapshot['activity'][$keys[0]] ?? false) && ! ($this->snapshot['activity'][$keys[1]] ?? false);
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Rupiah (Rp), neto' },
                    ticks: {
                        maxTicksLimit: 5,
                        callback: (value) => new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(value)
                    }
                }
            },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16 } },
                tooltip: { callbacks: { label: (context) => {
                    const [whole, fraction = '00'] = String(context.raw).split('.');
                    return context.dataset.label + ': Rp ' + whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + fraction;
                } } }
            }
        }
        JS);
    }

    protected function getType(): string
    {
        return 'line';
    }
}
