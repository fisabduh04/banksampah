<x-filament-panels::page>
    <div class="financial-report">
        <x-filament::section compact>
            <x-slot name="heading">
                Periode laporan
            </x-slot>

            <div class="financial-report-filters">
                <div>
                    <label for="start-date" class="mb-2 block text-sm font-medium">
                        Tanggal awal
                    </label>

                    <x-filament::input.wrapper>
                        <x-filament::input id="start-date" type="date" wire:model.live="startDate" />
                    </x-filament::input.wrapper>
                </div>

                <div>
                    <label for="end-date" class="mb-2 block text-sm font-medium">
                        Tanggal akhir
                    </label>

                    <x-filament::input.wrapper>
                        <x-filament::input id="end-date" type="date" wire:model.live="endDate" />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        @php
            $report = $this->getTrialBalanceData();
        @endphp

        <x-filament::section>
            <x-slot name="heading">
                Pemeriksaan Neraca Saldo
            </x-slot>
            @if ($report['valid'])
                <x-slot name="description">
                    {{ \Carbon\Carbon::parse($this->startDate)->translatedFormat('d M Y') }}
                    – {{ \Carbon\Carbon::parse($this->endDate)->translatedFormat('d M Y') }}
                </x-slot>
                <x-slot name="afterHeader">
                    <span role="status">
                        <x-filament::badge :color="$report['rows']->isEmpty() ? 'gray' : ($report['balanced'] ? 'success' : 'danger')"
                            :icon="$report['rows']->isEmpty() ? 'heroicon-m-information-circle' : ($report['balanced'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')">
                            {{ $report['rows']->isEmpty() ? 'Belum ada jurnal' : ($report['balanced'] ? 'Neraca Saldo seimbang.' : 'Neraca Saldo tidak seimbang. Periksa jurnal.') }}
                        </x-filament::badge>
                    </span>
                </x-slot>
            @endif

            @if (!$report['valid'])
                <p role="alert">Periode tidak valid. Periksa tanggal awal dan akhir.</p>
            @else
                <div class="financial-report-metrics financial-report-metrics-three">
                    @foreach (['opening' => 'Saldo awal', 'period' => 'Mutasi periode', 'closing' => 'Saldo akhir'] as $key => $label)
                        <div @class(['financial-report-metric-primary' => $key === 'closing'])>
                            <h3 class="font-semibold">{{ $label }}</h3>
                            <dl>
                                <dt>Debit</dt>
                                <dd>@include('filament.financial-amount', ['amount' => $report['totals'][$key.'_debit']])</dd>
                                <dt>Kredit</dt>
                                <dd>@include('filament.financial-amount', ['amount' => $report['totals'][$key.'_credit']])</dd>
                            </dl>
                        </div>
                    @endforeach
                </div>
                @if ($report['rows']->isEmpty())
                    <p class="financial-report-note">Belum ada jurnal sampai tanggal akhir yang dipilih.</p>
                @endif
                <p class="financial-report-note">Jumlah akun dengan jurnal: {{ $report['rows']->count() }}</p>
            @endif
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
