<?php

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\CashMutationService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
