<?php

use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Filament\Resources\Deposits\Pages\EditDeposit;
use App\Filament\Resources\Deposits\Pages\ListDeposits;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\User;
use App\Models\WasteType;
use App\Services\DepositService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }
    config(['database.connections.deposit_balance_test' => [
        ...config('database.connections.mysql'), 'url' => null, 'database' => 'banksampah_testing',
    ], 'database.default' => 'deposit_balance_test']);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian saldo hanya boleh memakai MySQL banksampah_testing.');
    }
    $connection->beginTransaction();
});

afterEach(function (): void {
    if (config('database.default') === 'deposit_balance_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

test('saldo mengikuti nasabah yang dipilih dan pilihan kosong tidak menampilkan saldo sebelumnya', function (): void {
    $this->actingAs(User::factory()->create());
    $customer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Saldo']);
    $otherCustomer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Baru']);
    $customer->balanceMutations()->create(['type' => 'credit', 'amount' => '150000.50', 'transaction_date' => '2026-09-14', 'reference_type' => 'deposit']);
    $customer->balanceMutations()->create(['type' => 'debit', 'amount' => '25000.00', 'transaction_date' => '2026-09-14', 'reference_type' => 'withdrawal']);

    Livewire::test(CreateDeposit::class)
        ->assertSee('Pilih nasabah untuk melihat saldo.')
        ->set('data.customer_id', $customer->id)
        ->assertSee('Rp 125.000,50')
        ->set('data.customer_id', $otherCustomer->id)
        ->assertSee('Rp 0,00')
        ->assertDontSee('Rp 125.000,50')
        ->set('data.customer_id', null)
        ->assertSee('Pilih nasabah untuk melihat saldo.')
        ->assertDontSee('Rp 0,00');
});

test('draft menampilkan saldo terkini dan saldo bertambah setelah setoran diposting', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    $this->actingAs(User::factory()->create());
    $customer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Posting']);
    $customer->balanceMutations()->create(['type' => 'credit', 'amount' => '50000.00', 'transaction_date' => '2026-09-13', 'reference_type' => 'deposit']);
    $waste = WasteType::create(['code' => 'W-'.Str::ulid(), 'name' => 'Bahan Saldo']);
    $deposit = Deposit::create(['deposit_number' => 'D-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'total_weight' => '2.000', 'total_amount' => '10000.00', 'status' => 'draft']);
    $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '2.000', 'price' => '5000.00', 'subtotal' => '10000.00']);

    Livewire::test(EditDeposit::class, ['record' => $deposit->id])
        ->assertSee('Rp 50.000,00')
        ->fillForm(['notes' => 'Draft tetap belum menambah saldo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->fresh()->balance)->toBe(50000.0);

    $form = Livewire::test(CreateDeposit::class)
        ->set('data.customer_id', $customer->id)
        ->assertSee('Rp 50.000,00');

    app(DepositService::class)->post($deposit);

    $form->call('$refresh')->assertSee('Rp 60.000,00');
    expect($deposit->fresh()->status)->toBe('posted');
});

test('daftar setoran menampilkan saldo terkini per nasabah termasuk baris draft dan pembatalan', function (): void {
    $this->actingAs(User::factory()->create());
    $customer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Saldo Tabel']);
    $otherCustomer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Saldo Nol']);
    $customer->balanceMutations()->create(['type' => 'credit', 'amount' => '150000.50', 'transaction_date' => '2026-09-14', 'reference_type' => 'deposit']);
    $customer->balanceMutations()->create(['type' => 'debit', 'amount' => '25000.00', 'transaction_date' => '2026-09-14', 'reference_type' => 'withdrawal']);
    $deposits = collect();
    foreach (['draft', 'posted', 'cancelled'] as $status) {
        $deposits->push(Deposit::create(['deposit_number' => 'D-'.Str::ulid(), 'customer_id' => $customer->id,
            'transaction_date' => '2026-09-14', 'total_weight' => '1.000', 'total_amount' => '10000.00', 'status' => $status]));
    }
    $otherDeposit = Deposit::create(['deposit_number' => 'D-'.Str::ulid(), 'customer_id' => $otherCustomer->id,
        'transaction_date' => '2026-09-14', 'total_weight' => '1.000', 'total_amount' => '10000.00', 'status' => 'draft']);

    $table = Livewire::test(ListDeposits::class)
        ->searchTable('Nasabah Saldo')
        ->assertSee('Saldo Nasabah')
        ->assertCanSeeTableRecords($deposits)
        ->assertTableColumnStateSet('customer_balance', '0.00', $otherDeposit);

    foreach ($deposits as $deposit) {
        $table->assertTableColumnStateSet('customer_balance', '125000.50', $deposit);
    }

    $table->filterTable('status', 'draft')
        ->assertTableColumnStateSet('customer_balance', '125000.50', $deposits->first());
});

test('posting dari daftar setoran langsung memperbarui kolom saldo nasabah', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(10, 0));
    $this->actingAs(User::factory()->create());
    $customer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Posting Tabel']);
    $customer->balanceMutations()->create(['type' => 'credit', 'amount' => '50000.00', 'transaction_date' => '2026-09-13', 'reference_type' => 'deposit']);
    $waste = WasteType::create(['code' => 'W-'.Str::ulid(), 'name' => 'Bahan Saldo Tabel']);
    $deposit = Deposit::create(['deposit_number' => 'D-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'total_weight' => '2.000', 'total_amount' => '10000.00', 'status' => 'draft']);
    $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '2.000', 'price' => '5000.00', 'subtotal' => '10000.00']);

    Livewire::test(ListDeposits::class)
        ->searchTable('Nasabah Posting Tabel')
        ->assertTableColumnStateSet('customer_balance', '50000.00', $deposit)
        ->callTableAction('post', $deposit)
        ->assertNotified('Posting Berhasil')
        ->assertTableColumnStateSet('customer_balance', '60000.00', $deposit);

    expect($deposit->fresh()->status)->toBe('posted');
});
