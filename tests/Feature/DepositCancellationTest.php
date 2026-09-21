<?php

use App\Filament\Resources\Deposits\Pages\EditDeposit;
use App\Filament\Resources\Deposits\Pages\ListDeposits;
use App\Filament\Resources\Withdrawals\Pages\EditWithdrawal;
use App\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Models\BalanceMutation;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Models\WasteType;
use App\Models\Withdrawal;
use App\Services\CustomerDraftService;
use App\Services\DepositService;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
    app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true);
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
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'saldo nasabah tidak mencukupi');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('pembatalan ditolak ketika stok telah dijual tanpa perubahan parsial', function (): void {
    ['deposit' => $deposit, 'waste' => $waste] = cancellationRecords();
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'out', 'quantity' => '1.000', 'unit_cost' => '10.00', 'total_cost' => '10.00', 'reference_type' => 'sale', 'transaction_date' => '2026-09-14']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'stok tidak mencukupi');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('pembatalan menggunakan biaya koreksi lama tanpa membalik kuantitas dua kali', function (string $correctionQuantity): void {
    ['deposit' => $deposit, 'source' => $source] = cancellationRecords('0.00');
    foreach (['cost_reconciliation_reversal' => ['out', '0.00'], 'cost_reconciliation' => ['in', '100.00']] as $type => [$direction, $cost]) {
        InventoryMovement::create(['waste_type_id' => $source->waste_type_id, 'movement_type' => $direction, 'quantity' => $correctionQuantity,
            'total_cost' => $cost, 'unit_cost' => $direction === 'in' ? '10.00' : '0.00', 'reference_type' => $type, 'reference_id' => $source->id, 'transaction_date' => '2026-09-14']);
    }
    app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true);
    $reversal = InventoryMovement::query()->where('reference_type', 'deposit_cancellation')->where('reference_id', $deposit->id)->sole();
    expect($reversal)->quantity->toBe('10.000')->total_cost->toBe('100.00')->unit_cost->toBe('10.00');
})->with(['10.000', '0.000']);

test('koreksi biaya parsial ditolak tanpa menebak biaya pembalik', function (): void {
    ['deposit' => $deposit, 'source' => $source] = cancellationRecords('0.00');
    InventoryMovement::create(['waste_type_id' => $source->waste_type_id, 'movement_type' => 'out', 'quantity' => '0.000',
        'total_cost' => '0.00', 'reference_type' => 'cost_reconciliation_reversal', 'reference_id' => $source->id, 'transaction_date' => '2026-09-14']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'Koreksi biaya asal belum lengkap');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('permintaan pembatalan berulang dengan object lama tidak menggandakan ledger', function (): void {
    ['deposit' => $deposit] = cancellationRecords();
    $stale = $deposit->fresh();
    app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($stale, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'Hanya transaksi yang telah dibukukan');
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
        expect(fn () => app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(RuntimeException::class, 'Simulasi');
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
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'nilai persediaan tidak seimbang');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('kredit bertanggal masa depan tidak boleh membiayai pembatalan hari ini', function (): void {
    ['deposit' => $deposit, 'customer' => $customer] = cancellationRecords();
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => '100.00', 'transaction_date' => '2026-09-14', 'reference_type' => 'withdrawal']);
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => '100.00', 'transaction_date' => '2026-09-15', 'reference_type' => 'deposit']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'Riwayat saldo tidak valid');
    expect(cancellationSnapshot($deposit))->toBe($before);
});

test('pembatalan menolak nilai persediaan kurang meskipun berat mencukupi', function (): void {
    ['deposit' => $deposit, 'source' => $source] = cancellationRecords();
    InventoryMovement::create(['waste_type_id' => $source->waste_type_id, 'movement_type' => 'out', 'quantity' => '0.000',
        'total_cost' => '1.00', 'reference_type' => 'adjustment', 'transaction_date' => '2026-09-14']);
    $before = cancellationSnapshot($deposit);
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'nilai persediaan tidak seimbang');
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

    app(DepositService::class)->cancel($deposit, 'Kesalahan input pengujian', User::factory()->create()->id, true);
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

test('penarikan ulang dengan object lama tidak menggandakan debit', function (): void {
    ['customer' => $customer] = cancellationRecords();
    $withdrawal = Withdrawal::create(['withdrawal_number' => 'W-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'status' => 'draft', 'amount' => '80.00']);
    $stale = $withdrawal->fresh();
    app(WithdrawalService::class)->post($withdrawal);
    expect(fn () => app(WithdrawalService::class)->post($stale))->toThrow(Exception::class, 'Hanya transaksi');
    expect(BalanceMutation::where('reference_type', 'withdrawal')->where('reference_id', $withdrawal->id)->count())->toBe(1);
    expect($customer->fresh()->balance)->toBe(20.0);
});

test('penarikan memakai desimal tepat hingga sisa satu sen pada nominal besar', function (): void {
    ['customer' => $customer] = cancellationRecords();
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => '9999999999899.99', 'transaction_date' => '2026-09-14']);
    $withdrawal = Withdrawal::create(['withdrawal_number' => 'W-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'status' => 'draft', 'amount' => '9999999999999.98']);
    app(WithdrawalService::class)->post($withdrawal);
    $balance = DB::table('balance_mutations')->where('customer_id', $customer->id)
        ->selectRaw("SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) AS balance")->first()->balance;
    expect($balance)->toBe('0.01');
});

test('penarikan yang gagal menulis mutasi tetap draft tanpa mengubah saldo', function (): void {
    ['customer' => $customer] = cancellationRecords();
    $withdrawal = Withdrawal::create(['withdrawal_number' => 'W-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'status' => 'draft', 'amount' => '80.00']);
    $event = 'eloquent.creating: '.BalanceMutation::class;
    Event::listen($event, function (BalanceMutation $mutation): void {
        if ($mutation->reference_type === 'withdrawal') {
            throw new RuntimeException('Simulasi kegagalan debit');
        }
    });
    try {
        expect(fn () => app(WithdrawalService::class)->post($withdrawal))->toThrow(RuntimeException::class, 'Simulasi');
        expect($withdrawal->fresh()->status)->toBe('draft');
        expect($customer->fresh()->balance)->toBe(100.0);
        expect(BalanceMutation::where('reference_type', 'withdrawal')->where('reference_id', $withdrawal->id)->exists())->toBeFalse();
    } finally {
        Event::forget($event);
    }
});

test('penarikan menolak nominal tidak sah atau melebihi saldo', function (string $amount): void {
    ['customer' => $customer] = cancellationRecords();
    $withdrawal = Withdrawal::create(['withdrawal_number' => 'W-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'status' => 'draft', 'amount' => $amount]);
    expect(fn () => app(WithdrawalService::class)->post($withdrawal))->toThrow(Exception::class);
    expect($withdrawal->fresh()->status)->toBe('draft');
    expect($customer->fresh()->balance)->toBe(100.0);
})->with(['0.00', '-1.00', '100.01']);

/** @return array{record: Deposit|Withdrawal, page: class-string} */
function editableCustomerTransaction(string $kind): array
{
    ['deposit' => $posted, 'customer' => $customer, 'waste' => $waste] = cancellationRecords();
    test()->actingAs(User::factory()->create());
    if ($kind === 'deposit') {
        $record = $posted->replicate();
        $record->forceFill(['deposit_number' => 'EDIT-'.Str::ulid(), 'status' => 'draft'])->save();
        $record->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
        $page = EditDeposit::class;
    } else {
        $record = Withdrawal::create(['withdrawal_number' => 'EDIT-'.Str::ulid(), 'customer_id' => $customer->id,
            'transaction_date' => '2026-09-14', 'status' => 'draft', 'amount' => '10.00']);
        $page = EditWithdrawal::class;
    }

    return compact('record', 'page');
}

test('draft masih dapat diedit tanpa mengubah status dan nomor transaksi', function (string $kind): void {
    ['record' => $record, 'page' => $page] = editableCustomerTransaction($kind);
    Livewire::test($page, ['record' => $record->id])
        ->fillForm(['notes' => 'Catatan diperbarui'])
        ->call('save')->assertHasNoFormErrors();
    expect($record->fresh())->notes->toBe('Catatan diperbarui')->status->toBe('draft');
})->with(['deposit', 'withdrawal']);

test('status posted tidak dapat disimpan melalui form edit', function (string $kind): void {
    ['record' => $record, 'page' => $page] = editableCustomerTransaction($kind);
    $before = $record->fresh()->getAttributes();
    Livewire::test($page, ['record' => $record->id])
        ->fillForm(['status' => 'posted'])->call('save')->assertHasFormErrors(['status']);
    expect($record->fresh()->getAttributes())->toBe($before);
})->with(['deposit', 'withdrawal']);

test('halaman edit lama tidak dapat menyimpan setelah transaksi diposting', function (string $kind): void {
    ['record' => $record, 'page' => $page] = editableCustomerTransaction($kind);
    $component = Livewire::test($page, ['record' => $record->id]);
    $service = $kind === 'deposit' ? DepositService::class : WithdrawalService::class;
    app($service)->post($record);
    $before = $record->fresh()->getAttributes();
    $component->call('save')->assertForbidden();
    expect($record->fresh()->getAttributes())->toBe($before);
    expect(fn () => app(CustomerDraftService::class)->delete($record))->toThrow(ValidationException::class);
    expect($record->fresh())->not->toBeNull();
})->with(['deposit', 'withdrawal']);

test('URL edit transaksi final ditolak oleh server', function (string $kind, string $status): void {
    ['record' => $record, 'page' => $page] = editableCustomerTransaction($kind);
    $record->update(['status' => $status]);
    Livewire::test($page, ['record' => $record->id])->assertForbidden();
})->with([['deposit', 'posted'], ['deposit', 'cancelled'], ['withdrawal', 'posted'], ['withdrawal', 'cancelled']]);

test('draft dapat dihapus melalui aksi halaman edit', function (string $kind): void {
    ['record' => $record, 'page' => $page] = editableCustomerTransaction($kind);
    Livewire::test($page, ['record' => $record->id])->callAction('delete');
    expect($record->fresh())->toBeNull();
})->with(['deposit', 'withdrawal']);

test('posting menolak tanggal sebelum mutasi saldo dan tanggal masa depan tanpa perubahan', function (string $kind, string $date): void {
    ['record' => $record] = editableCustomerTransaction($kind);
    $record->update(['transaction_date' => $date]);
    $before = $record->fresh()->getAttributes();
    $balanceBefore = BalanceMutation::orderBy('id')->get()->toArray();
    $stockBefore = InventoryMovement::orderBy('id')->get()->toArray();
    $service = $kind === 'deposit' ? DepositService::class : WithdrawalService::class;

    expect(fn () => app($service)->post($record))->toThrow(Exception::class, 'Tanggal');

    expect($record->fresh()->getAttributes())->toBe($before);
    expect(BalanceMutation::orderBy('id')->get()->toArray())->toBe($balanceBefore);
    expect(InventoryMovement::orderBy('id')->get()->toArray())->toBe($stockBefore);
})->with([
    ['deposit', '2026-09-12'], ['withdrawal', '2026-09-12'],
    ['deposit', '2026-09-15'], ['withdrawal', '2026-09-15'],
]);

test('posting menerima tanggal sama dengan mutasi terakhir dan hari ini', function (string $kind, string $date): void {
    ['record' => $record] = editableCustomerTransaction($kind);
    $record->update(['transaction_date' => $date]);
    $service = $kind === 'deposit' ? DepositService::class : WithdrawalService::class;

    app($service)->post($record);

    expect($record->fresh()->status)->toBe('posted');
    $mutation = BalanceMutation::where('reference_type', $kind)->where('reference_id', $record->id)->sole();
    expect($mutation->transaction_date->toDateString())->toBe($date);
    expect($mutation->amount)->toBe($kind === 'deposit' ? '100.00' : '10.00');
})->with([
    ['deposit', '2026-09-13'], ['withdrawal', '2026-09-13'],
    ['deposit', '2026-09-14'], ['withdrawal', '2026-09-14'],
]);

test('setoran nasabah lain tidak boleh disisipkan sebelum riwayat persediaan bahan', function (): void {
    ['record' => $record] = editableCustomerTransaction('deposit');
    $customer = Customer::create(['customer_code' => 'C-'.Str::ulid(), 'name' => 'Nasabah Lain']);
    $record->update(['customer_id' => $customer->id, 'transaction_date' => '2026-09-12']);
    $before = cancellationSnapshot($record);

    expect(fn () => app(DepositService::class)->post($record))->toThrow(Exception::class, 'mutasi persediaan terakhir');

    expect(cancellationSnapshot($record))->toBe($before);
});

test('pembatalan penarikan lama bertanggal masa depan tidak membuat kredit lebih awal', function (): void {
    ['record' => $record] = editableCustomerTransaction('withdrawal');
    app(WithdrawalService::class)->post($record);
    $source = BalanceMutation::where('reference_type', 'withdrawal')->where('reference_id', $record->id)->sole();
    $source->update(['transaction_date' => '2026-09-15']);
    $before = BalanceMutation::where('customer_id', $record->customer_id)->orderBy('id')->get()->toArray();

    expect(fn () => app(WithdrawalService::class)->cancel($record, 'Kesalahan input pengujian', User::factory()->create()->id, true))->toThrow(Exception::class, 'tidak boleh mendahului');

    expect($record->fresh()->status)->toBe('posted');
    expect(BalanceMutation::where('customer_id', $record->customer_id)->orderBy('id')->get()->toArray())->toBe($before);
});

test('koreksi nasabah menolak alasan atau konfirmasi atau petugas yang tidak valid', function (string $kind, string $reason, bool $confirmed, ?int $actor): void {
    ['record' => $record] = editableCustomerTransaction($kind);
    $service = $kind === 'deposit' ? DepositService::class : WithdrawalService::class;
    app($service)->post($record);
    $before = $record->fresh()->getAttributes();
    $balances = BalanceMutation::orderBy('id')->get()->toArray();
    $stock = InventoryMovement::orderBy('id')->get()->toArray();

    expect(fn () => app($service)->cancel($record, $reason, $actor ?? auth()->id(), $confirmed))->toThrow(RuntimeException::class);

    expect($record->fresh()->getAttributes())->toBe($before);
    expect(BalanceMutation::orderBy('id')->get()->toArray())->toBe($balances);
    expect(InventoryMovement::orderBy('id')->get()->toArray())->toBe($stock);
})->with(['deposit', 'withdrawal'])->with([
    'tanpa konfirmasi' => ['Kesalahan pencatatan', false, null],
    'alasan kosong' => ['   ', true, null],
    'alasan terlalu panjang' => [str_repeat('a', 2001), true, null],
    'petugas tidak valid' => ['Kesalahan pencatatan', true, 0],
]);

test('dialog koreksi nasabah mewajibkan konfirmasi dan menyimpan jejak petugas', function (string $kind): void {
    ['record' => $record] = editableCustomerTransaction($kind);
    $service = $kind === 'deposit' ? DepositService::class : WithdrawalService::class;
    app($service)->post($record);
    $page = $kind === 'deposit'
        ? ListDeposits::class
        : ListWithdrawals::class;
    $component = Livewire::test($page);

    $component->callTableAction('cancel', $record, data: ['reason' => 'Pencatatan ganda, transaksi benar TEST-001', 'confirmed_correction' => false])
        ->assertHasTableActionErrors(['confirmed_correction']);
    expect($record->fresh()->status)->toBe('posted');
    Livewire::test($page)->callTableAction('cancel', $record, data: ['reason' => 'Pencatatan ganda, transaksi benar TEST-001', 'confirmed_correction' => true])
        ->assertHasNoTableActionErrors();

    expect($record->fresh()->status)->toBe('cancelled');
    $reversal = BalanceMutation::where('reference_type', $kind.'_cancellation')->where('reference_id', $record->id)->sole();
    expect($reversal->description)->toContain('Koreksi pencatatan', 'Petugas #'.auth()->id(), 'transaksi benar TEST-001');
    expect($reversal->created_at)->not->toBeNull();
})->with(['deposit', 'withdrawal']);

test('penarikan lama yang sudah diverifikasi tidak boleh dikoreksi sebagai salah input', function (): void {
    ['record' => $record] = editableCustomerTransaction('withdrawal');
    app(WithdrawalService::class)->post($record);
    $record->forceFill(['verified_at' => now(), 'verified_by' => auth()->id()])->save();
    $before = $record->fresh()->getAttributes();
    $balances = BalanceMutation::where('customer_id', $record->customer_id)->orderBy('id')->get()->toArray();

    expect(fn () => app(WithdrawalService::class)->cancel($record, 'Salah input', auth()->id(), true))
        ->toThrow(Exception::class, 'sudah diverifikasi');

    expect($record->fresh()->getAttributes())->toBe($before);
    expect(BalanceMutation::where('customer_id', $record->customer_id)->orderBy('id')->get()->toArray())->toBe($balances);
});
