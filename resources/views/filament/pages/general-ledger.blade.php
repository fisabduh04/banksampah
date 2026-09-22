<x-filament-panels::page>
    @php
        $accounts = $this->getAccounts();
        $ledger = $this->getLedgerData();
        $selectedAccount = $ledger['account'];
    @endphp

    <div class="space-y-6">

        {{-- =========================================================
             FILTER LAPORAN
             ========================================================= --}}
        <x-filament::section>
            <x-slot name="heading">
                Filter Buku Besar
            </x-slot>

            <x-slot name="description">
                Pilih akun dan periode laporan yang ingin ditampilkan.
            </x-slot>

            <div
                class="
                    grid
                    gap-5
                    lg:grid-cols-3
                ">

                {{-- Akun --}}
                <div class="space-y-2">
                    <label for="accountId"
                        class="
                            block
                            text-sm
                            font-medium
                            text-gray-950
                            dark:text-white
                        ">
                        Akun
                    </label>

                    <x-filament::input.wrapper>
                        <x-filament::input.select id="accountId" wire:model.live="accountId">
                            @if ($accounts->isEmpty())
                                <option value="">
                                    Tidak ada akun
                                </option>
                            @else
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->code }}
                                        —
                                        {{ $account->name }}

                                        @if (!$account->is_active)
                                            (Nonaktif)
                                        @endif
                                    </option>
                                @endforeach
                            @endif
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                {{-- Dari tanggal --}}
                <div class="space-y-2">
                    <label for="startDate"
                        class="
                            block
                            text-sm
                            font-medium
                            text-gray-950
                            dark:text-white
                        ">
                        Dari Tanggal
                    </label>

                    <x-filament::input.wrapper>
                        <x-filament::input id="startDate" type="date" wire:model.live="startDate" />
                    </x-filament::input.wrapper>
                </div>

                {{-- Sampai tanggal --}}
                <div class="space-y-2">
                    <label for="endDate"
                        class="
                            block
                            text-sm
                            font-medium
                            text-gray-950
                            dark:text-white
                        ">
                        Sampai Tanggal
                    </label>

                    <x-filament::input.wrapper>
                        <x-filament::input id="endDate" type="date" wire:model.live="endDate" />
                    </x-filament::input.wrapper>
                </div>

            </div>
        </x-filament::section>

        {{-- =========================================================
             VALIDASI PERIODE
             ========================================================= --}}
        @if ($selectedAccount !== null && !$ledger['valid'])
            <div
                class="
                    rounded-xl
                    border
                    border-danger-200
                    bg-danger-50
                    px-4
                    py-3
                    text-sm
                    text-danger-700
                    dark:border-danger-400/20
                    dark:bg-danger-400/10
                    dark:text-danger-300
                ">
                Periode Buku Besar tidak valid.
                Pastikan tanggal awal dan tanggal akhir telah diisi
                serta tanggal awal tidak melebihi tanggal akhir.
            </div>
        @endif

        @if ($selectedAccount !== null && $ledger['valid'])

            {{-- =====================================================
                 IDENTITAS AKUN
                 ===================================================== --}}
            <div
                class="
                    flex
                    flex-col
                    gap-4
                    rounded-2xl
                    border
                    border-gray-200
                    bg-white
                    p-5
                    shadow-sm
                    dark:border-white/10
                    dark:bg-gray-900
                    lg:flex-row
                    lg:items-center
                    lg:justify-between
                ">
                <div class="min-w-0">

                    <div
                        class="
                            text-xs
                            font-semibold
                            uppercase
                            tracking-wider
                            text-gray-500
                            dark:text-gray-400
                        ">
                        Akun Buku Besar
                    </div>

                    <div
                        class="
                            mt-1
                            flex
                            flex-wrap
                            items-center
                            gap-2
                        ">
                        <h2
                            class="
                                text-xl
                                font-bold
                                tracking-tight
                                text-gray-950
                                dark:text-white
                            ">
                            {{ $selectedAccount->code }}
                            —
                            {{ $selectedAccount->name }}
                        </h2>

                        @if ($selectedAccount->is_active)
                            <x-filament::badge color="success">
                                Aktif
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray">
                                Nonaktif
                            </x-filament::badge>
                        @endif
                    </div>

                    <div
                        class="
                            mt-2
                            flex
                            flex-wrap
                            gap-x-4
                            gap-y-1
                            text-sm
                            text-gray-500
                            dark:text-gray-400
                        ">
                        <span>
                            Tipe:
                            <strong
                                class="
                                    font-medium
                                    text-gray-700
                                    dark:text-gray-200
                                ">
                                {{ $selectedAccount->accountTypes()[$selectedAccount->account_type] ?? ucfirst($selectedAccount->account_type) }}
                            </strong>
                        </span>

                        <span>
                            Saldo Normal:
                            <strong
                                class="
                                    font-medium
                                    text-gray-700
                                    dark:text-gray-200
                                ">
                                {{ $this->normalBalanceLabel($selectedAccount) }}
                            </strong>
                        </span>

                        <span>
                            Periode:
                            <strong
                                class="
                                    font-medium
                                    text-gray-700
                                    dark:text-gray-200
                                ">
                                {{ \Carbon\Carbon::parse($this->startDate)->format('d/m/Y') }}

                                s.d.

                                {{ \Carbon\Carbon::parse($this->endDate)->format('d/m/Y') }}
                            </strong>
                        </span>
                    </div>

                </div>

                {{-- Jumlah transaksi --}}
                <div
                    class="
                        shrink-0
                        rounded-xl
                        bg-gray-50
                        px-4
                        py-3
                        dark:bg-white/5
                    ">
                    <div
                        class="
                            text-xs
                            font-medium
                            uppercase
                            tracking-wide
                            text-gray-500
                            dark:text-gray-400
                        ">
                        Transaksi Periode
                    </div>

                    <div
                        class="
                            mt-1
                            text-2xl
                            font-bold
                            tabular-nums
                            text-gray-950
                            dark:text-white
                        ">
                        {{ number_format($ledger['transaction_count'], 0, ',', '.') }}
                    </div>
                </div>
            </div>

            {{-- =====================================================
                 RINGKASAN KEUANGAN
                 ===================================================== --}}
            <div
                class="
                    grid
                    gap-4
                    sm:grid-cols-2
                    xl:grid-cols-4
                ">

                {{-- Saldo awal --}}
                <div
                    class="
                        rounded-xl
                        border
                        border-gray-200
                        bg-white
                        p-5
                        shadow-sm
                        dark:border-white/10
                        dark:bg-gray-900
                    ">
                    <div
                        class="
                            text-sm
                            font-medium
                            text-gray-500
                            dark:text-gray-400
                        ">
                        Saldo Awal
                    </div>

                    <div
                        class="
                            mt-2
                            text-xl
                            font-bold
                            tabular-nums
                            tracking-tight
                            text-gray-950
                            dark:text-white
                        ">
                        Rp
                        {{ $this->formatAmount($ledger['opening_balance']) }}
                    </div>
                </div>

                {{-- Total debit --}}
                <div
                    class="
                        rounded-xl
                        border
                        border-gray-200
                        bg-white
                        p-5
                        shadow-sm
                        dark:border-white/10
                        dark:bg-gray-900
                    ">
                    <div
                        class="
                            text-sm
                            font-medium
                            text-gray-500
                            dark:text-gray-400
                        ">
                        Total Debit
                    </div>

                    <div
                        class="
                            mt-2
                            text-xl
                            font-bold
                            tabular-nums
                            tracking-tight
                            text-gray-950
                            dark:text-white
                        ">
                        Rp
                        {{ $this->formatAmount($ledger['total_debit']) }}
                    </div>
                </div>

                {{-- Total kredit --}}
                <div
                    class="
                        rounded-xl
                        border
                        border-gray-200
                        bg-white
                        p-5
                        shadow-sm
                        dark:border-white/10
                        dark:bg-gray-900
                    ">
                    <div
                        class="
                            text-sm
                            font-medium
                            text-gray-500
                            dark:text-gray-400
                        ">
                        Total Kredit
                    </div>

                    <div
                        class="
                            mt-2
                            text-xl
                            font-bold
                            tabular-nums
                            tracking-tight
                            text-gray-950
                            dark:text-white
                        ">
                        Rp
                        {{ $this->formatAmount($ledger['total_credit']) }}
                    </div>
                </div>

                {{-- Saldo akhir --}}
                <div
                    class="
                        rounded-xl
                        border
                        border-primary-200
                        bg-primary-50
                        p-5
                        shadow-sm
                        dark:border-primary-400/20
                        dark:bg-primary-400/10
                    ">
                    <div
                        class="
                            text-sm
                            font-semibold
                            text-primary-700
                            dark:text-primary-300
                        ">
                        Saldo Akhir
                    </div>

                    <div
                        class="
                            mt-2
                            text-xl
                            font-extrabold
                            tabular-nums
                            tracking-tight
                            text-primary-700
                            dark:text-primary-300
                        ">
                        Rp
                        {{ $this->formatAmount($ledger['closing_balance']) }}
                    </div>
                </div>

            </div>

            {{-- =====================================================
                 NATIVE FILAMENT TABLE
                 ===================================================== --}}
            <div>
                {{ $this->table }}
            </div>
        @elseif ($accounts->isEmpty())
            <x-filament::section>
                <div
                    class="
                        py-12
                        text-center
                        text-sm
                        text-gray-500
                        dark:text-gray-400
                    ">
                    Belum tersedia akun Buku Besar.
                </div>
            </x-filament::section>

        @endif

    </div>
</x-filament-panels::page>
