<?php

use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Collector;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\WasteType;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('table action cancels a sale and restores its original inventory cost', function (): void {
    $user = User::factory()->financeManager()->create();
    $this->actingAs($user);
    $this->freezeTime();
    $collector = Collector::create(['code' => 'COL-001', 'name' => 'Pengepul']);
    $wasteType = WasteType::create(['code' => 'PET', 'name' => 'Botol']);
    $sale = Sale::create([
        'sale_number' => 'SALE-001', 'collector_id' => $collector->id,
        'transaction_date' => '2026-09-01', 'status' => Sale::STATUS_POSTED,
        'total_weight' => 2, 'total_amount' => 10000, 'total_cost' => 6000,
    ]);
    $sale->items()->create([
        'waste_type_id' => $wasteType->id, 'weight' => 2,
        'price' => 5000, 'subtotal' => 10000, 'cost_price' => 3000, 'cost_total' => 6000,
    ]);

    Livewire::test(ListSales::class)
        ->callAction(TestAction::make('batalkanPenjualan')->table($sale), data: ['reason' => 'Transaksi keliru'])
        ->assertNotified('Penjualan berhasil dibatalkan');

    $this->assertDatabaseHas('sales', [
        'id' => $sale->id, 'status' => Sale::STATUS_CANCELLED,
        'cancelled_by' => $user->id, 'cancellation_reason' => 'Transaksi keliru',
        'total_amount' => 10000, 'total_cost' => 6000,
    ]);
    $this->assertDatabaseHas('inventory_movements', [
        'reference_type' => 'sale_cancellation', 'reference_id' => $sale->id,
        'waste_type_id' => $wasteType->id, 'movement_type' => 'in',
        'quantity' => 2, 'unit_cost' => 3000, 'total_cost' => 6000,
    ]);
    $this->assertDatabaseCount('inventory_movements', 1);
});

test('table action rejects cancellation while a sale has active payments', function (): void {
    $this->actingAs(User::factory()->financeManager()->create());
    $collector = Collector::create(['code' => 'COL-001', 'name' => 'Pengepul']);
    $sale = Sale::create([
        'sale_number' => 'SALE-001', 'collector_id' => $collector->id,
        'transaction_date' => '2026-09-01', 'status' => Sale::STATUS_POSTED,
        'total_amount' => 10000,
    ]);
    $payment = $sale->payments()->create([
        'payment_number' => 'PAY-001', 'payment_date' => '2026-09-01',
        'amount' => 5000, 'payment_method' => 'cash', 'status' => SalePayment::STATUS_POSTED,
    ]);

    Livewire::test(ListSales::class)
        ->callAction(TestAction::make('batalkanPenjualan')->table($sale), data: ['reason' => 'Transaksi keliru'])
        ->assertNotified('Penjualan tidak dapat dibatalkan');

    $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => Sale::STATUS_POSTED, 'cancelled_at' => null]);
    $this->assertDatabaseHas('sale_payments', ['id' => $payment->id, 'status' => SalePayment::STATUS_POSTED]);
    $this->assertDatabaseCount('inventory_movements', 0);
});
