<x-filament-panels::page>
    <div class="financial-report">
        <x-filament::section compact heading="Periode laporan">
            <div class="financial-report-filters">
                <div>
                    <label for="income-start-date" class="mb-2 block text-sm font-medium">Tanggal awal</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="income-start-date" type="date" wire:model.live="startDate" />
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="income-end-date" class="mb-2 block text-sm font-medium">Tanggal akhir</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="income-end-date" type="date" wire:model.live="endDate" />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        @php($report = $this->getReportData())

        @if (!$report['valid'])
            <x-filament::section>
                <p role="alert">Periode tidak valid. Periksa tanggal awal dan akhir.</p>
            </x-filament::section>
        @else
            @if (!$report['balanced'])
                <x-filament::section compact heading="Peringatan: Neraca Saldo tidak seimbang" class="financial-report-warning">
                    <p role="alert">Neraca Saldo untuk periode ini tidak seimbang. Angka Laba Rugi tetap ditampilkan apa adanya untuk pemeriksaan. Periksa jurnal sebelum menggunakan laporan ini.</p>
                </x-filament::section>
            @endif
            <x-filament::section heading="Ringkasan Laba Rugi">
                <x-slot name="description">
                    {{ \Carbon\Carbon::parse($report['start_date'])->translatedFormat('d M Y') }}
                    – {{ \Carbon\Carbon::parse($report['end_date'])->translatedFormat('d M Y') }}
                </x-slot>
                <x-slot name="afterHeader">
                    <span role="status">
                        <x-filament::badge :color="$report['balanced'] ? 'success' : 'danger'"
                            :icon="$report['balanced'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'">
                            {{ $report['balanced'] ? 'Neraca Saldo seimbang' : 'Periksa Neraca Saldo' }}
                        </x-filament::badge>
                    </span>
                </x-slot>
                <dl class="financial-report-metrics financial-report-metrics-three">
                    @foreach (['revenue' => 'Total Pendapatan', 'expense' => 'Total Beban', 'net_profit' => 'Laba / Rugi Bersih'] as $key => $label)
                        <div @class(['financial-report-metric-primary' => $key === 'net_profit'])>
                            <dt class="text-sm">{{ $label }}</dt>
                            <dd class="text-xl font-semibold">
                                @include('filament.financial-amount', ['amount' => $report['totals'][$key]])
                            </dd>
                        </div>
                    @endforeach
                </dl>
                <p class="financial-report-note">Nilai mengikuti tanggal transaksi jurnal.</p>
            </x-filament::section>
            {{ $this->table }}
        @endif
    </div>
</x-filament-panels::page>
