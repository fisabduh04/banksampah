<span>
    {{ $type === 'cash' ? 'Belum ada rekening kas untuk direkonsiliasi' : 'Belum ada rekening bank untuk direkonsiliasi' }}
    @if (! $card['balanced'])
        @include('filament.dashboard-difference', ['amount' => $card['difference']])
    @endif
</span>
