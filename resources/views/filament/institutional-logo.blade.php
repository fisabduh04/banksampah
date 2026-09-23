<div @class([
    'flex min-w-0 justify-center px-4',
    'bank-sampah-login institutional-logo-header' => $isHeader ?? false,
    'border-t border-gray-200 py-5 dark:border-white/10' => ! ($isHeader ?? false),
])>
    <div
        role="group"
        aria-label="Poltera dan Diktisaintek Berdampak"
        @class([
            'flex w-full max-w-64 items-center justify-center gap-3 rounded-xl bg-white px-4',
            'py-2' => $isHeader ?? false,
            'py-3' => ! ($isHeader ?? false),
        ])
    >
        {{-- Sumber logo: https://www.poltera.ac.id/logo-resmi-poltera/ --}}
        <img
            src="{{ asset('images/poltera.png') }}"
            alt="Politeknik Negeri Madura (Poltera)"
            width="400"
            height="440"
            class="institutional-logo-poltera h-12 w-auto shrink-0 object-contain"
        >

        <span aria-hidden="true" class="h-8 w-px shrink-0 bg-gray-200"></span>

        {{-- Sumber logo: https://lldikti13.kemdiktisaintek.go.id/ --}}
        <img
            src="{{ asset('images/diktisaintek-berdampak.png') }}"
            alt="Diktisaintek Berdampak — Kementerian Pendidikan Tinggi, Sains, dan Teknologi"
            width="1024"
            height="302"
            class="institutional-logo-dikti h-auto w-36 min-w-0 object-contain"
        >
    </div>
</div>
