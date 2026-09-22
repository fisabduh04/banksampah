<?php

use App\Models\Account;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Services\DepositService;
use App\Services\SalePostingService;
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
        'database.connections.sale_accounting_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'sale_accounting_test',
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

    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') === 'sale_accounting_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

test(
    'penjualan dan pembatalan menghasilkan jurnal penjualan hpp dan reversal yang seimbang',
    function (): void {
        $user = User::factory()->create();

        $customer = Customer::create([
            'customer_code' => 'AKT-SALE-001',
            'name' => 'Nasabah Uji Penjualan',
        ]);

        $waste = WasteType::create([
            'code' => 'AKT-SALE',
            'name' => 'Sampah Uji Penjualan',
            'is_active' => true,
        ]);

        /**
         * Siapkan persediaan:
         * 10 kg × Rp10 = Rp100.
         */
        $deposit = Deposit::create([
            'deposit_number' => 'DP-AKT-SALE-001',
            'customer_id' => $customer->id,
            'transaction_date' => now()->toDateString(),
            'total_weight' => '10.000',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);

        $deposit->items()->create([
            'waste_type_id' => $waste->id,
            'weight' => '10.000',
            'price' => '10.00',
            'subtotal' => '100.00',
        ]);

        app(DepositService::class)->post(
            deposit: $deposit,
            userId: $user->id
        );

        $collector = Collector::create([
            'code' => 'P-AKT-SALE',
            'name' => 'Pengepul Uji Akuntansi',
            'is_active' => true,
        ]);

        /**
         * Jual 10 kg × Rp15 = Rp150.
         * HPP berasal dari persediaan = Rp100.
         */
        $sale = Sale::create([
            'sale_number' => 'PJ-AKT-SALE-001',
            'collector_id' => $collector->id,
            'transaction_date' => now()->toDateString(),
            'status' => Sale::STATUS_DRAFT,
            'total_weight' => '10.000',
            'total_amount' => '150.00',
            'payment_status' => 'unpaid',
        ]);

        $sale->items()->create([
            'waste_type_id' => $waste->id,
            'weight' => '10.000',
            'price' => '15.00',
            'subtotal' => '150.00',
        ]);

        /**
         * POSTING PENJUALAN.
         */
        app(SalePostingService::class)->post(
            sale: $sale,
            userId: $user->id
        );

        $sale->refresh();

        expect($sale->status)->toBe(Sale::STATUS_POSTED);
        expect($sale->total_amount)->toBe('150.00');
        expect($sale->total_cost)->toBe('100.00');
        expect($sale->gross_profit)->toBe('50.00');

        $journal = JournalEntry::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->with('lines.account')
            ->sole();

        expect($journal->lines)->toHaveCount(4);

        $receivable = Account::where(
            'system_key',
            'collector_receivable'
        )->sole();

        $revenue = Account::where(
            'system_key',
            'sales_revenue'
        )->sole();

        $cogs = Account::where(
            'system_key',
            'cogs'
        )->sole();

        $inventory = Account::where(
            'system_key',
            'inventory'
        )->sole();

        /**
         * Debit Piutang Rp150.
         */
        $receivableLine = $journal->lines
            ->where('account_id', $receivable->id)
            ->first();

        expect($receivableLine->debit)->toBe('150.00');
        expect($receivableLine->credit)->toBe('0.00');

        /**
         * Kredit Pendapatan Rp150.
         */
        $revenueLine = $journal->lines
            ->where('account_id', $revenue->id)
            ->first();

        expect($revenueLine->debit)->toBe('0.00');
        expect($revenueLine->credit)->toBe('150.00');

        /**
         * Debit HPP Rp100.
         */
        $cogsLine = $journal->lines
            ->where('account_id', $cogs->id)
            ->first();

        expect($cogsLine->debit)->toBe('100.00');
        expect($cogsLine->credit)->toBe('0.00');

        /**
         * Kredit Persediaan Rp100.
         */
        $inventoryLine = $journal->lines
            ->where('account_id', $inventory->id)
            ->first();

        expect($inventoryLine->debit)->toBe('0.00');
        expect($inventoryLine->credit)->toBe('100.00');

        /**
         * Total jurnal harus balance.
         */
        expect(
            $journal->lines->sum(
                fn ($line) => (float) $line->debit
            )
        )->toBe(
            $journal->lines->sum(
                fn ($line) => (float) $line->credit
            )
        );

        /**
         * PEMBATALAN PENJUALAN.
         */
        app(SalePostingService::class)->cancel(
            sale: $sale,
            userId: $user->id,
            reason: 'Salah input pengujian',
            confirmedCorrection: true
        );

        $sale->refresh();
        $journal->refresh();

        expect($sale->status)->toBe(Sale::STATUS_CANCELLED);

        expect($journal->status)
            ->toBe(JournalEntry::STATUS_REVERSED);

        $reversal = JournalEntry::query()
            ->where('reference_type', 'sale_cancellation')
            ->where('reference_id', $sale->id)
            ->with('lines.account')
            ->sole();

        expect($reversal->reversal_of_id)
            ->toBe($journal->id);

        expect($reversal->lines)->toHaveCount(4);

        /**
         * Reversal:
         *
         * Debit Pendapatan Rp150
         * Kredit Piutang Rp150
         * Debit Persediaan Rp100
         * Kredit HPP Rp100
         */
        expect(
            $reversal->lines
                ->where('account_id', $revenue->id)
                ->first()
                ->debit
        )->toBe('150.00');

        expect(
            $reversal->lines
                ->where('account_id', $receivable->id)
                ->first()
                ->credit
        )->toBe('150.00');

        expect(
            $reversal->lines
                ->where('account_id', $inventory->id)
                ->first()
                ->debit
        )->toBe('100.00');

        expect(
            $reversal->lines
                ->where('account_id', $cogs->id)
                ->first()
                ->credit
        )->toBe('100.00');
    }
);
