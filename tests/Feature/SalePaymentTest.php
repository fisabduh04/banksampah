<?php

use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Collector;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\SalePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }
    config(['database.connections.payment_test' => [
        ...config('database.connections.mysql'), 'url' => null, 'database' => 'banksampah_testing',
    ], 'database.default' => 'payment_test']);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian pembatalan penjualan hanya boleh memakai MySQL banksampah_testing.');
    }
    $connection->beginTransaction();
});

afterEach(function (): void {
    if (config('database.default') === 'payment_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

/** @return array{sale: Sale, user: User} */
function paymentInvoice(string $total = '100.00'): array
{
    test()->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    $user = User::factory()->create();
    $collector = Collector::create(['code' => 'P-'.Str::ulid(), 'name' => 'Pengepul Pengujian', 'is_active' => true]);
    $sale = Sale::create(['sale_number' => 'PJ-'.Str::ulid(), 'collector_id' => $collector->id,
        'transaction_date' => '2026-09-13', 'status' => 'posted', 'total_amount' => $total, 'posted_by' => $user->id, 'posted_at' => now()]);

    return compact('sale', 'user');
}

/** @param array<string, mixed> $overrides */
function paymentRequest(Sale $sale, User $user, array $overrides = []): SalePayment
{
    return app(SalePaymentService::class)->recordPayment(...[
        'sale' => $sale, 'amount' => '40.00', 'paymentDate' => '2026-09-14', 'paymentMethod' => 'cash',
        'referenceNumber' => null, 'notes' => null, 'userId' => $user->id, 'idempotencyKey' => (string) Str::uuid(),
        ...$overrides,
    ]);
}

test('cicilan dan pelunasan tepat sampai satu sen termasuk saat pembatalan', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    paymentRequest($sale, $user, ['amount' => '99.99']);
    expect($sale->fresh())->payment_status->toBe('partial')->outstanding_amount->toBe('0.01');
    $last = paymentRequest($sale, $user, ['amount' => '0.01']);
    expect($sale->fresh())->payment_status->toBe('paid')->paid_amount->toBe('100.00')->outstanding_amount->toBe('0.00');

    app(SalePaymentService::class)->cancelPayment($last, 'Salah input sen', $user->id, true);

    expect($sale->fresh())->payment_status->toBe('partial')->outstanding_amount->toBe('0.01');
});

test('pengiriman ulang pembayaran lunas mengembalikan pembayaran yang sama', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $key = (string) Str::uuid();
    $first = paymentRequest($sale, $user, ['amount' => '100.00', 'idempotencyKey' => $key]);

    $second = paymentRequest($sale, $user, ['amount' => '100', 'idempotencyKey' => strtoupper($key)]);

    expect($second->id)->toBe($first->id);
    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(1);
    expect($sale->fresh()->paid_amount)->toBe('100.00');
});

test('pengenal yang sama menolak isi pembayaran yang berubah', function (array $changes): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $key = (string) Str::uuid();
    $payment = paymentRequest($sale, $user, ['idempotencyKey' => $key]);
    $before = $payment->getAttributes();

    expect(fn () => paymentRequest($sale, $user, ['idempotencyKey' => $key, ...$changes]))->toThrow(RuntimeException::class, 'data yang berbeda');

    expect($payment->fresh()->getAttributes())->toBe($before);
    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(1);
})->with([
    'nominal' => [['amount' => '41.00']], 'tanggal' => [['paymentDate' => '2026-09-13']],
    'metode' => [['paymentMethod' => 'transfer']], 'referensi' => [['referenceNumber' => 'Bukti lain']],
    'catatan' => [['notes' => 'Catatan lain']],
]);

test('pengenal pembayaran tidak dapat dipakai untuk penjualan lain atau setelah pembatalan', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    ['sale' => $other] = paymentInvoice();
    $key = (string) Str::uuid();
    $payment = paymentRequest($sale, $user, ['idempotencyKey' => $key]);
    expect(fn () => paymentRequest($other, $user, ['idempotencyKey' => $key]))->toThrow(RuntimeException::class, 'data yang berbeda');
    app(SalePaymentService::class)->cancelPayment($payment, 'Salah input', $user->id, true);

    expect(fn () => paymentRequest($sale, $user, ['idempotencyKey' => $key]))->toThrow(RuntimeException::class, 'sudah dibatalkan');

    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(1);
    expect($sale->fresh()->paid_amount)->toBe('0.00');
});

test('pembayaran tidak valid ditolak tanpa mengubah piutang', function (array $changes): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $before = $sale->fresh()->getAttributes();

    expect(fn () => paymentRequest($sale, $user, $changes))->toThrow(RuntimeException::class);

    expect($sale->fresh()->getAttributes())->toBe($before);
    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(0);
})->with([
    'nol' => [['amount' => '0']], 'negatif' => [['amount' => '-1']], 'lebih satu sen' => [['amount' => '100.01']],
    'pecahan berlebihan' => [['amount' => '1.001']], 'eksponen' => [['amount' => '1e2']],
    'melampaui kapasitas' => [['amount' => '10000000000000.00']],
    'tanggal sebelum penjualan' => [['paymentDate' => '2026-09-12']],
    'tanggal besok' => [['paymentDate' => '2026-09-15']], 'tanggal tidak nyata' => [['paymentDate' => '2026-02-30']],
    'kunci kosong' => [['idempotencyKey' => '']], 'kunci tidak valid' => [['idempotencyKey' => 'bukan-uuid']],
    'metode tidak dikenal' => [['paymentMethod' => 'invalid']], 'petugas tidak valid' => [['userId' => 0]],
]);

test('pembayaran baru tidak mendahului pembayaran atau pembatalan terakhir', function (bool $cancel): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $payment = paymentRequest($sale, $user, ['paymentDate' => $cancel ? '2026-09-13' : '2026-09-14']);
    if ($cancel) {
        app(SalePaymentService::class)->cancelPayment($payment, 'Salah input', $user->id, true);
    }
    expect(fn () => paymentRequest($sale, $user, ['paymentDate' => '2026-09-13']))->toThrow(RuntimeException::class, 'riwayat pembayaran');
    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(1);
})->with([false, true]);

test('pembayaran lama tanpa pengenal tetap dihitung tanpa ditulis ulang', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $old = paymentRequest($sale, $user);
    $old->update(['idempotency_key' => null]);
    $before = $old->fresh()->getAttributes();

    paymentRequest($sale, $user, ['amount' => '60.00']);

    expect($sale->fresh())->payment_status->toBe('paid')->paid_amount->toBe('100.00');
    expect($old->fresh()->getAttributes())->toBe($before);
});

test('nominal besar mempertahankan sisa piutang satu sen', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice('9999999999999.99');

    paymentRequest($sale, $user, ['amount' => '9999999999999.98']);

    expect($sale->fresh())->payment_status->toBe('partial')->outstanding_amount->toBe('0.01');
});

test('kegagalan pembaruan penjualan membatalkan seluruh pencatatan pembayaran', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $event = 'eloquent.updating: '.Sale::class;
    Event::listen($event, function (): void {
        throw new RuntimeException('Simulasi gagal menyimpan status');
    });
    try {
        expect(fn () => paymentRequest($sale, $user))->toThrow(RuntimeException::class, 'Simulasi');
    } finally {
        Event::forget($event);
    }
    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(0);
    expect($sale->fresh()->payment_status)->toBe('unpaid');
});

test('form pembayaran menyimpan pengenal otomatis dan nominal desimal', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $this->actingAs($user);

    Livewire::test(ListSales::class)->callTableAction('catatPembayaran', $sale, data: [
        'amount' => '99.99', 'payment_date' => '2026-09-14', 'payment_method' => 'cash',
    ])->assertHasNoTableActionErrors();

    $payment = SalePayment::where('sale_id', $sale->id)->sole();
    expect(Str::isUuid($payment->idempotency_key))->toBeTrue();
    expect($payment->amount)->toBe('99.99');
    expect($sale->fresh()->outstanding_amount)->toBe('0.01');
});

test('form mempertahankan pengenal ketika validasi gagal dan pengiriman diulang', function (): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $this->actingAs($user);
    $component = Livewire::test(ListSales::class)->mountTableAction('catatPembayaran', $sale);
    $key = $component->get('mountedActions.0.data.idempotency_key');
    $component->setTableActionData(['amount' => '', 'payment_date' => '2026-09-14', 'payment_method' => 'cash'])
        ->callMountedTableAction()->assertHasTableActionErrors(['amount']);
    expect($component->get('mountedActions.0.data.idempotency_key'))->toBe($key);

    $component->setTableActionData(['amount' => '40.00'])->callMountedTableAction()->assertHasNoTableActionErrors();
    $replay = paymentRequest($sale, $user, ['idempotencyKey' => $key]);

    expect($replay->idempotency_key)->toBe($key);
    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(1);
});

test('penjualan yang belum dibukukan atau dibatalkan tidak menerima pembayaran', function (string $status): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $sale->update(['status' => $status]);

    expect(fn () => paymentRequest($sale, $user))->toThrow(RuntimeException::class, 'sudah diposting');

    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(0);
})->with(['draft', 'cancelled']);

test('riwayat nominal atau status pembayaran tidak valid tidak diabaikan', function (array $invalid): void {
    ['sale' => $sale, 'user' => $user] = paymentInvoice();
    $old = paymentRequest($sale, $user);
    $old->update($invalid);

    expect(fn () => paymentRequest($sale, $user))->toThrow(RuntimeException::class, 'tidak valid');

    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(1);
})->with([[['amount' => '0.00']], [['status' => 'invalid']]]);
