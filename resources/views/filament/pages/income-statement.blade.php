<x-filament-panels::page>
    <x-filament::section heading="Periode laporan">
        <div class="grid gap-4 md:grid-cols-2">
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
            <x-filament::section heading="Peringatan: Neraca Saldo tidak seimbang">
                <p role="alert">Neraca Saldo untuk periode ini tidak seimbang. Angka Laba Rugi tetap ditampilkan apa adanya untuk pemeriksaan. Periksa jurnal sebelum menggunakan laporan ini.</p>
            </x-filament::section>
        @endif
        <x-filament::section heading="Ringkasan Laba Rugi">
            <p>{{ $report['start_date'] }} sampai {{ $report['end_date'] }}. Nilai mengikuti tanggal transaksi jurnal.</p>
            <dl class="mt-4 grid gap-4 md:grid-cols-3">
                @foreach (['revenue' => 'Total Pendapatan', 'expense' => 'Total Beban', 'net_profit' => 'Laba / Rugi Bersih'] as $key => $label)
                    <div>
                        <dt class="text-sm">{{ $label }}</dt>
                        <dd class="text-xl font-semibold">
                            @include('filament.financial-amount', ['amount' => $report['totals'][$key]])
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
        {{ $this->table }}
    @endif
</x-filament-panels::page>
