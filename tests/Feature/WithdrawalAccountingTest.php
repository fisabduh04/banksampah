<?php

use App\Models\Account;
use App\Models\BalanceMutation;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CashMutationService;
use App\Services\WithdrawalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException(
            'Pengujian hanya boleh berjalan dalam environment testing.'
        );
    }

    config([
        'database.connections.withdrawal_accounting_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'withdrawal_accounting_test',
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

    $connection->beginTransaction();

    /**
     * Pastikan Chart of Accounts tersedia
     * pada database pengujian.
     */
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') === 'withdrawal_accounting_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

test('posting penarikan tanpa akun kas ditolak tanpa perubahan keuangan', function (bool $withUser): void {
    $user = User::factory()->create();
    $customer = Customer::create([
        'customer_code' => 'WD-TANPA-KAS', 'name' => 'Nasabah Uji Tanpa Kas',
    ]);
    BalanceMutation::create([
        'customer_id' => $customer->id, 'type' => 'credit', 'amount' => '100.00',
        'reference_type' => 'test_opening_balance', 'reference_id' => $customer->id,
        'transaction_date' => now()->toDateString(), 'description' => 'Saldo awal pengujian',
    ]);
    $withdrawal = Withdrawal::create([
        'withdrawal_number' => 'WD-TANPA-KAS', 'customer_id' => $customer->id,
        'cash_account_id' => null, 'transaction_date' => now()->toDateString(),
        'amount' => '100.00', 'status' => 'draft',
    ]);
    $balanceMutationCount = BalanceMutation::count();
    $cashMutationCount = CashMutation::count();
    $journalCount = JournalEntry::count();

    expect(fn () => app(WithdrawalService::class)->post($withdrawal, $withUser ? $user->id : null))
        ->toThrow(Exception::class, 'Pilih Kas/Bank sumber pembayaran sebelum penarikan diposting.');

    $this->assertDatabaseCount('balance_mutations', $balanceMutationCount);
    $this->assertDatabaseCount('cash_mutations', $cashMutationCount);
    $this->assertDatabaseCount('journal_entries', $journalCount);
    expect($withdrawal->fresh())->status->toBe('draft')->posted_at->toBeNull()->posted_by->toBeNull();
})->with([
    'userId null' => [false],
    'userId non-null' => [true],
]);

test('pembatalan penarikan historis tanpa akun kas dan jurnal tetap mengembalikan saldo', function (): void {
    $user = User::factory()->create();
    $customer = Customer::create([
        'customer_code' => 'WD-HISTORIS', 'name' => 'Nasabah Uji Histori',
    ]);
    BalanceMutation::create([
        'customer_id' => $customer->id, 'type' => 'credit', 'amount' => '100.00',
        'reference_type' => 'test_opening_balance', 'reference_id' => $customer->id,
        'transaction_date' => now()->toDateString(), 'description' => 'Saldo awal pengujian',
    ]);
    $withdrawal = Withdrawal::create([
        'withdrawal_number' => 'WD-HISTORIS', 'customer_id' => $customer->id,
        'cash_account_id' => null, 'transaction_date' => now()->toDateString(),
        'amount' => '100.00', 'status' => 'posted', 'posted_at' => now(),
    ]);
    $originalMutation = BalanceMutation::create([
        'customer_id' => $customer->id, 'type' => 'debit', 'amount' => '100.00',
        'reference_type' => 'withdrawal', 'reference_id' => $withdrawal->id,
        'transaction_date' => now()->toDateString(), 'description' => 'Penarikan historis',
    ]);
    $originalBefore = $originalMutation->fresh()->getAttributes();
    $cashMutationCount = CashMutation::count();
    $journalCount = JournalEntry::count();

    app(WithdrawalService::class)->cancel($withdrawal, 'Koreksi penarikan historis', $user->id, true);

    expect($withdrawal->fresh())->status->toBe('cancelled')->cash_account_id->toBeNull();
    expect($originalMutation->fresh()->getAttributes())->toBe($originalBefore);
    $reversal = BalanceMutation::where('reference_type', 'withdrawal_cancellation')
        ->where('reference_id', $withdrawal->id)->sole();
    expect($reversal)->type->toBe('credit')->amount->toBe('100.00')->customer_id->toBe($customer->id);
    $this->assertDatabaseCount('cash_mutations', $cashMutationCount);
    $this->assertDatabaseCount('journal_entries', $journalCount);
});

test(
    'penarikan dan pembatalan menjaga saldo nasabah kas dan jurnal tetap konsisten',
    function (): void {
        $user = User::factory()->create();

        $customer = Customer::create([
            'customer_code' => 'AKT-WD-001',
            'name' => 'Nasabah Uji Akuntansi',
        ]);

        /**
         * Siapkan saldo tabungan nasabah Rp100.000.
         */
        BalanceMutation::create([
            'customer_id' => $customer->id,
            'type' => 'credit',
            'amount' => '100000.00',
            'reference_type' => 'test_opening_balance',
            'reference_id' => 990001,
            'transaction_date' => now()->toDateString(),
            'description' => 'Saldo awal pengujian penarikan',
        ]);

        /**
         * Siapkan Kas Utama dan saldo Kas Rp200.000.
         */
        $cashAccount = CashAccount::create([
            'code' => 'KAS-AKT-WD',
            'name' => 'Kas Uji Penarikan',
            'account_type' => CashAccount::TYPE_CASH,
            'is_active' => true,
        ]);

        app(CashMutationService::class)->record(
            cashAccountId: $cashAccount->id,
            transactionDate: now()->toDateString(),
            mutationType: CashMutation::TYPE_IN,
            amount: '200000.00',
            referenceType: 'opening_balance_test',
            referenceId: 990001,
            referenceNumber: 'SALDO-AWAL-UJI',
            description: 'Saldo awal Kas pengujian',
            userId: $user->id
        );

        $withdrawal = Withdrawal::create([
            'withdrawal_number' => 'WD-AKT-001',
            'customer_id' => $customer->id,
            'cash_account_id' => $cashAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => '100000.00',
            'status' => 'draft',
        ]);

        /**
         * POSTING PENARIKAN
         */
        app(WithdrawalService::class)->post(
            withdrawal: $withdrawal,
            userId: $user->id
        );

        expect($withdrawal->fresh()->status)->toBe('posted');

        $balanceMutation = BalanceMutation::query()
            ->where('reference_type', 'withdrawal')
            ->where('reference_id', $withdrawal->id)
            ->sole();

        expect($balanceMutation->type)->toBe('debit');
        expect($balanceMutation->amount)->toBe('100000.00');

        $cashOut = CashMutation::query()
            ->where('reference_type', 'withdrawal')
            ->where('reference_id', $withdrawal->id)
            ->sole();

        expect($cashOut->mutation_type)->toBe(CashMutation::TYPE_OUT);
        expect($cashOut->amount)->toBe('100000.00');

        /**
         * Saldo Kas:
         * 200.000 - 100.000 = 100.000
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('100000.00');

        $journal = JournalEntry::query()
            ->where('reference_type', 'withdrawal')
            ->where('reference_id', $withdrawal->id)
            ->with('lines.account')
            ->sole();

        $customerSavings = Account::query()
            ->where('system_key', 'customer_savings')
            ->sole();

        $cashLedger = Account::query()
            ->where('system_key', 'cash')
            ->sole();

        expect(
            $journal->lines
                ->where('account_id', $customerSavings->id)
                ->first()
                ->debit
        )->toBe('100000.00');

        expect(
            $journal->lines
                ->where('account_id', $cashLedger->id)
                ->first()
                ->credit
        )->toBe('100000.00');

        /**
         * PEMBATALAN PENARIKAN
         */
        app(WithdrawalService::class)->cancel(
            withdrawal: $withdrawal,
            reason: 'Salah input pengujian',
            userId: $user->id,
            confirmedCorrection: true
        );

        expect($withdrawal->fresh()->status)->toBe('cancelled');

        $balanceReversal = BalanceMutation::query()
            ->where('reference_type', 'withdrawal_cancellation')
            ->where('reference_id', $withdrawal->id)
            ->sole();

        expect($balanceReversal->type)->toBe('credit');
        expect($balanceReversal->amount)->toBe('100000.00');

        $cashIn = CashMutation::query()
            ->where('reference_type', 'withdrawal_cancellation')
            ->where('reference_id', $withdrawal->id)
            ->sole();

        expect($cashIn->mutation_type)->toBe(CashMutation::TYPE_IN);
        expect($cashIn->amount)->toBe('100000.00');

        /**
         * Saldo Kas kembali Rp200.000.
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('200000.00');

        $journal->refresh();

        expect($journal->status)
            ->toBe(JournalEntry::STATUS_REVERSED);

        $journalReversal = JournalEntry::query()
            ->where(
                'reference_type',
                'withdrawal_cancellation'
            )
            ->where('reference_id', $withdrawal->id)
            ->with('lines.account')
            ->sole();

        expect($journalReversal->reversal_of_id)
            ->toBe($journal->id);

        expect(
            $journalReversal->lines
                ->where('account_id', $cashLedger->id)
                ->first()
                ->debit
        )->toBe('100000.00');

        expect(
            $journalReversal->lines
                ->where('account_id', $customerSavings->id)
                ->first()
                ->credit
        )->toBe('100000.00');
    }
);
