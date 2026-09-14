<?php

use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Services\InventoryService;
use App\Services\LegacyInventoryCostReconciliationService;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/** @return array{deposits: array<int>, sale: Sale, waste: WasteType, other: WasteType, user: User} */
function legacyCostRecords(): array
{
    $user = User::factory()->financeManager()->create();
    auth()->login($user);
    $waste = WasteType::create(['code' => 'L-01', 'name' => 'Sampah A', 'is_active' => true]);
    $other = WasteType::create(['code' => 'L-02', 'name' => 'Sampah B', 'is_active' => true]);
    $customer = Customer::create(['customer_code' => 'L-01', 'name' => 'Nasabah']);
    $deposits = [];
    foreach ([[$waste, 50, 600, 30000], [$other, 4, 700, 2800]] as [$type, $weight, $price, $subtotal]) {
        $deposit = Deposit::create(['deposit_number' => 'ST-L-'.$type->id, 'customer_id' => $customer->id,
            'transaction_date' => '2026-09-11', 'status' => 'posted', 'total_weight' => $weight, 'total_amount' => $subtotal]);
        $deposit->items()->create(['waste_type_id' => $type->id, 'weight' => $weight, 'price' => $price, 'subtotal' => $subtotal]);
        InventoryMovement::create(['waste_type_id' => $type->id, 'movement_type' => 'in', 'quantity' => $weight,
            'unit_cost' => 0, 'total_cost' => 0, 'reference_type' => 'deposit', 'reference_id' => $deposit->id, 'transaction_date' => '2026-09-11']);
        $deposits[] = $deposit->id;
    }
    $collector = Collector::create(['code' => 'L-01', 'name' => 'Pengepul', 'is_active' => true]);
    $sale = Sale::create(['sale_number' => 'PJ-L-001', 'collector_id' => $collector->id, 'transaction_date' => '2026-09-11',
        'status' => 'posted', 'total_weight' => 14, 'total_amount' => 14000, 'total_cost' => 0, 'gross_profit' => 14000]);
    $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => 14, 'price' => 1000, 'subtotal' => 14000,
        'cost_price' => 0, 'cost_total' => 0, 'gross_profit' => 14000]);
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'out', 'quantity' => 14,
        'unit_cost' => 0, 'total_cost' => 0, 'reference_type' => 'sale', 'reference_id' => $sale->id, 'transaction_date' => '2026-09-11']);
    app(SalePaymentService::class)->recordPayment($sale, 14000, '2026-09-11', 'cash', null, null, $user->id, (string) Str::uuid());

    return compact('deposits', 'sale', 'waste', 'other', 'user');
}

test('pratinjau rekonsiliasi tidak menulis data', function (): void {
    ['deposits' => $deposits, 'sale' => $sale] = legacyCostRecords();
    $result = app(LegacyInventoryCostReconciliationService::class)->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama');
    expect($result)->toMatchArray(['applied' => false, 'movements' => 3, 'inventory_value' => '24400.00', 'sale_cost' => '8400.00']);
    $this->assertDatabaseCount('inventory_cost_reconciliations', 0);
    $this->assertDatabaseCount('inventory_movements', 3);
    expect($sale->refresh()->total_cost)->toBe('0.00');
});

test('koreksi mempertahankan ledger dan pembayaran asli serta mencatat snapshot sebelum sesudah', function (): void {
    ['deposits' => $deposits, 'sale' => $sale, 'waste' => $waste, 'other' => $other] = legacyCostRecords();
    $originals = InventoryMovement::orderBy('id')->get()->map(fn (InventoryMovement $movement): array => $movement->getAttributes())->all();
    $paymentBefore = $sale->payments()->sole()->getAttributes();
    $result = app(LegacyInventoryCostReconciliationService::class)->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama', true);
    expect($result['applied'])->toBeTrue();
    foreach ($originals as $original) {
        expect(InventoryMovement::findOrFail($original['id'])->getAttributes())->toBe($original);
    }
    expect($sale->payments()->sole()->getAttributes())->toBe($paymentBefore);
    expect($sale->refresh())->status->toBe('posted')->payment_status->toBe('paid')->paid_amount->toBe(14000.0)
        ->outstanding_amount->toBe(0.0)->total_cost->toBe('8400.00')->gross_profit->toBe('5600.00');
    expect($sale->items()->sole())->cost_price->toBe('600.00')->cost_total->toBe('8400.00')->gross_profit->toBe('5600.00');
    $this->assertDatabaseCount('inventory_movements', 9);
    $this->assertDatabaseCount('inventory_cost_reconciliations', 3);
    $audit = DB::table('inventory_cost_reconciliations')->where('sale_id', $sale->id)->sole();
    expect($audit)->approved_by->toBe('Pemilik proyek')->reason->toBe('Koreksi biaya lama');
    $before = json_decode($audit->source_snapshot, true);
    $after = json_decode($audit->corrected_snapshot, true);
    expect((string) $before['header']['total_cost'])->toBe('0.00');
    expect($after['header']['total_cost'])->toBe('8400.00');
    expect($before['payments'])->toHaveCount(1);
    expect(app(InventoryService::class)->getBalance($waste->id))->toMatchArray(['quantity' => 36.0, 'value' => 21600.0]);
    expect(app(InventoryService::class)->getBalance($other->id))->toMatchArray(['quantity' => 4.0, 'value' => 2800.0]);
    DB::transaction(function () use ($waste): void {
        expect(app(InventoryService::class)->getLockedBalance($waste->id))->toMatchArray(['quantity' => 36.0, 'value' => 21600.0]);
    });
});

test('pengulangan koreksi tidak menggandakan ledger atau audit', function (): void {
    ['deposits' => $deposits, 'sale' => $sale] = legacyCostRecords();
    $service = app(LegacyInventoryCostReconciliationService::class);
    $service->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama', true);
    $result = $service->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama', true);
    expect($result)->toMatchArray(['already_applied' => true, 'applied' => false, 'inventory_value' => '24400.00']);
    $this->assertDatabaseCount('inventory_movements', 9);
    $this->assertDatabaseCount('inventory_cost_reconciliations', 3);
});

test('koreksi ditolak bila ada mutasi lain yang belum diaudit', function (): void {
    ['deposits' => $deposits, 'sale' => $sale, 'waste' => $waste] = legacyCostRecords();
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => 1,
        'unit_cost' => 1, 'total_cost' => 1, 'reference_type' => 'adjustment', 'transaction_date' => '2026-09-14']);
    expect(fn () => app(LegacyInventoryCostReconciliationService::class)->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama', true))
        ->toThrow(UnexpectedValueException::class, 'di luar lingkup');
    $this->assertDatabaseCount('inventory_cost_reconciliations', 0);
    expect($sale->refresh()->total_cost)->toBe('0.00');
});

test('kegagalan di tengah koreksi merollback ledger audit dan HPP', function (): void {
    ['deposits' => $deposits, 'sale' => $sale] = legacyCostRecords();
    InventoryMovement::creating(function (InventoryMovement $movement): void {
        if ($movement->reference_type === 'cost_reconciliation' && $movement->movement_type === 'out') {
            throw new UnexpectedValueException('Simulasi kegagalan penyimpanan.');
        }
    });
    try {
        expect(fn () => app(LegacyInventoryCostReconciliationService::class)->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama', true))
            ->toThrow(UnexpectedValueException::class, 'Simulasi kegagalan');
    } finally {
        InventoryMovement::flushEventListeners();
    }
    $this->assertDatabaseCount('inventory_cost_reconciliations', 0);
    $this->assertDatabaseCount('inventory_movements', 3);
    expect($sale->refresh())->total_cost->toBe('0.00')->payment_status->toBe('paid');
});

test('pembatalan penjualan setelah rekonsiliasi mengembalikan biaya yang benar', function (): void {
    ['deposits' => $deposits, 'sale' => $sale, 'waste' => $waste, 'user' => $user] = legacyCostRecords();
    app(LegacyInventoryCostReconciliationService::class)->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama', true);
    app(SalePaymentService::class)->cancelPayment($sale->payments()->sole(), 'Koreksi transaksi', $user->id);
    app(SalePostingService::class)->cancel($sale, $user->id, 'Koreksi transaksi');
    expect(app(InventoryService::class)->getBalance($waste->id))->toMatchArray(['quantity' => 50.0, 'value' => 30000.0]);
    $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'sale_cancellation', 'total_cost' => 8400]);
});

test('perintah rekonsiliasi memakai pratinjau secara bawaan', function (): void {
    ['deposits' => $deposits, 'sale' => $sale] = legacyCostRecords();
    $this->artisan('inventory:reconcile-legacy-costs', ['--deposits' => implode(',', $deposits), '--sales' => (string) $sale->id,
        '--approved-by' => 'Pemilik proyek', '--reason' => 'Koreksi biaya lama'])
        ->expectsOutputToContain('Pratinjau')->assertSuccessful();
    $this->assertDatabaseCount('inventory_cost_reconciliations', 0);
});

test('detail penjualan menampilkan alasan persetujuan dan perubahan HPP', function (): void {
    ['deposits' => $deposits, 'sale' => $sale, 'user' => $user] = legacyCostRecords();
    $this->actingAs($user);
    app(LegacyInventoryCostReconciliationService::class)->reconcile($deposits, [$sale->id], 'Pemilik proyek', 'Koreksi biaya lama', true);
    Livewire::test(ViewSale::class, ['record' => $sale->id])
        ->assertSee('Riwayat Koreksi HPP')->assertSee('Koreksi biaya lama')->assertSee('Pemilik proyek')->assertSee('8.400,00');
});
