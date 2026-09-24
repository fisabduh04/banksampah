<x-filament-panels::page>
    <div class="financial-report">
        <x-filament::section compact heading="Periode laporan">
            <div class="financial-report-filters">
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
                <x-filament::section compact heading="Peringatan: laporan perlu diperiksa" class="financial-report-warning">
                    <p role="alert">Saldo Kas/Bank berbeda dari GL, Neraca Saldo tidak seimbang, atau transfer periode belum neto nol. Angka tetap ditampilkan apa adanya.</p>
                </x-filament::section>
            @endif
            <x-filament::section heading="Arus Kas Gabungan Kas dan Bank">
                <x-slot name="description">
                    {{ \Carbon\Carbon::parse($report['start_date'])->translatedFormat('d M Y') }}
                    – {{ \Carbon\Carbon::parse($report['end_date'])->translatedFormat('d M Y') }}
                </x-slot>
                <x-slot name="afterHeader">
                    <span role="status">
                        <x-filament::badge :color="$report['balanced'] ? 'success' : 'danger'"
                            :icon="$report['balanced'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'">
                            {{ $report['balanced'] ? 'Rekonsiliasi seimbang' : 'Perlu diperiksa' }}
                        </x-filament::badge>
                    </span>
                </x-slot>
                <h3 class="financial-report-group-heading">Posisi kas</h3>
                <dl class="financial-report-metrics financial-report-metrics-three">
                    @foreach (['opening' => 'Saldo Awal', 'net_change' => 'Perubahan Kas', 'closing' => 'Saldo Akhir'] as $key => $label)
                        <div @class(['financial-report-metric-primary' => $key === 'closing'])>
                            <dt class="text-sm">{{ $label }}</dt>
                            <dd class="text-xl font-semibold">@include('filament.financial-amount', ['amount' => $report['totals'][$key]])</dd>
                        </div>
                    @endforeach
                </dl>
                <h3 class="financial-report-group-heading">Arus eksternal</h3>
                <dl class="financial-report-metrics financial-report-metrics-two financial-report-details">
                    @foreach (['receipts' => 'Penerimaan Eksternal', 'payments' => 'Pengeluaran Eksternal'] as $key => $label)
                        <div><dt>{{ $label }}</dt><dd>@include('filament.financial-amount', ['amount' => $report['totals'][$key]])</dd></div>
                    @endforeach
                </dl>
                <h3 class="financial-report-group-heading">Perubahan lainnya</h3>
                <dl class="financial-report-metrics financial-report-metrics-two financial-report-details">
                    @foreach (['transfer_net' => 'Neto Transfer Internal', 'opening_entries_net' => 'Pencatatan Saldo Awal dalam Periode'] as $key => $label)
                        <div><dt>{{ $label }}</dt><dd>@include('filament.financial-amount', ['amount' => $report['totals'][$key]])</dd></div>
                    @endforeach
                </dl>
                <p class="financial-report-note">Saldo awal + penerimaan eksternal − pengeluaran eksternal + neto transfer internal + pencatatan saldo awal dalam periode = saldo akhir.</p>
                <p class="financial-report-note">Mengikuti tanggal mutasi kas, termasuk rekening nonaktif yang memiliki riwayat. Setoran sampah non-tunai tidak dihitung sebagai kas masuk.</p>
            </x-filament::section>

            {{ $this->table }}

            <x-filament::section heading="Transfer Antar Rekening">
                <dl class="financial-report-metrics financial-report-metrics-two financial-report-details">
                    @foreach (['transfer_in' => 'Sisi Masuk', 'transfer_out' => 'Sisi Keluar'] as $key => $label)
                        <div><dt>{{ $label }}</dt><dd>@include('filament.financial-amount', ['amount' => $report['totals'][$key]])</dd></div>
                    @endforeach
                </dl>
                <p class="financial-report-note">Transfer internal tidak menambah total arus eksternal. Pembayaran pengepul melalui transfer bank tetap merupakan penerimaan eksternal. Neto nol belum membuktikan kelengkapan pasangan transfer; pencocokan pasangan belum tersedia.</p>
                @if (!$report['transfer_balanced'])
                    <p class="financial-report-warning mt-4" role="alert">Neto transfer tidak nol. Periksa pasangan yang belum tercatat atau berada di luar periode; selisihnya tetap masuk perubahan saldo.</p>
                @endif
            </x-filament::section>

            @foreach (['cash' => 'Kas', 'bank' => 'Bank'] as $type => $label)
                <x-filament::section :heading="'Pemeriksaan Saldo '.$label">
                    <x-slot name="afterHeader">
                        <span role="status">
                            <x-filament::badge :color="$report['groups'][$type]['balanced'] ? 'success' : 'danger'"
                                :icon="$report['groups'][$type]['balanced'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'">
                                {{ $report['groups'][$type]['balanced'] ? 'Sesuai GL' : 'Selisih dengan GL' }}
                            </x-filament::badge>
                        </span>
                    </x-slot>
                    <dl class="financial-report-metrics financial-report-metrics-three financial-report-details">
                        @foreach (['opening' => 'Saldo Awal Mutasi', 'gl_opening' => 'Saldo Awal GL', 'opening_difference' => 'Selisih Awal', 'closing' => 'Saldo Akhir Mutasi', 'gl_closing' => 'Saldo Akhir GL', 'difference' => 'Selisih Akhir'] as $key => $fieldLabel)
                            <div><dt>{{ $fieldLabel }}</dt><dd>@include('filament.financial-amount', ['amount' => $report['groups'][$type][$key]])</dd></div>
                        @endforeach
                    </dl>
                    <p class="financial-report-note">Jumlah seluruh rekening berjenis {{ $label }}, dibandingkan dengan satu akun kontrol GL.</p>
                </x-filament::section>
            @endforeach

            <x-filament::section heading="Ringkasan Operasional Persediaan">
                <div class="financial-report-operations">
                    @forelse ($report['operations'] as $operation)
                        <div class="financial-report-operation">
                            <h3>{{ $operation['label'] }}</h3>
                            <dl>
                                <div>
                                    <dt>Berat Masuk / Keluar (kg)</dt>
                                    <dd>{{ str_replace('.', ',', $operation['quantity_in']) }} / {{ str_replace('.', ',', $operation['quantity_out']) }}</dd>
                                </div>
                                <div>
                                    <dt>Nilai Biaya Masuk</dt>
                                    <dd>@include('filament.financial-amount', ['amount' => $operation['value_in']])</dd>
                                </div>
                                <div>
                                    <dt>Nilai Biaya Keluar</dt>
                                    <dd>@include('filament.financial-amount', ['amount' => $operation['value_out']])</dd>
                                </div>
                            </dl>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada mutasi persediaan dalam periode ini.</p>
                    @endforelse
                </div>
                <p class="financial-report-note">Kuantitas dan nilai biaya dari mutasi persediaan pada periode yang sama. Nilai biaya bukan omzet atau penerimaan kas. Pembatalan tampil pada tanggal mutasi pembalik; koreksi ditampilkan terpisah.</p>
            </x-filament::section>
            <x-filament::section heading="Dasar Klasifikasi">
                <p class="financial-report-note">Laporan dikelompokkan menurut sumber transaksi, belum menurut aktivitas operasi, investasi, dan pendanaan. Penerimaan/pengeluaran manual memerlukan penetapan klasifikasi bisnis tersendiri. Pencatatan saldo awal dalam periode dipisahkan dari arus eksternal dan tetap memengaruhi perubahan saldo tercatat.</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
