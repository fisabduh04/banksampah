<?php

use App\Models\BalanceMutation;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\WasteType;
use App\Services\DepositService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }
    config(['database.connections.cancellation_test' => [
        ...config('database.connections.mysql'), 'url' => null, 'database' => 'banksampah_testing',
    ], 'database.default' => 'cancellation_test']);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian pembatalan hanya boleh memakai MySQL banksampah_testing.');
    }
    /** Gunakan schema pengujian yang sudah tersedia; tidak menjalankan migration atau menghapus tabel. */
    $connection->beginTransaction();
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
});

afterEach(function (): void {
    if (config('database.default') === 'cancellation_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

/** @return array{deposit: Deposit, source: InventoryMovement, customer: Customer, waste: WasteType} */
function cancellationRecords(string $cost = '100.00'): array
{
    $customer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Pengujian']);
    $waste = WasteType::create(['code' => 'W-'.Str::ulid(), 'name' => 'Bahan Pengujian']);
    $deposit = Deposit::create(['deposit_number' => 'D-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-13', 'total_weight' => '10.000', 'total_amount' => '100.00', 'status' => 'draft']);
    $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
    app(DepositService::class)->post($deposit);
    $source = InventoryMovement::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sole();
    if ($cost === '0.00') {
        /** Simulasi ledger lama sebelum biaya setoran dicatat oleh aplikasi. */
        $source->update(['unit_cost' => '0.00', 'total_cost' => '0.00']);
    }

    return compact('deposit', 'source', 'customer', 'waste');
}

/** @return array<string, mixed> */
function cancellationSnapshot(Deposit $deposit): array
{
    return ['deposit' => $deposit->fresh()->getAttributes(),
        'balance' => BalanceMutation::query()->where('customer_id', $deposit->customer_id)->orderBy('id')->get()->toArray(),
        'stock' => InventoryMovement::query()->whereIn('waste_type_id', $deposit->items()->pluck('waste_type_id'))->orderBy('id')->get()->toArray()];
}

test('pembatalan membalik saldo dan stok sesuai biaya tercatat tanpa mengubah mutasi asal', function (string $cost): void {
    ['deposit' => $deposit, 'source' => $source, 'customer' => $customer] = cancellationRecords($cost);
    $original = $source->getAttributes();
    app(DepositService::class)->cancel($deposit);
    $reversal = InventoryMovement::query()->where('reference_type', 'deposit_cancellation')->where('reference_id', $deposit->id)->sole();
    expect($deposit->status)->toBe('cancelled');
    expect($reversal)->movement_type->toBe('out')->quantity->toBe('10.000')->total_cost->toBe($cost);
    expect($reversal->transaction_date->toDateString())->toBe('2026-09-14');
    expect($source->fresh()->getAttributes())->toBe($original);
    expect(BalanceMutation::query()->where('customer_id', $customer->id)->where('type', 'debit')->sole()->amount)->toBe('100.00');
    expect($customer->fresh()->balance)->toBe(0.0);
})->with(['100.00', '0.00']);

test('pembatalan ditolak ketika saldo telah ditarik tanpa perubahan parsial', function (): void {
    ['deposit' => $deposit, 'customer' => $customer] = cancellationRecords();
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => '60.00', 'transaction_date' => '2026-09-14', 'reference_type' => 'withdrawal']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit))->toThrow(Exception::class, 'saldo nasabah tidak mencukupi');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('pembatalan ditolak ketika stok telah dijual tanpa perubahan parsial', function (): void {
    ['deposit' => $deposit, 'waste' => $waste] = cancellationRecords();
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'out', 'quantity' => '1.000', 'unit_cost' => '10.00', 'total_cost' => '10.00', 'reference_type' => 'sale', 'transaction_date' => '2026-09-14']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit))->toThrow(Exception::class, 'stok tidak mencukupi');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('pembatalan menggunakan biaya koreksi lama tanpa membalik kuantitas dua kali', function (string $correctionQuantity): void {
    ['deposit' => $deposit, 'source' => $source] = cancellationRecords('0.00');
    foreach (['cost_reconciliation_reversal' => ['out', '0.00'], 'cost_reconciliation' => ['in', '100.00']] as $type => [$direction, $cost]) {
        InventoryMovement::create(['waste_type_id' => $source->waste_type_id, 'movement_type' => $direction, 'quantity' => $correctionQuantity,
            'total_cost' => $cost, 'unit_cost' => $direction === 'in' ? '10.00' : '0.00', 'reference_type' => $type, 'reference_id' => $source->id, 'transaction_date' => '2026-09-14']);
    }
    app(DepositService::class)->cancel($deposit);
    $reversal = InventoryMovement::query()->where('reference_type', 'deposit_cancellation')->where('reference_id', $deposit->id)->sole();
    expect($reversal)->quantity->toBe('10.000')->total_cost->toBe('100.00')->unit_cost->toBe('10.00');
})->with(['10.000', '0.000']);

test('koreksi biaya parsial ditolak tanpa menebak biaya pembalik', function (): void {
    ['deposit' => $deposit, 'source' => $source] = cancellationRecords('0.00');
    InventoryMovement::create(['waste_type_id' => $source->waste_type_id, 'movement_type' => 'out', 'quantity' => '0.000',
        'total_cost' => '0.00', 'reference_type' => 'cost_reconciliation_reversal', 'reference_id' => $source->id, 'transaction_date' => '2026-09-14']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit))->toThrow(Exception::class, 'Koreksi biaya asal belum lengkap');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('permintaan pembatalan berulang dengan object lama tidak menggandakan ledger', function (): void {
    ['deposit' => $deposit] = cancellationRecords();
    $stale = $deposit->fresh();
    app(DepositService::class)->cancel($deposit);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($stale))->toThrow(Exception::class, 'Hanya transaksi yang telah dibukukan');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('kegagalan menulis stok membatalkan debit saldo dan status', function (): void {
    ['deposit' => $deposit] = cancellationRecords();
    $before = cancellationSnapshot($deposit);
    $event = 'eloquent.creating: '.InventoryMovement::class;
    Event::listen($event, function (InventoryMovement $movement): void {
        if ($movement->reference_type === 'deposit_cancellation') {
            throw new RuntimeException('Simulasi gagal menulis pembalik stok');
        }
    });
    try {
        expect(fn () => app(DepositService::class)->cancel($deposit))->toThrow(RuntimeException::class, 'Simulasi');
        expect(cancellationSnapshot($deposit))->toBe($before);
    } finally {
        Event::forget($event);
    }
});

test('stok nol setelah pembatalan tidak boleh menyisakan nilai persediaan', function (): void {
    ['deposit' => $deposit, 'source' => $source] = cancellationRecords();
    InventoryMovement::create(['waste_type_id' => $source->waste_type_id, 'movement_type' => 'in', 'quantity' => '0.000',
        'total_cost' => '1.00', 'reference_type' => 'adjustment', 'transaction_date' => '2026-09-14']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit))->toThrow(Exception::class, 'nilai persediaan tidak seimbang');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('kredit bertanggal masa depan tidak boleh membiayai pembatalan hari ini', function (): void {
    ['deposit' => $deposit, 'customer' => $customer] = cancellationRecords();
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => '100.00', 'transaction_date' => '2026-09-14', 'reference_type' => 'withdrawal']);
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => '100.00', 'transaction_date' => '2026-09-15', 'reference_type' => 'deposit']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit))->toThrow(Exception::class, 'Riwayat saldo tidak valid');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('pembatalan menolak nilai persediaan kurang meskipun berat mencukupi', function (): void {
    ['deposit' => $deposit, 'source' => $source] = cancellationRecords();
    InventoryMovement::create(['waste_type_id' => $source->waste_type_id, 'movement_type' => 'out', 'quantity' => '0.000',
        'total_cost' => '1.00', 'reference_type' => 'adjustment', 'transaction_date' => '2026-09-14']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit))->toThrow(Exception::class, 'nilai persediaan tidak seimbang');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('posting mencatat biaya setiap bahan dari snapshot setoran', function (array $details, string $weight, string $amount): void {
    $customer = Customer::create(['customer_code' => 'P-'.Str::ulid(), 'name' => 'Nasabah Uji Posting']);
    $deposit = Deposit::create(['deposit_number' => 'P-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'total_weight' => $weight, 'total_amount' => $amount, 'status' => 'draft']);
    foreach ($details as [$itemWeight, $price, $subtotal]) {
        $waste = WasteType::create(['code' => 'P-'.Str::ulid(), 'name' => 'Bahan Uji Posting']);
        $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => $itemWeight, 'price' => $price, 'subtotal' => $subtotal]);
    }
    app(DepositService::class)->post($deposit);
    expect($deposit->fresh()->status)->toBe('posted');
    foreach ($deposit->items as $item) {
        $movement = InventoryMovement::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)
            ->where('waste_type_id', $item->waste_type_id)->sole();
        expect($movement)->quantity->toBe($item->weight)->unit_cost->toBe($item->price)->total_cost->toBe($item->subtotal);
    }
    expect(BalanceMutation::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sole()->amount)->toBe($amount);
    expect(InventoryMovement::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->sum('total_cost'))->toBe($amount);

    app(DepositService::class)->cancel($deposit);
    expect($deposit->status)->toBe('cancelled');
    expect(InventoryMovement::query()->where('reference_type', 'deposit_cancellation')->where('reference_id', $deposit->id)->sum('total_cost'))->toBe($amount);
})->with([
    'satu bahan' => [[['10.000', '1000.00', '10000.00']], '10.000', '10000.00'],
    'berat pecahan' => [[['1.255', '1234.56', '1549.37']], '1.255', '1549.37'],
    'beberapa bahan' => [[['1.255', '1234.56', '1549.37'], ['2.000', '300.00', '600.00']], '3.255', '2149.37'],
]);

test('posting baru tetap berjalan dan tidak mengubah biaya nol pada setoran lama', function (): void {
    ['deposit' => $old, 'source' => $source] = cancellationRecords('0.00');
    $before = $source->getAttributes();
    $new = $old->replicate();
    $new->forceFill(['deposit_number' => 'NEW-'.Str::ulid(), 'status' => 'draft'])->save();
    $new->items()->create(['waste_type_id' => $source->waste_type_id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
    app(DepositService::class)->post($new);
    expect($new->fresh()->status)->toBe('posted');
    expect($source->fresh()->getAttributes())->toBe($before);
    expect(InventoryMovement::query()->where('reference_type', 'deposit')->where('reference_id', $new->id)->sole()->total_cost)->toBe('100.00');
});

test('kegagalan pencatatan biaya posting membatalkan status saldo dan seluruh stok baru', function (): void {
    ['deposit' => $old, 'source' => $source] = cancellationRecords();
    $new = $old->replicate();
    $new->forceFill(['deposit_number' => 'FAIL-'.Str::ulid(), 'status' => 'draft'])->save();
    $new->items()->create(['waste_type_id' => $source->waste_type_id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
    $before = cancellationSnapshot($new);
    $event = 'eloquent.creating: '.InventoryMovement::class;
    Event::listen($event, function (InventoryMovement $movement) use ($new): void {
        if ($movement->reference_type === 'deposit' && $movement->reference_id === $new->id) {
            throw new RuntimeException('Simulasi gagal mencatat biaya posting');
        }
    });
    try {
        expect(fn () => app(DepositService::class)->post($new))->toThrow(RuntimeException::class, 'Simulasi');
        expect(cancellationSnapshot($new))->toBe($before);
    } finally {
        Event::forget($event);
    }
});
