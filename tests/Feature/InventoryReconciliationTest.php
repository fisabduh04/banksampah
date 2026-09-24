<?php

use App\Models\Account;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Services\DepositService;
use App\Services\ReconciliationService;
use App\Services\SalePostingService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }

    config([
        'database.connections.inventory_reconciliation_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'inventory_reconciliation_test',
    ]);

    $connection = DB::connection();
    if (
        $connection->getDriverName() !== 'mysql'
        || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing'
    ) {
        throw new RuntimeException('Pengujian hanya boleh memakai MySQL banksampah_testing.');
    }

    $connection->beginTransaction();
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') === 'inventory_reconciliation_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

function inventoryReconciliationDeposit(): Deposit
{
    $customer = Customer::query()->create([
        'customer_code' => 'IR-'.Str::ulid(), 'name' => 'Nasabah Uji Persediaan',
    ]);
    $waste = WasteType::query()->create([
        'code' => 'IR-'.Str::ulid(), 'name' => 'Bahan Uji Persediaan', 'is_active' => true,
    ]);
    $deposit = Deposit::query()->create([
        'deposit_number' => 'IR-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-08-30', 'status' => 'draft',
        'total_weight' => '10.000', 'total_amount' => '100.00',
    ]);
    $deposit->items()->create([
        'waste_type_id' => $waste->id, 'weight' => '10.000',
        'price' => '10.00', 'subtotal' => '100.00',
    ]);
    app(DepositService::class)->post($deposit);

    return $deposit;
}

/** @param array<string, mixed> $attributes */
function inventoryReconciliationMovement(array $attributes = []): InventoryMovement
{
    $waste = WasteType::query()->create([
        'code' => 'IR-'.Str::ulid(), 'name' => 'Bahan Mutasi Uji', 'is_active' => false,
    ]);

    return InventoryMovement::query()->create([
        'waste_type_id' => $waste->id, 'movement_type' => 'in',
        'quantity' => '1.000', 'unit_cost' => '1.00', 'total_cost' => '1.00',
        'transaction_date' => '2026-08-30', 'reference_type' => 'inventory_reconciliation_test',
        ...$attributes,
    ]);
}

test('setoran penjualan dan pembatalan lintas periode direkonsiliasi menurut tanggal masing-masing', function (): void {
    $this->travelTo(now()->setDate(2026, 8, 31)->setTime(10, 0));
    $user = User::factory()->create();
    $deposit = inventoryReconciliationDeposit();
    $collector = Collector::query()->create([
        'code' => 'IR-'.Str::ulid(), 'name' => 'Pengepul Uji Persediaan', 'is_active' => true,
    ]);
    $sale = Sale::query()->create([
        'sale_number' => 'IR-'.Str::ulid(), 'collector_id' => $collector->id,
        'transaction_date' => '2026-08-31', 'status' => Sale::STATUS_DRAFT,
        'total_weight' => '4.000', 'total_amount' => '60.00', 'payment_status' => 'unpaid',
    ]);
    $sale->items()->create([
        'waste_type_id' => $deposit->items()->sole()->waste_type_id,
        'weight' => '4.000', 'price' => '15.00', 'subtotal' => '60.00',
    ]);
    app(SalePostingService::class)->post($sale, $user->id);
    $this->travelTo(now()->setDate(2026, 9, 1));
    app(SalePostingService::class)->cancel($sale, $user->id, 'Koreksi penjualan uji', true);
    $this->travelTo(now()->setDate(2026, 9, 2));
    app(DepositService::class)->cancel($deposit, 'Koreksi setoran uji', $user->id, true);
    $account = Account::query()->where('system_key', 'inventory')->sole();
    $account->update(['is_active' => false, 'is_postable' => false]);
    $service = app(ReconciliationService::class);

    $reports = [];
    foreach (['2026-08-29', '2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02'] as $date) {
        $reports[$date] = $service->inventoryAsOf($date);
    }

    foreach (['2026-08-29' => '0.00', '2026-08-30' => '100.00', '2026-08-31' => '60.00',
        '2026-09-01' => '100.00', '2026-09-02' => '0.00'] as $date => $balance) {
        expect($reports[$date])->toBe([
            'as_of_date' => $date, 'account_id' => $account->id, 'account_code' => $account->code,
            'gl_balance' => $balance, 'inventory_balance' => $balance, 'difference' => '0.00', 'balanced' => true,
        ]);
    }
    expect(JournalEntry::query()->where('reference_type', 'sale')->where('reference_id', $sale->id)->sole()->status)
        ->toBe(JournalEntry::STATUS_REVERSED);
});

test('koreksi biaya historis nol dihitung tanpa mengubah sumber dan tetap cocok sesudah pembatalan', function (string $quantity): void {
    $this->travelTo(now()->setDate(2026, 9, 2)->setTime(10, 0));
    $deposit = inventoryReconciliationDeposit();
    $source = InventoryMovement::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sole();
    /** Simulasi histori tanpa biaya, mengikuti fixture DepositCancellationTest. */
    $source->update(['unit_cost' => '0.00', 'total_cost' => '0.00']);
    foreach (['cost_reconciliation_reversal' => ['out', '0.00'], 'cost_reconciliation' => ['in', '100.00']] as $reference => [$type, $cost]) {
        InventoryMovement::query()->create([
            'waste_type_id' => $source->waste_type_id, 'movement_type' => $type,
            'quantity' => $quantity, 'unit_cost' => $cost === '0.00' ? '0.00' : '10.00', 'total_cost' => $cost,
            'reference_type' => $reference, 'reference_id' => $source->id, 'transaction_date' => '2026-09-01',
        ]);
    }
    app(DepositService::class)->cancel($deposit, 'Koreksi setoran historis uji', User::factory()->create()->id, true);
    $service = app(ReconciliationService::class);

    $before = $service->inventoryAsOf('2026-08-31');
    $corrected = $service->inventoryAsOf('2026-09-01');
    $cancelled = $service->inventoryAsOf('2026-09-02');

    expect($before['inventory_balance'])->toBe('0.00');
    expect($before['gl_balance'])->toBe('100.00');
    expect($before['difference'])->toBe('-100.00');
    expect($before['balanced'])->toBeFalse();
    expect($corrected['inventory_balance'])->toBe('100.00');
    expect($corrected['difference'])->toBe('0.00');
    expect($corrected['balanced'])->toBeTrue();
    expect($cancelled['inventory_balance'])->toBe('0.00');
    expect($cancelled['gl_balance'])->toBe('0.00');
    expect($cancelled['balanced'])->toBeTrue();
    expect($source->fresh()->total_cost)->toBe('0.00');
})->with(['koreksi nilai saja' => '0.000', 'pembalik dan pengganti penuh' => '10.000']);

test('nilai snapshot dan koreksi tanpa jurnal menghasilkan selisih tepat tanpa penulisan data', function (): void {
    $source = inventoryReconciliationMovement(['quantity' => '3.000', 'unit_cost' => '33.33', 'total_cost' => '100.00']);
    foreach (['cost_reconciliation_reversal' => ['out', '100.00'], 'cost_reconciliation' => ['in', '100.01']] as $reference => [$type, $cost]) {
        InventoryMovement::query()->create([
            'waste_type_id' => $source->waste_type_id, 'movement_type' => $type,
            'quantity' => '0.000', 'unit_cost' => '0.00', 'total_cost' => $cost,
            'reference_type' => $reference, 'reference_id' => $source->id, 'transaction_date' => '2026-09-01',
        ]);
    }
    inventoryReconciliationMovement(['movement_type' => 'invalid', 'total_cost' => '-1.00', 'transaction_date' => '2026-09-02']);
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    try {
        $report = app(ReconciliationService::class)->inventoryAsOf('2026-09-01');
        $queries = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    expect($report['inventory_balance'])->toBe('100.01');
    expect($report['gl_balance'])->toBe('0.00');
    expect($report['difference'])->toBe('100.01');
    expect($report['balanced'])->toBeFalse();
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
});

test('mutasi persediaan tidak valid ditolak sebelum menghasilkan laporan', function (array $attributes): void {
    inventoryReconciliationMovement($attributes);

    expect(fn () => app(ReconciliationService::class)->inventoryAsOf('2026-09-01'))
        ->toThrow(RuntimeException::class, 'Terdapat mutasi persediaan dengan jenis, kuantitas, atau biaya tidak valid.');
})->with([
    'jenis asing' => [['movement_type' => 'adjustment']],
    'jenis kapital' => [['movement_type' => 'IN']],
    'kuantitas negatif' => [['quantity' => '-0.001']],
    'biaya satuan negatif' => [['unit_cost' => '-0.01']],
    'nilai negatif' => [['total_cost' => '-0.01']],
]);

test('tanggal rekonsiliasi persediaan wajib valid', function (string $date): void {
    expect(fn () => app(ReconciliationService::class)->inventoryAsOf($date))
        ->toThrow(InvalidArgumentException::class, 'Tanggal laporan tidak valid.');
})->with(['tanggal-salah', '2026-02-30', '01-09-2026', '']);
