<x-filament-panels::page>
    <x-filament::section heading="Periode laporan">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="cash-flow-start" class="mb-2 block text-sm font-medium">Tanggal awal</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="cash-flow-start" type="date" wire:model.live="startDate" />
                </x-filament::input.wrapper>
            </div>
            <div>
                <label for="cash-flow-end" class="mb-2 block text-sm font-medium">Tanggal akhir</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="cash-flow-end" type="date" wire:model.live="endDate" />
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
            <x-filament::section heading="Peringatan: laporan perlu diperiksa">
                <p role="alert">Saldo Kas/Bank berbeda dari GL, Neraca Saldo tidak seimbang, atau transfer periode belum neto nol. Angka tetap ditampilkan apa adanya.</p>
            </x-filament::section>
        @endif
        <x-filament::section heading="Arus Kas Gabungan Kas dan Bank">
            <p>{{ $report['start_date'] }} sampai {{ $report['end_date'] }}. Mengikuti tanggal mutasi kas, termasuk rekening nonaktif yang memiliki riwayat. Setoran sampah non-tunai tidak dihitung sebagai kas masuk.</p>
            <dl class="mt-4 grid gap-4 md:grid-cols-3">
                @foreach (['opening' => 'Saldo Awal', 'receipts' => 'Penerimaan Eksternal', 'payments' => 'Pengeluaran Eksternal', 'transfer_net' => 'Neto Transfer Internal', 'opening_entries_net' => 'Pencatatan Saldo Awal dalam Periode', 'net_change' => 'Perubahan Kas', 'closing' => 'Saldo Akhir'] as $key => $label)
                    <div>
                        <dt class="text-sm">{{ $label }}</dt>
                        <dd class="text-xl font-semibold">@include('filament.financial-amount', ['amount' => $report['totals'][$key]])</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4">Saldo awal + penerimaan eksternal − pengeluaran eksternal + neto transfer internal + pencatatan saldo awal dalam periode = saldo akhir.</p>
        </x-filament::section>

        {{ $this->table }}

        <x-filament::section heading="Transfer Antar Rekening">
            <p>Transfer internal tidak menambah total arus eksternal. Pembayaran pengepul melalui transfer bank tetap merupakan penerimaan eksternal. Neto nol belum membuktikan kelengkapan pasangan transfer; pencocokan pasangan belum tersedia.</p>
            <dl class="mt-4 grid gap-4 md:grid-cols-2">
                @foreach (['transfer_in' => 'Sisi Masuk', 'transfer_out' => 'Sisi Keluar'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd>@include('filament.financial-amount', ['amount' => $report['totals'][$key]])</dd></div>
                @endforeach
            </dl>
            @if (!$report['transfer_balanced'])
                <p class="mt-4" role="alert">Neto transfer tidak nol. Periksa pasangan yang belum tercatat atau berada di luar periode; selisihnya tetap masuk perubahan saldo.</p>
            @endif
        </x-filament::section>

        @foreach (['cash' => 'Kas', 'bank' => 'Bank'] as $type => $label)
            <x-filament::section :heading="'Pemeriksaan Saldo '.$label">
                <p>Jumlah seluruh rekening berjenis {{ $label }}, dibandingkan dengan satu akun kontrol GL.</p>
                <dl class="mt-4 grid gap-4 md:grid-cols-3">
                    @foreach (['opening' => 'Saldo Awal Mutasi', 'gl_opening' => 'Saldo Awal GL', 'opening_difference' => 'Selisih Awal', 'closing' => 'Saldo Akhir Mutasi', 'gl_closing' => 'Saldo Akhir GL', 'difference' => 'Selisih Akhir'] as $key => $fieldLabel)
                        <div><dt>{{ $fieldLabel }}</dt><dd>@include('filament.financial-amount', ['amount' => $report['groups'][$type][$key]])</dd></div>
                    @endforeach
                </dl>
            </x-filament::section>
        @endforeach

        <x-filament::section heading="Ringkasan Operasional Persediaan">
            <p>Kuantitas dan nilai biaya dari mutasi persediaan pada periode yang sama. Nilai biaya bukan omzet atau penerimaan kas. Pembatalan tampil pada tanggal mutasi pembalik; koreksi ditampilkan terpisah.</p>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                @forelse ($report['operations'] as $operation)
                    <div>
                        <p class="font-semibold">{{ $operation['label'] }}</p>
                        <dl>
                            <dt>Berat Masuk / Keluar (kg)</dt>
                            <dd>{{ str_replace('.', ',', $operation['quantity_in']) }} / {{ str_replace('.', ',', $operation['quantity_out']) }}</dd>
                            <dt>Nilai Biaya Masuk</dt>
                            <dd>@include('filament.financial-amount', ['amount' => $operation['value_in']])</dd>
                            <dt>Nilai Biaya Keluar</dt>
                            <dd>@include('filament.financial-amount', ['amount' => $operation['value_out']])</dd>
                        </dl>
                    </div>
                @empty
                    <p>Tidak ada mutasi persediaan dalam periode ini.</p>
                @endforelse
            </div>
        </x-filament::section>
        <x-filament::section heading="Dasar Klasifikasi">
            <p>Laporan dikelompokkan menurut sumber transaksi, belum menurut aktivitas operasi, investasi, dan pendanaan. Penerimaan/pengeluaran manual memerlukan penetapan klasifikasi bisnis tersendiri. Pencatatan saldo awal dalam periode dipisahkan dari arus eksternal dan tetap memengaruhi perubahan saldo tercatat.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
