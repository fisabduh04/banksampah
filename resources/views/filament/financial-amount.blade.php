@php
    [$whole, $fraction] = explode('.', $amount);
    $formatted = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.$fraction;
@endphp
<span class="whitespace-nowrap tabular-nums">Rp {{ $formatted }}</span>
