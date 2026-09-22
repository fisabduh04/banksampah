<?php

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\Collector;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CashMutationService;
use App\Services\SalePaymentService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException(
            'Pengujian hanya boleh berjalan dalam environment testing.'
        );
    }

    config([
        'database.connections.sale_payment_accounting_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'sale_payment_accounting_test',
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
     * Pastikan akun sistem tersedia.
     */
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') === 'sale_payment_accounting_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

test(
    'pembayaran pengepul dan pembatalannya menjaga kas piutang dan jurnal tetap konsisten',
    function (): void {
        $user = User::factory()->create();

        $collector = Collector::create([
            'code' => 'P-AKT-BYR',
            'name' => 'Pengepul Uji Pembayaran',
            'is_active' => true,
        ]);

        /**
         * Penjualan sudah diposting senilai Rp100.000.
         */
        $sale = Sale::create([
            'sale_number' => 'PJ-AKT-BYR-001',
            'collector_id' => $collector->id,
            'transaction_date' => now()->toDateString(),
            'status' => Sale::STATUS_POSTED,
            'total_weight' => '10.000',
            'total_amount' => '100000.00',
            'total_cost' => '70000.00',
            'gross_profit' => '30000.00',
            'payment_status' => 'unpaid',
            'posted_at' => now(),
            'posted_by' => $user->id,
        ]);

        $cashAccount = CashAccount::create([
            'code' => 'KAS-AKT-BYR',
            'name' => 'Kas Uji Pembayaran',
            'account_type' => CashAccount::TYPE_CASH,
            'is_active' => true,
        ]);

        /**
         * CATAT PEMBAYARAN PENGEPUL.
         */
        $payment = app(SalePaymentService::class)->recordPayment(
            sale: $sale,
            amount: '100000.00',
            paymentDate: now()->toDateString(),
            paymentMethod: 'cash',
            referenceNumber: 'BUKTI-AKT-001',
            notes: 'Uji pembayaran akuntansi',
            userId: $user->id,
            idempotencyKey: (string) Str::uuid(),
            cashAccountId: $cashAccount->id
        );

        expect($payment->status)
            ->toBe(SalePayment::STATUS_POSTED);

        expect($sale->fresh()->payment_status)
            ->toBe('paid');

        /**
         * Ledger Kas harus menerima Rp100.000.
         */
        $cashIn = CashMutation::query()
            ->where('reference_type', 'sale_payment')
            ->where('reference_id', $payment->id)
            ->sole();

        expect($cashIn->mutation_type)
            ->toBe(CashMutation::TYPE_IN);

        expect($cashIn->amount)
            ->toBe('100000.00');

        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('100000.00');

        /**
         * Jurnal pembayaran:
         *
         * Debit  Kas
         * Kredit Piutang Pengepul
         */
        $journal = JournalEntry::query()
            ->where('reference_type', 'sale_payment')
            ->where('reference_id', $payment->id)
            ->with('lines.account')
            ->sole();

        expect($journal->lines)->toHaveCount(2);

        $cashLedger = Account::query()
            ->where('system_key', 'cash')
            ->sole();

        $receivable = Account::query()
            ->where('system_key', 'collector_receivable')
            ->sole();

        $cashLine = $journal->lines
            ->where('account_id', $cashLedger->id)
            ->first();

        expect($cashLine->debit)->toBe('100000.00');
        expect($cashLine->credit)->toBe('0.00');

        $receivableLine = $journal->lines
            ->where('account_id', $receivable->id)
            ->first();

        expect($receivableLine->debit)->toBe('0.00');
        expect($receivableLine->credit)->toBe('100000.00');

        /**
         * PEMBATALAN PEMBAYARAN.
         */
        app(SalePaymentService::class)->cancelPayment(
            payment: $payment,
            reason: 'Salah input pembayaran pengujian',
            userId: $user->id,
            confirmedCorrection: true
        );

        $payment->refresh();
        $journal->refresh();

        expect($payment->status)
            ->toBe(SalePayment::STATUS_CANCELLED);

        expect($sale->fresh()->payment_status)
            ->toBe('unpaid');

        /**
         * Ledger Kas menghasilkan OUT Rp100.000.
         */
        $cashOut = CashMutation::query()
            ->where(
                'reference_type',
                'sale_payment_cancellation'
            )
            ->where('reference_id', $payment->id)
            ->sole();

        expect($cashOut->mutation_type)
            ->toBe(CashMutation::TYPE_OUT);

        expect($cashOut->amount)
            ->toBe('100000.00');

        /**
         * Dampak bersih Kas kembali nol.
         */
        expect(
            app(CashMutationService::class)
                ->balance($cashAccount)
        )->toBe('0.00');

        /**
         * Jurnal asal ditandai reversed.
         */
        expect($journal->status)
            ->toBe(JournalEntry::STATUS_REVERSED);

        /**
         * Jurnal reversal:
         *
         * Debit  Piutang Pengepul
         * Kredit Kas
         */
        $reversal = JournalEntry::query()
            ->where(
                'reference_type',
                'sale_payment_cancellation'
            )
            ->where('reference_id', $payment->id)
            ->with('lines.account')
            ->sole();

        expect($reversal->reversal_of_id)
            ->toBe($journal->id);

        expect(
            $reversal->lines
                ->where('account_id', $receivable->id)
                ->first()
                ->debit
        )->toBe('100000.00');

        expect(
            $reversal->lines
                ->where('account_id', $cashLedger->id)
                ->first()
                ->credit
        )->toBe('100000.00');
    }
);
