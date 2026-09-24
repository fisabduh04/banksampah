<x-filament-panels::page>
    @php
        $accounts = $this->getAccounts();
        $ledger = $this->getLedgerData();
        $selectedAccount = $ledger['account'];
    @endphp

    <div class="financial-report">
        <x-filament::section compact heading="Filter Buku Besar">
            <div class="financial-report-filters financial-report-filters-account">
                <div>
                    <label for="accountId" class="mb-2 block text-sm font-medium">Akun</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="accountId" wire:model.live="accountId">
                            @if ($accounts->isEmpty())
                                <option value="">Tidak ada akun</option>
                            @else
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->code }} — {{ $account->name }}
                                        @if (!$account->is_active)
                                            (Nonaktif)
                                        @endif
                                    </option>
                                @endforeach
                            @endif
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="startDate" class="mb-2 block text-sm font-medium">Dari Tanggal</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="startDate" type="date" wire:model.live="startDate" />
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="endDate" class="mb-2 block text-sm font-medium">Sampai Tanggal</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="endDate" type="date" wire:model.live="endDate" />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        @if ($selectedAccount !== null && !$ledger['valid'])
            <x-filament::section compact class="financial-report-warning">
                <p role="alert">
                    Periode Buku Besar tidak valid.
                    Pastikan tanggal awal dan tanggal akhir telah diisi
                    serta tanggal awal tidak melebihi tanggal akhir.
                </p>
            </x-filament::section>
        @endif

        @if ($selectedAccount !== null && $ledger['valid'])
            <x-filament::section :heading="$selectedAccount->code.' — '.$selectedAccount->name">
                <x-slot name="description">
                    {{ \Carbon\Carbon::parse($this->startDate)->translatedFormat('d M Y') }}
                    – {{ \Carbon\Carbon::parse($this->endDate)->translatedFormat('d M Y') }}
                </x-slot>
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$selectedAccount->is_active ? 'success' : 'gray'">
                        {{ $selectedAccount->is_active ? 'Aktif' : 'Nonaktif' }}
                    </x-filament::badge>
                </x-slot>
                <dl class="financial-report-metrics financial-report-metrics-four">
                    @foreach (['opening_balance' => 'Saldo Awal', 'total_debit' => 'Total Debit', 'total_credit' => 'Total Kredit', 'closing_balance' => 'Saldo Akhir'] as $key => $label)
                        <div @class(['financial-report-metric-primary' => $key === 'closing_balance'])>
                            <dt>{{ $label }}</dt>
                            <dd>Rp {{ $this->formatAmount($ledger[$key]) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="financial-report-note flex flex-wrap gap-x-4 gap-y-1">
                    <span>Transaksi Periode: {{ number_format($ledger['transaction_count'], 0, ',', '.') }}</span>
                    <span>Tipe: {{ $selectedAccount->accountTypes()[$selectedAccount->account_type] ?? ucfirst($selectedAccount->account_type) }}</span>
                    <span>Saldo Normal: {{ $this->normalBalanceLabel($selectedAccount) }}</span>
                </div>
            </x-filament::section>

            {{ $this->table }}
        @elseif ($accounts->isEmpty())
            <x-filament::section compact>
                <p class="financial-report-note">Belum tersedia akun Buku Besar.</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
