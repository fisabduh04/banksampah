<x-filament-panels::page>
    <x-filament::section heading="Tanggal laporan">
        <label for="balance-sheet-date" class="mb-2 block text-sm font-medium">Posisi keuangan sampai tanggal</label>
        <x-filament::input.wrapper>
            <x-filament::input id="balance-sheet-date" type="date" wire:model.live="asOfDate" />
        </x-filament::input.wrapper>
    </x-filament::section>

    @php($report = $this->getReportData())

    @if (!$report['valid'])
        <x-filament::section>
            <p role="alert">Tanggal laporan tidak valid.</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Posisi Keuangan">
            <p>Saldo kumulatif sampai {{ $report['as_of_date'] }}, termasuk saldo awal dan pembalik yang sudah terjadi.</p>
            <dl class="mt-4 grid gap-4 md:grid-cols-3">
                @foreach (['assets' => 'Total Aset', 'liabilities' => 'Total Liabilitas', 'equity' => 'Ekuitas Tercatat', 'unclosed_earnings' => 'Laba / Rugi Belum Ditutup', 'equity_including_earnings' => 'Total Ekuitas Termasuk Laba / Rugi', 'liabilities_and_equity' => 'Total Liabilitas + Ekuitas'] as $key => $label)
                    <div>
                        <dt class="text-sm">{{ $label }}</dt>
                        <dd class="text-xl font-semibold">
                            @include('filament.financial-amount', ['amount' => $report['totals'][$key]])
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-sm">Laba/rugi belum ditutup mencakup saldo pendapatan dikurangi beban dari seluruh periode yang belum dipindahkan ke ekuitas.</p>
            <p class="mt-4 font-semibold" role="status">
                {{ $report['balanced'] ? 'Neraca seimbang: Aset = Liabilitas + Ekuitas.' : 'Neraca tidak seimbang. Periksa jurnal.' }}
                Selisih: @include('filament.financial-amount', ['amount' => $report['difference']])
            </p>
        </x-filament::section>
        {{ $this->table }}
    @endif
</x-filament-panels::page>
