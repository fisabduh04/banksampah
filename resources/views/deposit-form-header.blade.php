<div class="deposit-page-header">
    <style>
        .deposit-page-header { display: flex; align-items: center; justify-content: space-between; gap: 24px; }
        .deposit-page-header > .fi-header { flex: 1; min-width: 0; }
        .deposit-balance-card { width: 320px; flex-shrink: 0; padding: 18px 20px; border: 1px solid #a7f3d0; border-radius: 16px; background: #ecfdf5; color: #064e3b; box-shadow: 0 1px 3px rgb(0 0 0 / 4%); }
        .deposit-balance-card__top { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; }
        .deposit-balance-card__icon { width: 18px; height: 18px; flex-shrink: 0; }
        .deposit-balance-card__amount { margin-top: 8px; font-size: 28px; line-height: 1.2; font-weight: 700; letter-spacing: -.025em; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .deposit-balance-card__name { margin-top: 6px; font-size: 13px; font-weight: 500; overflow-wrap: anywhere; }
        .deposit-balance-card__note { margin-top: 8px; font-size: 12px; line-height: 1.5; color: #047857; }
        .deposit-balance-card__empty { margin-top: 8px; font-size: 14px; line-height: 1.5; }
        .dark .deposit-balance-card { background: #062b23; border-color: #145c47; color: #d1fae5; }
        .dark .deposit-balance-card__note { color: #6ee7b7; }
        @media (max-width: 767px) {
            .deposit-page-header { flex-direction: column; align-items: stretch; gap: 16px; }
            .deposit-balance-card { width: 100%; }
        }
    </style>

    <x-filament-panels::header
        :heading="$this->getHeading()"
        :subheading="$this->getSubheading()"
        :breadcrumbs="filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : []"
        :actions="$this->getCachedHeaderActions()"
        :actions-alignment="$this->getHeaderActionsAlignment()"
    />

    <aside class="deposit-balance-card" aria-label="Saldo Nasabah Saat Ini" aria-live="polite" aria-atomic="true">
        <div class="deposit-balance-card__top">
            <x-filament::icon icon="heroicon-o-wallet" class="deposit-balance-card__icon" />
            <span>Saldo Nasabah Saat Ini</span>
        </div>
        @if ($customerBalance !== null)
            <div class="deposit-balance-card__amount">Rp {{ number_format($customerBalance, 2, ',', '.') }}</div>
            <div class="deposit-balance-card__name">{{ $customerName }}</div>
            <p class="deposit-balance-card__note">Setoran baru menambah saldo setelah diposting.</p>
        @else
            <p class="deposit-balance-card__empty">Pilih nasabah untuk melihat saldo.</p>
        @endif
    </aside>
</div>
