<?php

use App\Filament\Resources\CashMutations\Pages\ListCashMutations;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\CashMutationService;
use Database\Seeders\AccountSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    /**
     * Test tidak boleh berjalan pada database produksi.
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
        'database.connections.cash_manual_accounting_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'cash_manual_accounting_test',
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
     * Semua perubahan test dibungkus transaction
     * sehingga database kembali bersih setelah test.
     */
    $connection->beginTransaction();

    /**
     * Pastikan Chart of Accounts tersedia.
     */
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') ===
            'cash_manual_accounting_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

test(
    'penerimaan dan pengeluaran kas manual menghasilkan ledger dan jurnal yang konsisten',
    function (): void {
        $user = User::factory()->create();

        /**
         * Kas fisik yang digunakan untuk pengujian.
         */
        $cashAccount = CashAccount::create([
            'code' => 'KAS-AKT-MANUAL',
            'name' => 'Kas Uji Akuntansi Manual',
            'account_type' => CashAccount::TYPE_CASH,
            'is_active' => true,
        ]);

        /**
         * Ambil akun buku besar.
         */
        $cashLedgerAccount = Account::query()
            ->where('system_key', 'cash')
            ->sole();

        $openingBalanceAccount = Account::query()
            ->where('system_key', 'opening_balance')
            ->sole();

        $operatingExpenseAccount = Account::query()
            ->where('system_key', 'operating_expense')
            ->sole();

        /*
         * =====================================================
         * 1. PENERIMAAN KAS MANUAL
         * =====================================================
         *
         * Dr Kas                  Rp100.000
         *     Cr Saldo Awal       Rp100.000
         */
        $receiptKey = (string) Str::uuid();

        $receipt = app(CashMutationService::class)
            ->recordManualReceipt(
                cashAccountId: $cashAccount->id,
                transactionDate: now()->toDateString(),
                amount: '100000.00',
                referenceNumber: 'TEST-MASUK-001',
                description: 'Uji penerimaan kas manual',
                counterAccountId: $openingBalanceAccount->id,
                userId: $user->id,
                idempotencyKey: $receiptKey
            );

        expect($receipt->mutation_type)
            ->toBe(CashMutation::TYPE_IN);

        expect($receipt->amount)
            ->toBe('100000.00');

        expect($receipt->reference_type)
            ->toBe('manual_receipt');

        expect($receipt->counter_account_id)
            ->toBe($openingBalanceAccount->id);

        /**
         * Saldo kas setelah penerimaan.
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('100000.00');

        /**
         * Pastikan jurnal penerimaan terbentuk.
         */
        $receiptJournal = JournalEntry::query()
            ->where('reference_type', 'manual_receipt')
            ->where('reference_id', $receipt->id)
            ->with('lines')
            ->sole();

        expect($receiptJournal->lines)
            ->toHaveCount(2);

        $receiptCashLine = $receiptJournal->lines
            ->where(
                'account_id',
                $cashLedgerAccount->id
            )
            ->first();

        expect($receiptCashLine)
            ->not->toBeNull();

        expect($receiptCashLine->debit)
            ->toBe('100000.00');

        expect($receiptCashLine->credit)
            ->toBe('0.00');

        $receiptCounterLine = $receiptJournal->lines
            ->where(
                'account_id',
                $openingBalanceAccount->id
            )
            ->first();

        expect($receiptCounterLine)
            ->not->toBeNull();

        expect($receiptCounterLine->debit)
            ->toBe('0.00');

        expect($receiptCounterLine->credit)
            ->toBe('100000.00');

        /*
         * =====================================================
         * 2. PENGELUARAN KAS MANUAL
         * =====================================================
         *
         * Dr Beban Operasional     Rp40.000
         *     Cr Kas               Rp40.000
         */
        $expenseKey = (string) Str::uuid();

        $expense = app(CashMutationService::class)
            ->recordManualExpense(
                cashAccountId: $cashAccount->id,
                transactionDate: now()->toDateString(),
                amount: '40000.00',
                referenceNumber: 'TEST-KELUAR-001',
                description: 'Uji pengeluaran kas manual',
                counterAccountId: $operatingExpenseAccount->id,
                userId: $user->id,
                idempotencyKey: $expenseKey
            );

        expect($expense->mutation_type)
            ->toBe(CashMutation::TYPE_OUT);

        expect($expense->amount)
            ->toBe('40000.00');

        expect($expense->reference_type)
            ->toBe('manual_expense');

        expect($expense->counter_account_id)
            ->toBe($operatingExpenseAccount->id);

        /**
         * Saldo:
         *
         * Rp100.000 - Rp40.000 = Rp60.000
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('60000.00');

        /**
         * Pastikan jurnal pengeluaran terbentuk.
         */
        $expenseJournal = JournalEntry::query()
            ->where('reference_type', 'manual_expense')
            ->where('reference_id', $expense->id)
            ->with('lines')
            ->sole();

        expect($expenseJournal->lines)
            ->toHaveCount(2);

        $expenseCounterLine = $expenseJournal->lines
            ->where(
                'account_id',
                $operatingExpenseAccount->id
            )
            ->first();

        expect($expenseCounterLine)
            ->not->toBeNull();

        expect($expenseCounterLine->debit)
            ->toBe('40000.00');

        expect($expenseCounterLine->credit)
            ->toBe('0.00');

        $expenseCashLine = $expenseJournal->lines
            ->where(
                'account_id',
                $cashLedgerAccount->id
            )
            ->first();

        expect($expenseCashLine)
            ->not->toBeNull();

        expect($expenseCashLine->debit)
            ->toBe('0.00');

        expect($expenseCashLine->credit)
            ->toBe('40000.00');

        /*
         * =====================================================
         * 3. IDEMPOTENCY
         * =====================================================
         *
         * Request yang sama tidak boleh menghasilkan
         * mutasi dan jurnal kedua.
         */
        $receiptAgain = app(CashMutationService::class)
            ->recordManualReceipt(
                cashAccountId: $cashAccount->id,
                transactionDate: now()->toDateString(),
                amount: '100000.00',
                referenceNumber: 'TEST-MASUK-001',
                description: 'Uji penerimaan kas manual',
                counterAccountId: $openingBalanceAccount->id,
                userId: $user->id,
                idempotencyKey: $receiptKey
            );

        expect($receiptAgain->id)
            ->toBe($receipt->id);

        expect(
            CashMutation::query()
                ->where(
                    'idempotency_key',
                    $receiptKey
                )
                ->count()
        )->toBe(1);

        expect(
            JournalEntry::query()
                ->where('reference_type', 'manual_receipt')
                ->where('reference_id', $receipt->id)
                ->count()
        )->toBe(1);

        /**
         * Saldo harus tetap Rp60.000.
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('60000.00');
    }
);

test(
    'pengeluaran manual ditolak apabila saldo kas tidak mencukupi',
    function (): void {
        $user = User::factory()->create();

        $cashAccount = CashAccount::create([
            'code' => 'KAS-AKT-MINUS',
            'name' => 'Kas Uji Proteksi Saldo',
            'account_type' => CashAccount::TYPE_CASH,
            'is_active' => true,
        ]);

        $openingBalanceAccount = Account::query()
            ->where('system_key', 'opening_balance')
            ->sole();

        $expenseAccount = Account::query()
            ->where('system_key', 'operating_expense')
            ->sole();

        /**
         * Isi Kas hanya Rp50.000.
         */
        app(CashMutationService::class)
            ->recordManualReceipt(
                cashAccountId: $cashAccount->id,
                transactionDate: now()->toDateString(),
                amount: '50000.00',
                referenceNumber: 'TEST-SALDO-001',
                description: 'Saldo awal pengujian',
                counterAccountId: $openingBalanceAccount->id,
                userId: $user->id,
                idempotencyKey: (string) Str::uuid()
            );

        /**
         * Mencoba mengeluarkan Rp100.000 harus gagal.
         */
        expect(
            fn () => app(CashMutationService::class)
                ->recordManualExpense(
                    cashAccountId: $cashAccount->id,
                    transactionDate: now()->toDateString(),
                    amount: '100000.00',
                    referenceNumber: 'TEST-MINUS-001',
                    description: 'Pengeluaran melebihi saldo',
                    counterAccountId: $expenseAccount->id,
                    userId: $user->id,
                    idempotencyKey: (string) Str::uuid()
                )
        )->toThrow(
            RuntimeException::class,
            'Saldo Kas/Bank tidak mencukupi'
        );

        /**
         * Tidak boleh terbentuk transaksi pengeluaran gagal.
         */
        expect(
            CashMutation::query()
                ->where(
                    'reference_number',
                    'TEST-MINUS-001'
                )
                ->exists()
        )->toBeFalse();

        expect(
            JournalEntry::query()
                ->where(
                    'reference_number',
                    'TEST-MINUS-001'
                )
                ->exists()
        )->toBeFalse();

        /**
         * Saldo tetap Rp50.000.
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('50000.00');
    }
);

test('transaksi kas manual menolak akun kontrol tanpa mengubah saldo atau jurnal', function (string $method, string $counterSystemKey, string $cashAccountType): void {
    $user = User::factory()->create();
    $cashAccount = CashAccount::create([
        'code' => 'KAS-KONTROL', 'name' => 'Rekening Uji Akun Kontrol',
        'account_type' => $cashAccountType, 'is_active' => true,
    ]);
    CashMutation::create([
        'cash_account_id' => $cashAccount->id, 'mutation_type' => CashMutation::TYPE_IN,
        'amount' => '200.00', 'transaction_date' => now()->toDateString(),
        'reference_type' => 'test_opening_balance', 'reference_id' => $cashAccount->id,
    ]);
    $counterAccount = Account::where('system_key', $counterSystemKey)->sole();
    $cashMutationCount = CashMutation::count();
    $journalCount = JournalEntry::count();

    expect(fn () => app(CashMutationService::class)->{$method}(
        cashAccountId: $cashAccount->id,
        transactionDate: now()->toDateString(),
        amount: '50.00',
        referenceNumber: 'KONTROL-DITOLAK',
        description: 'Uji proteksi akun kontrol',
        counterAccountId: $counterAccount->id,
        userId: $user->id,
        idempotencyKey: (string) Str::uuid()
    ))->toThrow(RuntimeException::class, 'Akun kontrol tidak boleh digunakan sebagai akun lawan transaksi kas manual.');

    $this->assertDatabaseCount('cash_mutations', $cashMutationCount);
    $this->assertDatabaseCount('journal_entries', $journalCount);
    expect(app(CashMutationService::class)->balance($cashAccount))->toBe('200.00');
})->with(['recordManualReceipt', 'recordManualExpense'])
    ->with(['cash', 'bank', 'collector_receivable', 'inventory', 'customer_savings'])
    ->with(['cash', 'bank']);

test('transaksi kas manual tetap menerima akun nonkontrol dan system key kosong', function (string $method, ?string $counterSystemKey): void {
    $user = User::factory()->create();
    $cashAccount = CashAccount::create([
        'code' => 'KAS-NONKONTROL', 'name' => 'Kas Uji Akun Nonkontrol',
        'account_type' => CashAccount::TYPE_CASH, 'is_active' => true,
    ]);
    CashMutation::create([
        'cash_account_id' => $cashAccount->id, 'mutation_type' => CashMutation::TYPE_IN,
        'amount' => '200.00', 'transaction_date' => now()->toDateString(),
        'reference_type' => 'test_opening_balance', 'reference_id' => $cashAccount->id,
    ]);
    $counterAccount = $counterSystemKey === null
        ? Account::create([
            'code' => '59'.$user->id, 'name' => 'Akun Manual Uji',
            'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::NORMAL_DEBIT,
            'is_active' => true, 'is_postable' => true, 'system_key' => null,
        ])
        : Account::where('system_key', $counterSystemKey)->sole();

    $mutation = app(CashMutationService::class)->{$method}(
        cashAccountId: $cashAccount->id,
        transactionDate: now()->toDateString(),
        amount: '50.00',
        referenceNumber: 'NONKONTROL-DITERIMA',
        description: 'Uji akun lawan yang tetap diizinkan',
        counterAccountId: $counterAccount->id,
        userId: $user->id,
        idempotencyKey: (string) Str::uuid()
    );

    expect($mutation->counter_account_id)->toBe($counterAccount->id);
    expect(app(CashMutationService::class)->balance($cashAccount))
        ->toBe($method === 'recordManualReceipt' ? '250.00' : '150.00');
    $journal = JournalEntry::where('reference_type', $mutation->reference_type)
        ->where('reference_id', $mutation->id)->with('lines')->sole();
    expect($journal->lines)->toHaveCount(2);
    $counterLine = $journal->lines->where('account_id', $counterAccount->id)->sole();
    expect($counterLine->debit)->toBe($method === 'recordManualExpense' ? '50.00' : '0.00');
    expect($counterLine->credit)->toBe($method === 'recordManualReceipt' ? '50.00' : '0.00');
})->with(['recordManualReceipt', 'recordManualExpense'])
    ->with(['opening_balance', 'sales_revenue', 'cogs', 'operating_expense', null]);

test('replay identik kas manual historis dengan akun kontrol tetap mengembalikan transaksi asal', function (string $method, string $referenceType, string $mutationType): void {
    $user = User::factory()->create();
    $cashAccount = CashAccount::create([
        'code' => 'KAS-HISTORIS', 'name' => 'Kas Uji Replay Historis',
        'account_type' => CashAccount::TYPE_CASH, 'is_active' => true,
    ]);
    CashMutation::create([
        'cash_account_id' => $cashAccount->id, 'mutation_type' => CashMutation::TYPE_IN,
        'amount' => '200.00', 'transaction_date' => now()->toDateString(),
        'reference_type' => 'test_opening_balance', 'reference_id' => $cashAccount->id,
    ]);
    $counterAccount = Account::where('system_key', 'inventory')->sole();
    $idempotencyKey = (string) Str::uuid();
    $original = CashMutation::create([
        'cash_account_id' => $cashAccount->id, 'counter_account_id' => $counterAccount->id,
        'transaction_date' => now()->toDateString(), 'mutation_type' => $mutationType,
        'amount' => '50.00', 'reference_type' => $referenceType, 'reference_id' => null,
        'reference_number' => 'MANUAL-HISTORIS', 'description' => 'Transaksi historis pengujian',
        'created_by' => $user->id, 'idempotency_key' => $idempotencyKey,
    ]);
    $originalAttributes = $original->fresh()->getAttributes();
    $cashMutationCount = CashMutation::count();
    $journalCount = JournalEntry::count();
    $balance = app(CashMutationService::class)->balance($cashAccount);

    $replayed = app(CashMutationService::class)->{$method}(
        cashAccountId: $cashAccount->id,
        transactionDate: now()->toDateString(),
        amount: '50.00',
        referenceNumber: 'MANUAL-HISTORIS',
        description: 'Transaksi historis pengujian',
        counterAccountId: $counterAccount->id,
        userId: $user->id,
        idempotencyKey: $idempotencyKey
    );

    expect($replayed->getAttributes())->toBe($originalAttributes);
    $this->assertDatabaseCount('cash_mutations', $cashMutationCount);
    $this->assertDatabaseCount('journal_entries', $journalCount);
    expect(app(CashMutationService::class)->balance($cashAccount))->toBe($balance);
})->with([
    ['recordManualReceipt', 'manual_receipt', 'in'],
    ['recordManualExpense', 'manual_expense', 'out'],
]);

test('pilihan akun lawan kas manual menyembunyikan akun kontrol dan mempertahankan akun valid', function (string $action): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $accountAttributes = [
        'name' => 'Akun Pilihan Manual Uji', 'account_type' => Account::TYPE_EXPENSE,
        'normal_balance' => Account::NORMAL_DEBIT, 'is_active' => true,
        'is_postable' => true, 'system_key' => null,
    ];
    $customAccount = Account::create([...$accountAttributes, 'code' => 'UI-1-'.$user->id]);
    $inactiveAccount = Account::create([...$accountAttributes, 'code' => 'UI-2-'.$user->id, 'is_active' => false]);
    $nonPostableAccount = Account::create([...$accountAttributes, 'code' => 'UI-3-'.$user->id, 'is_postable' => false]);
    $systemAccounts = Account::whereNotNull('system_key')->get()->keyBy('system_key');

    Livewire::test(ListCashMutations::class)
        ->mountAction($action)
        ->assertActionMounted($action)
        ->assertFormFieldExists('counter_account_id', function (Select $field) use ($systemAccounts, $customAccount, $inactiveAccount, $nonPostableAccount): bool {
            $options = $field->getOptions();
            foreach (['cash', 'bank', 'collector_receivable', 'inventory', 'customer_savings'] as $systemKey) {
                expect($options)->not->toHaveKey((string) $systemAccounts->get($systemKey)->id);
            }
            foreach (['opening_balance', 'sales_revenue', 'cogs', 'operating_expense'] as $systemKey) {
                expect($options)->toHaveKey((string) $systemAccounts->get($systemKey)->id);
            }
            expect($options)->toHaveKey((string) $customAccount->id)
                ->not->toHaveKey((string) $inactiveAccount->id)
                ->not->toHaveKey((string) $nonPostableAccount->id);

            return true;
        });
})->with(['penerimaanKas', 'pengeluaranKas']);
