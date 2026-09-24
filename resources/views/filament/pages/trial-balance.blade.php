<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Periode laporan
        </x-slot>

        <div class="grid gap-4 md:grid-cols-2">
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

        @if (!$report['valid'])
            <p>Periode tidak valid. Periksa tanggal awal dan akhir.</p>
        @else
            <p>Jumlah akun dengan jurnal: {{ $report['rows']->count() }}</p>

            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <p>Saldo awal</p>
                    <p>Debit: {{ $report['totals']['opening_debit'] }}</p>
                    <p>Kredit: {{ $report['totals']['opening_credit'] }}</p>
                </div>

                <div>
                    <p>Mutasi periode</p>
                    <p>Debit: {{ $report['totals']['period_debit'] }}</p>
                    <p>Kredit: {{ $report['totals']['period_credit'] }}</p>
                </div>

                <div>
                    <p>Saldo akhir</p>
                    <p>Debit: {{ $report['totals']['closing_debit'] }}</p>
                    <p>Kredit: {{ $report['totals']['closing_credit'] }}</p>
                </div>
            </div>

            <p class="mt-4">
                @if ($report['rows']->isEmpty())
                    Belum ada jurnal sampai tanggal akhir yang dipilih.
                @elseif ($report['balanced'])
                    Neraca Saldo seimbang.
                @else
                    Neraca Saldo tidak seimbang. Periksa jurnal.
                @endif
            </p>
        @endif
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
