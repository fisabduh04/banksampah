<?php

use App\Filament\Resources\InventoryMovements\InventoryMovementResource;
use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\WasteType;
use App\Services\DepositService;
use App\Services\InventoryService;
use App\Services\SaleDraftService;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/** @return array{sale: Sale, deposit: Deposit, waste: WasteType, user: User} */
function prepareSaleWorkflow(): array
{
    $user = User::factory()->financeManager()->create();
    $waste = WasteType::create(['code' => 'PET', 'name' => 'PET', 'is_active' => true]);
    $customer = Customer::create(['customer_code' => 'NS-001', 'name' => 'Nasabah']);
    $deposit = Deposit::create(['deposit_number' => 'ST-001', 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'status' => 'draft', 'total_weight' => 25, 'total_amount' => 150000]);
    $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => 25, 'price' => 6000, 'subtotal' => 150000]);
    app(DepositService::class)->post($deposit, $user->id);
    $collector = Collector::create(['code' => 'PG-001', 'name' => 'Pengepul', 'is_active' => true]);
    $sale = Sale::create(['sale_number' => 'PJ-001', 'collector_id' => $collector->id,
        'transaction_date' => '2026-09-14', 'status' => 'draft', 'total_weight' => 5, 'total_amount' => 140000]);
    $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => 5, 'price' => 28000, 'subtotal' => 140000]);

    return compact('sale', 'deposit', 'waste', 'user');
}

function paySaleWorkflow(Sale $sale, User $user, float $amount): SalePayment
{
    return app(SalePaymentService::class)->recordPayment($sale, $amount, '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid());
}

test('alur PET menjaga HPP piutang reversal dan seluruh histori', function (): void {
    $this->travelTo(now()->startOfSecond());
    ['sale' => $sale, 'waste' => $waste, 'user' => $user] = prepareSaleWorkflow();
    $inventory = app(InventoryService::class);
    $posting = app(SalePostingService::class);
    $payments = app(SalePaymentService::class);
    expect($inventory->getBalance($waste->id))->toMatchArray(['quantity' => 25.0, 'value' => 150000.0]);
    $posting->post($sale, $user->id);
    expect($sale)->status->toBe('posted')->total_amount->toBe('140000.00')
        ->total_cost->toBe('30000.00')->gross_profit->toBe('110000.00')->posted_by->toBe($user->id);
    expect($sale->posted_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'cost_price' => 6000, 'cost_total' => 30000, 'gross_profit' => 110000]);
    $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'sale', 'reference_id' => $sale->id,
        'movement_type' => 'out', 'quantity' => 5, 'unit_cost' => 6000, 'total_cost' => 30000]);
    expect($inventory->getBalance($waste->id))->toMatchArray(['quantity' => 20.0, 'value' => 120000.0]);
    $first = paySaleWorkflow($sale, $user, 40000);
    expect($sale->refresh())->payment_status->toBe('partial')->paid_amount->toBe(40000.0)->outstanding_amount->toBe(100000.0);
    $last = paySaleWorkflow($sale, $user, 100000);
    expect($sale->refresh()->payment_status)->toBe('paid');
    expect($first->payment_number)->toMatch('/^BYR-20260914-[0-9]{6}$/');
    expect((int) Str::afterLast($first->payment_number, '-'))->toBe($first->id);
    $payments->cancelPayment($last, 'Koreksi pelunasan', $user->id);
    expect($sale->refresh())->payment_status->toBe('partial')->paid_amount->toBe(40000.0)->outstanding_amount->toBe(100000.0);
    expect(fn () => $posting->cancel($sale, $user->id, 'Koreksi penjualan'))->toThrow(RuntimeException::class, 'pembayaran aktif sebesar Rp 40.000');
    $payments->cancelPayment($first, 'Koreksi pembayaran', $user->id);
    expect($sale->refresh()->payment_status)->toBe('unpaid');
    expect($inventory->getBalance($waste->id))->toMatchArray(['quantity' => 20.0, 'value' => 120000.0]);
    $posting->cancel($sale, $user->id, 'Koreksi penjualan');
    expect($sale)->status->toBe('cancelled')->cancelled_by->toBe($user->id)->cancellation_reason->toBe('Koreksi penjualan');
    expect($inventory->getBalance($waste->id))->toMatchArray(['quantity' => 25.0, 'value' => 150000.0]);
    $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'sale_cancellation', 'reference_id' => $sale->id,
        'movement_type' => 'in', 'quantity' => 5, 'unit_cost' => 6000, 'total_cost' => 30000]);
    $this->assertDatabaseHas('sale_payments', ['id' => $last->id, 'status' => 'cancelled',
        'cancelled_by' => $user->id, 'cancellation_reason' => 'Koreksi pelunasan']);
    expect($last->refresh()->cancelled_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseCount('sale_payments', 2);
    $this->assertDatabaseCount('sales', 1);
    $this->assertDatabaseCount('inventory_movements', 3);
    expect(fn () => $posting->cancel($sale, $user->id, 'Ulang'))->toThrow(RuntimeException::class);
    expect(fn () => $payments->cancelPayment($last, 'Ulang', $user->id))->toThrow(RuntimeException::class);
});

test('posting ulang dan posting transaksi dibatalkan ditolak', function (string $status): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $sale->update(['status' => $status]);
    expect(fn () => app(SalePostingService::class)->post($sale, $user->id))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('inventory_movements', 1);
})->with(['posted', 'cancelled']);

test('posting menolak rincian tidak sah tanpa mutasi tambahan', function (string $field, float $value): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $sale->items()->update([$field => $value]);
    expect(fn () => app(SalePostingService::class)->post($sale, $user->id))->toThrow(RuntimeException::class);
    expect($sale->refresh()->status)->toBe('draft');
    $this->assertDatabaseCount('inventory_movements', 1);
})->with([['weight', 0], ['price', 0], ['subtotal', 1]]);

test('item kedua gagal membuat seluruh posting dirollback', function (): void {
    ['sale' => $sale, 'user' => $user, 'waste' => $waste] = prepareSaleWorkflow();
    $other = WasteType::create(['code' => 'KRD', 'name' => 'Kardus', 'is_active' => true]);
    $sale->items()->create(['waste_type_id' => $other->id, 'weight' => 1, 'price' => 1000, 'subtotal' => 1000]);
    expect(fn () => app(SalePostingService::class)->post($sale, $user->id))->toThrow(RuntimeException::class, 'Stok tidak mencukupi');
    expect($sale->refresh())->status->toBe('draft')->total_cost->toBe('0.00');
    expect($sale->items()->first()->cost_total)->toBe('0.00');
    expect(app(InventoryService::class)->getBalance($waste->id)['quantity'])->toBe(25.0);
    $this->assertDatabaseCount('inventory_movements', 1);
});

test('posting menolak master nonaktif dan item kosong', function (string $invalid): void {
    ['sale' => $sale, 'user' => $user, 'waste' => $waste] = prepareSaleWorkflow();
    match ($invalid) {
        'collector' => $sale->collector()->update(['is_active' => false]),
        'waste' => $waste->update(['is_active' => false]),
        'empty' => $sale->items()->delete(),
    };
    expect(fn () => app(SalePostingService::class)->post($sale, $user->id))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('inventory_movements', 1);
})->with(['collector', 'waste', 'empty']);

test('posting menghitung ulang header dari rincian', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $sale->update(['total_amount' => 1, 'total_weight' => 1]);
    app(SalePostingService::class)->post($sale, $user->id);
    expect($sale)->total_amount->toBe('140000.00')->total_weight->toBe('5.000');
});

test('pembayaran nol negatif terlalu kecil dan kelebihan satu sen ditolak', function (float $amount): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    app(SalePostingService::class)->post($sale, $user->id);
    expect(fn () => paySaleWorkflow($sale, $user, $amount))->toThrow(RuntimeException::class);
    expect($sale->refresh()->payment_status)->toBe('unpaid');
    $this->assertDatabaseCount('sale_payments', 0);
})->with([0, -1, 0.004, 140000.01, 150000]);

test('sisa satu sen tetap partial dan pembayaran tambahan setelah lunas ditolak', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    app(SalePostingService::class)->post($sale, $user->id);
    paySaleWorkflow($sale, $user, 139999.99);
    expect($sale->refresh())->payment_status->toBe('partial')->outstanding_amount->toBe(0.01);
    paySaleWorkflow($sale, $user, 0.01);
    expect($sale->refresh()->payment_status)->toBe('paid');
    expect(fn () => paySaleWorkflow($sale, $user, 1))->toThrow(RuntimeException::class, 'sudah lunas');
    $this->assertDatabaseCount('sale_payments', 2);
});

test('pembayaran draft dan cancelled ditolak', function (string $status): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $sale->update(['status' => $status]);
    expect(fn () => paySaleWorkflow($sale, $user, 1000))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('sale_payments', 0);
})->with(['draft', 'cancelled']);

test('alasan pembatalan wajib untuk penjualan dan pembayaran', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    app(SalePostingService::class)->post($sale, $user->id);
    $payment = paySaleWorkflow($sale, $user, 1000);
    expect(fn () => app(SalePaymentService::class)->cancelPayment($payment, '  ', $user->id))->toThrow(RuntimeException::class);
    expect(fn () => app(SalePostingService::class)->cancel($sale, $user->id, '  '))->toThrow(RuntimeException::class);
    expect($payment->refresh()->status)->toBe('posted');
    expect($sale->refresh()->status)->toBe('posted');
});

test('pembatalan setoran mengeluarkan biaya asal dan menolak stok yang sudah terjual', function (): void {
    ['sale' => $sale, 'deposit' => $deposit, 'waste' => $waste, 'user' => $user] = prepareSaleWorkflow();
    $staleDeposit = $deposit->fresh();
    expect(fn () => app(DepositService::class)->post($staleDeposit, $user->id))->toThrow(RuntimeException::class);
    app(SalePostingService::class)->post($sale, $user->id);
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Koreksi setoran', $user->id))->toThrow(RuntimeException::class, 'Stok tidak mencukupi');
    expect($deposit->refresh()->status)->toBe('posted');
    $this->assertDatabaseCount('balance_mutations', 1);
    app(SalePostingService::class)->cancel($sale, $user->id, 'Koreksi');
    app(DepositService::class)->cancel($deposit, 'Koreksi setoran', $user->id);
    expect(app(InventoryService::class)->getBalance($waste->id))->toMatchArray(['quantity' => 0.0, 'value' => 0.0]);
    $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'deposit_cancellation',
        'movement_type' => 'out', 'quantity' => 25, 'total_cost' => 150000]);
    expect(fn () => app(DepositService::class)->cancel($staleDeposit, 'Koreksi setoran', $user->id))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('balance_mutations', 2);
});

test('pengeluaran seluruh stok menghabiskan nilai tanpa residual', function (): void {
    $waste = WasteType::create(['code' => 'PET', 'name' => 'PET']);
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in',
        'quantity' => 3, 'unit_cost' => 0.33, 'total_cost' => 1, 'transaction_date' => '2026-09-14']);
    DB::transaction(function () use ($waste): void {
        $inventory = app(InventoryService::class);
        $first = $inventory->issue($waste->id, 1, 'sale', 1, '2026-09-14', 'Penjualan');
        $last = $inventory->issue($waste->id, 2, 'sale', 2, '2026-09-14', 'Penjualan');
        expect($first->total_cost)->toBe('0.33');
        expect($last->total_cost)->toBe('0.67');
        expect($inventory->getBalance($waste->id))->toMatchArray(['quantity' => 0.0, 'value' => 0.0]);
        expect(fn () => $inventory->issue($waste->id, 0.001, 'sale', 3, '2026-09-14', 'Penjualan'))->toThrow(RuntimeException::class);
    });
});

test('aksi tabel dan histori pembayaran menjalankan workflow pembayaran yang benar', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $this->actingAs($user);
    app(SalePostingService::class)->post($sale, $user->id);
    Livewire::test(ListSales::class)->callAction(TestAction::make('catatPembayaran')->table($sale), data: [
        'payment_date' => '2026-09-14', 'amount' => 1000, 'payment_method' => 'cash',
    ])->assertNotified('Pembayaran berhasil dicatat');
    $payment = $sale->payments()->sole();
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $sale->fresh(), 'pageClass' => ViewSale::class])
        ->callAction(TestAction::make('batalkanPembayaran')->table($payment), data: ['reason' => 'Koreksi'])
        ->assertNotified('Pembayaran berhasil dibatalkan');
    expect($sale->refresh())->status->toBe('posted')->payment_status->toBe('unpaid');
    expect($payment->refresh()->status)->toBe('cancelled');
    $this->assertDatabaseCount('inventory_movements', 2);
});

test('transaksi final tidak dapat diedit atau dihapus melalui resource maupun service draft', function (string $status): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $this->actingAs($user);
    $sale->update(['status' => $status]);
    expect(SaleResource::canEdit($sale))->toBeFalse();
    expect(SaleResource::canDelete($sale))->toBeFalse();
    Livewire::test(EditSale::class, ['record' => $sale->id])->assertForbidden();
    expect(fn () => app(SaleDraftService::class)->delete($sale))->toThrow(ValidationException::class);
    $this->assertModelExists($sale);
    Livewire::test(ViewSale::class, ['record' => $sale->id])->assertSee('Detail Penjualan');
})->with(['posted', 'cancelled']);

test('policy update menolak action khusus penjualan', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $this->actingAs($user);
    app(SalePostingService::class)->post($sale, $user->id);
    Gate::before(fn ($user, string $ability): ?bool => $ability === 'update' ? false : null);
    Livewire::test(ListSales::class)
        ->assertActionHidden(TestAction::make('catatPembayaran')->table($sale))
        ->assertActionHidden(TestAction::make('batalkanPenjualan')->table($sale));
    $this->assertDatabaseCount('sale_payments', 0);
});

test('tabel penjualan tidak melakukan query tambahan untuk accessor piutang', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    app(SalePostingService::class)->post($sale, $user->id);
    paySaleWorkflow($sale, $user, 1000);
    $records = SaleResource::getEloquentQuery()->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($records->first())->paid_amount->toBe(1000.0)->outstanding_amount->toBe(139000.0);
    expect(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();
});

test('form membuat nomor otomatis dan menghitung ulang angka browser', function (): void {
    ['sale' => $existingSale, 'user' => $user, 'waste' => $waste] = prepareSaleWorkflow();
    $this->actingAs($user);
    Livewire::test(CreateSale::class)->fillForm([
        'collector_id' => $existingSale->collector_id, 'transaction_date' => '2026-09-14',
        'due_date' => '2026-09-30', 'total_amount' => 1, 'total_weight' => 999,
        'items' => [['waste_type_id' => $waste->id, 'weight' => 5, 'price' => 28000, 'subtotal' => 1]],
    ])->call('create')->assertHasNoFormErrors();
    $sale = Sale::query()->latest('id')->first();
    expect($sale->sale_number)->toMatch('/^PJ-20260914-[0-9]{6}$/');
    expect((int) Str::afterLast($sale->sale_number, '-'))->toBe($sale->id);
    expect($sale)->status->toBe('draft')
        ->total_amount->toBe('140000.00')->total_weight->toBe('5.000')->total_cost->toBe('0.00');
    expect($sale->due_date->toDateString())->toBe('2026-09-30');
    expect($sale->items()->sole()->subtotal)->toBe('140000.00');
    $this->assertDatabaseCount('inventory_movements', 1);
});

test('form draft menyimpan perubahan rincian dan menolak simpan setelah posting', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $this->actingAs($user);
    $component = Livewire::test(EditSale::class, ['record' => $sale->id]);
    $itemKey = array_key_first($component->get('data.items'));
    $component->fillForm(['items.'.$itemKey.'.weight' => 2, 'items.'.$itemKey.'.subtotal' => 1])
        ->call('save')->assertHasNoFormErrors();
    expect($sale->refresh())->total_amount->toBe('56000.00')->total_weight->toBe('2.000');
    $stale = Livewire::test(EditSale::class, ['record' => $sale->id]);
    $stale->fillForm(['notes' => 'Tidak boleh tersimpan']);
    app(SalePostingService::class)->post($sale, $user->id);
    $stale->call('save')->assertForbidden();
    expect($sale->refresh())->status->toBe('posted')->notes->toBeNull();
});

test('draft dapat dihapus terkendali tanpa menyentuh persediaan', function (): void {
    ['sale' => $sale] = prepareSaleWorkflow();
    app(SaleDraftService::class)->delete($sale);
    $this->assertModelMissing($sale);
    $this->assertDatabaseCount('sale_items', 0);
    $this->assertDatabaseCount('inventory_movements', 1);
});

test('tabel dan histori tidak menawarkan aksi destruktif generik', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $this->actingAs($user);
    Livewire::test(ListSales::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($sale))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $sale, 'pageClass' => ViewSale::class])
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('associate')->table())
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});

test('reversal memakai biaya asal meskipun rata-rata persediaan sudah berubah', function (): void {
    ['sale' => $sale, 'waste' => $waste, 'user' => $user] = prepareSaleWorkflow();
    app(SalePostingService::class)->post($sale, $user->id);
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in',
        'quantity' => 20, 'unit_cost' => 10000, 'total_cost' => 200000, 'transaction_date' => '2026-09-14']);
    app(SalePostingService::class)->cancel($sale, $user->id, 'Koreksi');
    expect(app(InventoryService::class)->getBalance($waste->id))->toMatchArray(['quantity' => 45.0, 'value' => 350000.0]);
    $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'sale_cancellation', 'total_cost' => 30000]);
});

test('tabel ledger menampilkan saldo berjalan dan biaya tanpa aksi mutasi manual', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    $this->actingAs($user);
    app(SalePostingService::class)->post($sale, $user->id);
    Livewire::test(ListInventoryMovements::class)
        ->assertCanSeeTableRecords(InventoryMovement::all());
    expect(InventoryMovementResource::canCreate())->toBeFalse();
});

test('form menolak harga nol dan jenis sampah berulang', function (): void {
    ['sale' => $sale, 'user' => $user, 'waste' => $waste] = prepareSaleWorkflow();
    $this->actingAs($user);
    Livewire::test(CreateSale::class)->fillForm([
        'collector_id' => $sale->collector_id, 'transaction_date' => '2026-09-14',
        'items' => [
            ['waste_type_id' => $waste->id, 'weight' => 5, 'price' => 0],
            ['waste_type_id' => $waste->id, 'weight' => 5, 'price' => 28000],
        ],
    ])->call('create')->assertHasFormErrors();
    $this->assertDatabaseCount('sales', 1);
});

test('posting ditolak jika ledger setoran lama belum mempunyai biaya perolehan', function (): void {
    ['sale' => $sale, 'user' => $user] = prepareSaleWorkflow();
    InventoryMovement::query()->where('reference_type', 'deposit')->update(['unit_cost' => 0, 'total_cost' => 0]);
    expect(fn () => app(SalePostingService::class)->post($sale, $user->id))
        ->toThrow(RuntimeException::class, 'Biaya perolehan setoran lama belum lengkap');
    expect($sale->refresh()->status)->toBe('draft');
    $this->assertDatabaseCount('inventory_movements', 1);
});
