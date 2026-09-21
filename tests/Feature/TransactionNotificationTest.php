<?php

use App\Filament\Resources\Sales\Pages\EditSale;
use App\Models\Collector;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Services\SalePostingService;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }
    config(['database.connections.notification_test' => [
        ...config('database.connections.mysql'), 'url' => null, 'database' => 'banksampah_testing',
    ], 'database.default' => 'notification_test']);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian pembatalan penjualan hanya boleh memakai MySQL banksampah_testing.');
    }
    $connection->beginTransaction();
});

afterEach(function (): void {
    if (config('database.default') === 'notification_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

test('notifikasi posting menjelaskan stok tidak cukup dan menyembunyikan kesalahan teknis', function (string $failure): void {
    $this->travelTo(now()->setDate(2026, 9, 15)->setTime(10, 0));
    $user = User::factory()->create();
    $this->actingAs($user);
    $collector = Collector::create(['code' => 'P-'.Str::ulid(), 'name' => 'Pengepul Notifikasi', 'is_active' => true]);
    $waste = WasteType::create(['code' => 'W-'.Str::ulid(), 'name' => 'Bahan Notifikasi', 'is_active' => true]);
    $sale = Sale::create(['sale_number' => 'PJ-'.Str::ulid(), 'collector_id' => $collector->id,
        'transaction_date' => '2026-09-15', 'status' => 'draft', 'total_weight' => '1.000', 'total_amount' => '1000.00']);
    $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => '1.000', 'price' => '1000.00', 'subtotal' => '1000.00']);
    $before = $sale->fresh()->getAttributes();
    if ($failure === 'stock') {
        $message = 'Stok tidak mencukupi pada tanggal penjualan. Stok yang masuk setelah tanggal tersebut tidak dapat digunakan. Periksa jenis sampah, berat, tanggal penjualan, dan setoran yang sudah dibukukan. Jika ada setoran yang terlambat dicatat, minta administrator memeriksa urutan transaksi.';
    } else {
        Exceptions::fake();
        $exception = $failure === 'database'
            ? new QueryException('mysql', 'SELECT secret_internal_data', [], new PDOException('private_database_error'))
            : new TypeError('private_internal_path');
        $this->mock(SalePostingService::class, function ($mock) use ($exception): void {
            $mock->shouldReceive('post')->once()->andThrow($exception);
        });
        $message = 'Proses belum dapat diselesaikan karena gangguan sistem. Periksa status dan riwayat transaksi sebelum mencoba lagi agar tidak tercatat ganda. Jika masalah berulang, hubungi administrator dengan nomor transaksi dan waktu kejadian.';
    }

    Livewire::test(EditSale::class, ['record' => $sale->id])
        ->callAction('posting')
        ->assertNotified(Notification::make()->title('Penjualan tidak dapat diposting')
            ->body($message)->danger()->persistent());

    expect($sale->fresh()->getAttributes())->toBe($before);
    expect(InventoryMovement::where('waste_type_id', $waste->id)->exists())->toBeFalse();
    if ($failure !== 'stock') {
        Exceptions::assertReported($exception::class);
    }
})->with(['stock', 'database', 'unexpected']);
