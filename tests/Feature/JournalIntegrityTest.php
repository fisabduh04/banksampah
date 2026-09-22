<?php

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\CashMutationService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    /**
     * Pengujian hanya boleh berjalan
     * dalam environment testing.
     */
    if (! app()->environment('testing')) {
        throw new RuntimeException(
            'Pengujian hanya boleh berjalan dalam environment testing.'
        );
    }

    /**
     * Gunakan database khusus testing.
     */
    config([
        'database.connections.journal_integrity_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'journal_integrity_test',
    ]);

    $connection = DB::connection();

    if (
        $connection->getDriverName() !== 'mysql'
        || $connection->selectOne(
            'SELECT DATABASE() AS name'
        )->name !== 'banksampah_testing'
    ) {
        throw new RuntimeException(
            'Pengujian hanya boleh memakai MySQL banksampah_testing.'
        );
    }

    /**
     * Semua perubahan selama test akan di-rollback.
     */
    $connection->beginTransaction();

    /**
     * Pastikan akun sistem tersedia.
     */
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') === 'journal_integrity_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

/**
 * ============================================================
 * TEST 1
 * Request posting yang benar-benar sama harus idempotent.
 * ============================================================
 */
test(
    'posting jurnal yang sama dua kali hanya menghasilkan satu jurnal',
    function (): void {
        $user = User::factory()->create();

        $cashAccount = Account::query()
            ->where('system_key', 'cash')
            ->sole();

        $openingBalance = Account::query()
            ->where('system_key', 'opening_balance')
            ->sole();

        $service = app(JournalService::class);

        $lines = [
            [
                'account_id' => $cashAccount->id,
                'debit' => '100000.00',
                'credit' => '0.00',
                'description' => 'Kas masuk',
            ],
            [
                'account_id' => $openingBalance->id,
                'debit' => '0.00',
                'credit' => '100000.00',
                'description' => 'Saldo awal',
            ],
        ];

        $first = $service->post(
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_post',
            referenceId: $user->id,
            referenceNumber: 'JIT-POST-001',
            description: 'Uji idempotency posting jurnal',
            userId: $user->id,
            lines: $lines
        );

        /**
         * Kirim request persis sama.
         */
        $second = $service->post(
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_post',
            referenceId: $user->id,
            referenceNumber: 'JIT-POST-001',
            description: 'Uji idempotency posting jurnal',
            userId: $user->id,
            lines: $lines
        );

        /**
         * Harus mengembalikan jurnal yang sama.
         */
        expect($second->id)
            ->toBe($first->id);

        expect(
            JournalEntry::query()
                ->where(
                    'reference_type',
                    'journal_integrity_post'
                )
                ->where('reference_id', $user->id)
                ->count()
        )->toBe(1);

        expect(
            JournalLine::query()
                ->where(
                    'journal_entry_id',
                    $first->id
                )
                ->count()
        )->toBe(2);
    }
);

/**
 * ============================================================
 * TEST 2
 * Referensi sama tetapi nominal berbeda harus ditolak.
 * ============================================================
 */
test(
    'referensi jurnal yang sama dengan payload berbeda ditolak',
    function (): void {
        $user = User::factory()->create();

        $cashAccount = Account::query()
            ->where('system_key', 'cash')
            ->sole();

        $openingBalance = Account::query()
            ->where('system_key', 'opening_balance')
            ->sole();

        $service = app(JournalService::class);

        /**
         * Posting pertama Rp100.000.
         */
        $service->post(
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_different',
            referenceId: $user->id,
            referenceNumber: 'JIT-DIFF-001',
            description: 'Posting pertama',
            userId: $user->id,
            lines: [
                [
                    'account_id' => $cashAccount->id,
                    'debit' => '100000.00',
                    'credit' => '0.00',
                    'description' => 'Kas masuk',
                ],
                [
                    'account_id' => $openingBalance->id,
                    'debit' => '0.00',
                    'credit' => '100000.00',
                    'description' => 'Saldo awal',
                ],
            ]
        );

        /**
         * Referensi transaksi sama,
         * tetapi nominal diubah menjadi Rp150.000.
         *
         * Ini harus ditolak.
         */
        expect(
            fn () => $service->post(
                transactionDate: now()->toDateString(),
                referenceType: 'journal_integrity_different',
                referenceId: $user->id,
                referenceNumber: 'JIT-DIFF-001',
                description: 'Posting pertama',
                userId: $user->id,
                lines: [
                    [
                        'account_id' => $cashAccount->id,
                        'debit' => '150000.00',
                        'credit' => '0.00',
                        'description' => 'Kas masuk',
                    ],
                    [
                        'account_id' => $openingBalance->id,
                        'debit' => '0.00',
                        'credit' => '150000.00',
                        'description' => 'Saldo awal',
                    ],
                ]
            )
        )->toThrow(RuntimeException::class);

        /**
         * Database tetap hanya memiliki satu jurnal.
         */
        expect(
            JournalEntry::query()
                ->where(
                    'reference_type',
                    'journal_integrity_different'
                )
                ->where('reference_id', $user->id)
                ->count()
        )->toBe(1);
    }
);

/**
 * ============================================================
 * TEST 3
 * Retry reversal yang identik tidak boleh membuat reversal baru.
 * ============================================================
 */
test(
    'reversal yang sama dua kali hanya menghasilkan satu jurnal pembalik',
    function (): void {
        $user = User::factory()->create();

        $cashAccount = Account::query()
            ->where('system_key', 'cash')
            ->sole();

        $openingBalance = Account::query()
            ->where('system_key', 'opening_balance')
            ->sole();

        $service = app(JournalService::class);

        $original = $service->post(
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_original',
            referenceId: $user->id,
            referenceNumber: 'JIT-ORI-001',
            description: 'Jurnal asal untuk reversal',
            userId: $user->id,
            lines: [
                [
                    'account_id' => $cashAccount->id,
                    'debit' => '75000.00',
                    'credit' => '0.00',
                    'description' => 'Kas',
                ],
                [
                    'account_id' => $openingBalance->id,
                    'debit' => '0.00',
                    'credit' => '75000.00',
                    'description' => 'Saldo awal',
                ],
            ]
        );

        $firstReversal = $service->reverse(
            journalEntry: $original,
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_reversal',
            referenceId: $original->id,
            referenceNumber: 'REV-JIT-001',
            description: 'Reversal pengujian',
            userId: $user->id
        );

        /**
         * Retry dengan payload yang sama.
         */
        $secondReversal = $service->reverse(
            journalEntry: $original,
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_reversal',
            referenceId: $original->id,
            referenceNumber: 'REV-JIT-001',
            description: 'Reversal pengujian',
            userId: $user->id
        );

        expect($secondReversal->id)
            ->toBe($firstReversal->id);

        /**
         * Satu jurnal asal hanya memiliki satu reversal.
         */
        expect(
            JournalEntry::query()
                ->where(
                    'reversal_of_id',
                    $original->id
                )
                ->count()
        )->toBe(1);

        /**
         * Jurnal asal harus berstatus reversed.
         */
        expect(
            $original->fresh()->status
        )->toBe(JournalEntry::STATUS_REVERSED);
    }
);

/**
 * ============================================================
 * TEST 4
 * Reversal kedua dengan payload berbeda harus ditolak.
 * ============================================================
 */
test(
    'reversal kedua dengan data berbeda ditolak',
    function (): void {
        $user = User::factory()->create();

        $cashAccount = Account::query()
            ->where('system_key', 'cash')
            ->sole();

        $openingBalance = Account::query()
            ->where('system_key', 'opening_balance')
            ->sole();

        $service = app(JournalService::class);

        $original = $service->post(
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_original_diff',
            referenceId: $user->id,
            referenceNumber: 'JIT-ORI-DIFF',
            description: 'Jurnal asal',
            userId: $user->id,
            lines: [
                [
                    'account_id' => $cashAccount->id,
                    'debit' => '50000.00',
                    'credit' => '0.00',
                    'description' => 'Kas',
                ],
                [
                    'account_id' => $openingBalance->id,
                    'debit' => '0.00',
                    'credit' => '50000.00',
                    'description' => 'Saldo awal',
                ],
            ]
        );

        /**
         * Reversal pertama.
         */
        $service->reverse(
            journalEntry: $original,
            transactionDate: now()->toDateString(),
            referenceType: 'journal_integrity_reversal_diff',
            referenceId: $original->id,
            referenceNumber: 'REV-JIT-A',
            description: 'Reversal pertama',
            userId: $user->id
        );

        /**
         * Jurnal asal yang sama dicoba direversal lagi,
         * tetapi payload diubah.
         */
        expect(
            fn () => $service->reverse(
                journalEntry: $original,
                transactionDate: now()->toDateString(),
                referenceType: 'journal_integrity_reversal_diff',
                referenceId: $original->id,
                referenceNumber: 'REV-JIT-B',
                description: 'Reversal berbeda',
                userId: $user->id
            )
        )->toThrow(RuntimeException::class);

        expect(
            JournalEntry::query()
                ->where(
                    'reversal_of_id',
                    $original->id
                )
                ->count()
        )->toBe(1);
    }
);

/**
 * ============================================================
 * TEST 5
 * Jika jurnal gagal, mutasi Kas yang dibuat sebelumnya
 * juga harus dibatalkan.
 *
 * Ini membuktikan atomicity antar service.
 * ============================================================
 */
test(
    'kegagalan jurnal membatalkan mutasi kas secara atomik',
    function (): void {
        $user = User::factory()->create();

        $cashAccount = CashAccount::create([
            'code' => 'KAS-ATOMIC-'.$user->id,
            'name' => 'Kas Uji Atomicity',
            'account_type' => CashAccount::TYPE_CASH,
            'is_active' => true,
        ]);

        /**
         * Buat akun lawan yang NONAKTIF.
         *
         * CashMutationService akan sempat mencoba
         * membuat mutasi, tetapi JournalService
         * harus menolak akun ini.
         */
        $inactiveAccount = Account::create([
            'code' => '998'.$user->id,
            'name' => 'Akun Nonaktif Uji Atomicity',
            'account_type' => Account::TYPE_EQUITY,
            'normal_balance' => Account::NORMAL_CREDIT,
            'parent_id' => null,
            'is_postable' => true,
            'is_active' => false,
            'system_key' => null,
            'notes' => 'Hanya untuk pengujian atomic rollback',
        ]);

        $idempotencyKey = (string) Str::uuid();

        /**
         * Karena akun lawan tidak aktif,
         * JournalService harus gagal.
         */
        expect(
            fn () => app(CashMutationService::class)
                ->recordManualReceipt(
                    cashAccountId: $cashAccount->id,
                    transactionDate: now()->toDateString(),
                    amount: '100000.00',
                    referenceNumber: 'ATOMIC-FAIL-001',
                    description: 'Uji rollback atomik',
                    counterAccountId: $inactiveAccount->id,
                    userId: $user->id,
                    idempotencyKey: $idempotencyKey
                )
        )->toThrow(RuntimeException::class);

        /**
         * Mutasi yang sempat dibuat sebelum jurnal gagal
         * harus ikut ter-rollback.
         */
        expect(
            CashMutation::query()
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->exists()
        )->toBeFalse();

        /**
         * Jurnal juga tidak boleh tersisa.
         */
        expect(
            JournalEntry::query()
                ->where(
                    'reference_number',
                    'ATOMIC-FAIL-001'
                )
                ->exists()
        )->toBeFalse();

        /**
         * Saldo Kas harus tetap nol.
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('0.00');
    }
);
