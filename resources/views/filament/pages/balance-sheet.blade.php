<x-filament-panels::page>
    <div class="financial-report">
        <x-filament::section compact heading="Tanggal laporan">
            <div class="financial-report-filters">
                <div>
                    <label for="balance-sheet-date" class="mb-2 block text-sm font-medium">Posisi keuangan sampai tanggal</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="balance-sheet-date" type="date" wire:model.live="asOfDate" />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        @php($report = $this->getReportData())

        @if (!$report['valid'])
            <x-filament::section>
                <p role="alert">Tanggal laporan tidak valid.</p>
            </x-filament::section>
        @else
            <x-filament::section heading="Posisi Keuangan">
                <x-slot name="description">
                    Per {{ \Carbon\Carbon::parse($report['as_of_date'])->translatedFormat('d M Y') }}
                </x-slot>
                <x-slot name="afterHeader">
                    <span role="status">
                        <x-filament::badge :color="$report['balanced'] ? 'success' : 'danger'"
                            :icon="$report['balanced'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'">
                            {{ $report['balanced'] ? 'Neraca seimbang' : 'Neraca tidak seimbang' }}
                        </x-filament::badge>
                    </span>
                </x-slot>
                <dl class="financial-report-metrics financial-report-metrics-three">
                    @foreach (['assets' => 'Total Aset', 'liabilities' => 'Total Liabilitas', 'equity' => 'Ekuitas Tercatat', 'unclosed_earnings' => 'Laba / Rugi Belum Ditutup', 'equity_including_earnings' => 'Total Ekuitas Termasuk Laba / Rugi', 'liabilities_and_equity' => 'Total Liabilitas + Ekuitas'] as $key => $label)
                        <div @class(['financial-report-metric-primary' => in_array($key, ['assets', 'liabilities_and_equity'], true)])>
                            <dt class="text-sm">{{ $label }}</dt>
                            <dd class="text-xl font-semibold">
                                @include('filament.financial-amount', ['amount' => $report['totals'][$key]])
                            </dd>
                        </div>
                    @endforeach
                </dl>
                <div @class(['financial-report-comparison', 'financial-report-comparison-danger' => !$report['balanced']]) role="status">
                    <span>{{ $report['balanced'] ? 'Aset = Liabilitas + Ekuitas' : 'Periksa jurnal: Aset tidak sama dengan Liabilitas + Ekuitas.' }}</span>
                    <span>Selisih: @include('filament.financial-amount', ['amount' => $report['difference']])</span>
                </div>
                <p class="financial-report-note">Saldo kumulatif, termasuk saldo awal dan pembalik yang sudah terjadi.</p>
                <p class="financial-report-note">Laba/rugi belum ditutup mencakup saldo pendapatan dikurangi beban dari seluruh periode yang belum dipindahkan ke ekuitas.</p>
            </x-filament::section>
            {{ $this->table }}
        @endif
    </div>
</x-filament-panels::page>
