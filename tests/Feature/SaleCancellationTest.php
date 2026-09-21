<?php

use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\RelationManagers\PaymentsRelationManager;
use App\Models\Collector;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }
    config(['database.connections.sale_cancellation_test' => [
        ...config('database.connections.mysql'), 'url' => null, 'database' => 'banksampah_testing',
    ], 'database.default' => 'sale_cancellation_test']);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian pembatalan penjualan hanya boleh memakai MySQL banksampah_testing.');
    }
    $connection->beginTransaction();
});

afterEach(function (): void {
    if (config('database.default') === 'sale_cancellation_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

/** @return array{sale: Sale, user: User, source: InventoryMovement} */
function postedSaleForCancellation(): array
{
    $user = User::factory()->create();
    $collector = Collector::create(['code' => 'P-'.Str::ulid(), 'name' => 'Pengepul Pengujian', 'is_active' => true]);
    $waste = WasteType::create(['code' => 'W-'.Str::ulid(), 'name' => 'Bahan Pengujian', 'is_active' => true]);
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '10.000',
        'unit_cost' => '10.00', 'total_cost' => '100.00', 'reference_type' => 'adjustment', 'transaction_date' => '2026-09-13']);
    $sale = Sale::create(['sale_number' => 'PJ-'.Str::ulid(), 'collector_id' => $collector->id,
        'transaction_date' => '2026-09-13', 'status' => 'draft', 'total_weight' => '10.000', 'total_amount' => '150.00']);
    $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '15.00', 'subtotal' => '150.00']);
    app(SalePostingService::class)->post($sale, $user->id);
    $source = InventoryMovement::where('reference_type', 'sale')->where('reference_id', $sale->id)->sole();

    return compact('sale', 'user', 'source');
}

test('penjualan tanpa pembayaran dapat dibatalkan dengan membalik stok dan biaya asal', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    ['sale' => $sale, 'user' => $user, 'source' => $source] = postedSaleForCancellation();
    $original = $source->getAttributes();

    app(SalePostingService::class)->cancel($sale, $user->id, 'Kesalahan pencatatan penjualan', true);

    expect($sale->fresh())->status->toBe('cancelled')->cancelled_by->toBe($user->id)
        ->total_cost->toBe('100.00');
    expect($sale->cancellation_reason)->toContain('Koreksi pencatatan', 'Petugas #'.$user->id, 'Kesalahan pencatatan penjualan');
    $reversal = InventoryMovement::where('reference_type', 'sale_cancellation')->where('reference_id', $sale->id)->sole();
    expect($reversal)->movement_type->toBe('in')->quantity->toBe('10.000')->total_cost->toBe('100.00');
    expect($reversal->transaction_date->toDateString())->toBe('2026-09-14');
    expect($source->fresh()->getAttributes())->toBe($original);
});

test('penjualan dengan pembayaran aktif ditolak pembatalannya tanpa mengubah data', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    ['sale' => $sale, 'user' => $user, 'source' => $source] = postedSaleForCancellation();
    $payment = app(SalePaymentService::class)->recordPayment($sale, '50.00', '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid());
    $saleBefore = $sale->fresh()->getAttributes();
    $paymentBefore = $payment->fresh()->getAttributes();
    $stockBefore = InventoryMovement::where('waste_type_id', $source->waste_type_id)->orderBy('id')->get()->toArray();

    expect(fn () => app(SalePostingService::class)->cancel($sale, $user->id, 'Kesalahan pencatatan penjualan', true))
        ->toThrow(RuntimeException::class, 'masih memiliki pembayaran aktif');

    expect($sale->fresh()->getAttributes())->toBe($saleBefore);
    expect($payment->fresh()->getAttributes())->toBe($paymentBefore);
    expect(InventoryMovement::where('waste_type_id', $source->waste_type_id)->orderBy('id')->get()->toArray())->toBe($stockBefore);
});

test('koreksi penjualan dan pembayaran menolak permintaan tanpa konfirmasi atau alasan', function (string $kind, string $reason, bool $confirmed): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    ['sale' => $sale, 'user' => $user, 'source' => $source] = postedSaleForCancellation();
    $payment = $kind === 'payment' ? app(SalePaymentService::class)->recordPayment($sale, '50.00', '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid()) : null;
    $before = $sale->fresh()->getAttributes();
    $stock = InventoryMovement::where('waste_type_id', $source->waste_type_id)->orderBy('id')->get()->toArray();

    $operation = $kind === 'sale'
        ? fn () => app(SalePostingService::class)->cancel($sale, $user->id, $reason, $confirmed)
        : fn () => app(SalePaymentService::class)->cancelPayment($payment, $reason, $user->id, $confirmed);
    expect($operation)->toThrow(RuntimeException::class);

    expect($sale->fresh()->getAttributes())->toBe($before);
    expect($payment?->fresh()->status)->toBe($kind === 'payment' ? 'posted' : null);
    expect(InventoryMovement::where('waste_type_id', $source->waste_type_id)->orderBy('id')->get()->toArray())->toBe($stock);
})->with(['sale', 'payment'])->with([
    'tanpa konfirmasi' => ['Kesalahan pencatatan', false],
    'tanpa alasan' => [' ', true],
]);

test('dialog koreksi penjualan mencatat alasan dan mempertahankan mutasi asal', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    ['sale' => $sale, 'user' => $user, 'source' => $source] = postedSaleForCancellation();
    $this->actingAs($user);
    $original = $source->getAttributes();

    Livewire::test(ListSales::class)
        ->callTableAction('batalkanPenjualan', $sale, data: ['reason' => 'Salah input penjualan', 'confirmed_correction' => true])
        ->assertHasNoTableActionErrors();

    expect($sale->fresh()->status)->toBe('cancelled');
    expect($sale->fresh()->cancellation_reason)->toContain('Koreksi pencatatan', 'Salah input penjualan');
    expect($source->fresh()->getAttributes())->toBe($original);
});

test('dialog koreksi pembayaran mencatat audit dan tidak menggandakan pembatalan', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    ['sale' => $sale, 'user' => $user] = postedSaleForCancellation();
    $payment = app(SalePaymentService::class)->recordPayment($sale, '50.00', '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid());
    $this->actingAs($user);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $sale, 'pageClass' => ViewSale::class,
    ])->callTableAction('batalkanPembayaran', $payment, data: ['reason' => 'Salah input pembayaran', 'confirmed_correction' => true])
        ->assertHasNoTableActionErrors();

    expect($payment->fresh())->status->toBe('cancelled')->cancelled_by->toBe($user->id);
    expect($payment->fresh()->cancellation_reason)->toContain('Koreksi pencatatan', 'Salah input pembayaran');
    expect($sale->fresh()->payment_status)->toBe('unpaid');
    expect(fn () => app(SalePaymentService::class)->cancelPayment($payment, 'Salah input pembayaran', $user->id, true))
        ->toThrow(RuntimeException::class, 'masih aktif');
});

test('pembayaran lama yang sudah diverifikasi tidak boleh dikoreksi sebagai salah input', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    ['sale' => $sale, 'user' => $user] = postedSaleForCancellation();
    $payment = app(SalePaymentService::class)->recordPayment($sale, '50.00', '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid());
    $payment->forceFill(['verified_at' => now(), 'verified_by' => $user->id])->save();
    $before = $payment->fresh()->getAttributes();

    expect(fn () => app(SalePaymentService::class)->cancelPayment($payment, 'Salah input', $user->id, true))
        ->toThrow(RuntimeException::class, 'sudah diverifikasi');

    expect($payment->fresh()->getAttributes())->toBe($before);
    expect($sale->fresh()->payment_status)->toBe('partial');
});

test('posting penjualan memakai stok dan biaya pada tanggal kejadian', function (string $saleDate, string $stockDate, bool $laterDeposit, bool $laterSale, bool $allowed, string $message): void {
    $this->travelTo(now()->setDate(2026, 9, 15)->setTime(10, 0));
    $user = User::factory()->create();
    $collector = Collector::create(['code' => 'P-'.Str::ulid(), 'name' => 'Pengepul Tanggal', 'is_active' => true]);
    $waste = WasteType::create(['code' => 'W-'.Str::ulid(), 'name' => 'Bahan Tanggal', 'is_active' => true]);
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '5.000',
        'unit_cost' => '600.00', 'total_cost' => '3000.00', 'reference_type' => 'deposit', 'transaction_date' => $stockDate]);
    if ($laterDeposit) {
        InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '5.000',
            'unit_cost' => '1000.00', 'total_cost' => '5000.00', 'reference_type' => 'deposit', 'transaction_date' => '2026-09-15']);
    }
    if ($laterSale) {
        InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'out', 'quantity' => '1.000',
            'unit_cost' => '600.00', 'total_cost' => '600.00', 'reference_type' => 'sale', 'transaction_date' => '2026-09-15']);
    }
    $sale = Sale::create(['sale_number' => 'PJ-'.Str::ulid(), 'collector_id' => $collector->id,
        'transaction_date' => $saleDate, 'status' => 'draft', 'total_weight' => '1.000', 'total_amount' => '1000.00']);
    $item = $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => '1.000', 'price' => '1000.00', 'subtotal' => '1000.00']);
    $before = $sale->fresh()->getAttributes();
    $itemBefore = $item->fresh()->getAttributes();
    $stockBefore = InventoryMovement::where('waste_type_id', $waste->id)->orderBy('id')->get()->toArray();

    if (! $allowed) {
        expect(fn () => app(SalePostingService::class)->post($sale, $user->id))->toThrow(RuntimeException::class, $message);
        expect($sale->fresh()->getAttributes())->toBe($before);
        expect($item->fresh()->getAttributes())->toBe($itemBefore);
        expect(InventoryMovement::where('waste_type_id', $waste->id)->orderBy('id')->get()->toArray())->toBe($stockBefore);

        return;
    }
    app(SalePostingService::class)->post($sale, $user->id);

    expect($sale->fresh())->status->toBe('posted')->total_cost->toBe('600.00')->gross_profit->toBe('400.00');
    $movement = InventoryMovement::where('reference_type', 'sale')->where('reference_id', $sale->id)->sole();
    expect($movement->transaction_date->toDateString())->toBe($saleDate);
    expect($movement->total_cost)->toBe('600.00');
})->with([
    'stok besok tidak membiayai penjualan kemarin' => ['2026-09-14', '2026-09-15', false, false, false, 'Stok tidak mencukupi pada tanggal'],
    'terlambat dicatat dengan stok tersedia' => ['2026-09-14', '2026-09-13', false, false, true, ''],
    'harga setoran sesudahnya tidak mengubah HPP tanggal asal' => ['2026-09-14', '2026-09-13', true, false, true, ''],
    'stok tanggal sama boleh digunakan' => ['2026-09-15', '2026-09-15', false, false, true, ''],
    'penjualan masa depan ditolak' => ['2026-09-16', '2026-09-13', false, false, false, 'Tanggal penjualan'],
    'HPP penjualan berikutnya perlu pemeriksaan' => ['2026-09-14', '2026-09-13', false, true, false, 'koreksi terkontrol'],
]);

test('pembatalan penjualan tidak boleh mendahului tanggal sumber atau riwayat stok', function (string $target): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    ['sale' => $sale, 'user' => $user, 'source' => $source] = postedSaleForCancellation();
    if ($target === 'sale') {
        $sale->update(['transaction_date' => '2026-09-15']);
    } else {
        $source->update(['transaction_date' => '2026-09-15']);
    }
    $before = $sale->fresh()->getAttributes();
    $stockBefore = InventoryMovement::where('waste_type_id', $source->waste_type_id)->orderBy('id')->get()->toArray();

    expect(fn () => app(SalePostingService::class)->cancel($sale, $user->id, 'Salah tanggal sumber', true))
        ->toThrow(RuntimeException::class, 'mendahului');

    expect($sale->fresh()->getAttributes())->toBe($before);
    expect(InventoryMovement::where('waste_type_id', $source->waste_type_id)->orderBy('id')->get()->toArray())->toBe($stockBefore);
})->with(['sale', 'stock']);
