<?php

use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Filament\Resources\Deposits\Pages\EditDeposit;
use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Withdrawals\Pages\EditWithdrawal;
use App\Models\BalanceMutation;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Models\Withdrawal;
use App\Services\CustomerBalanceService;
use App\Services\CustomerTransactionDraftService;
use App\Services\DepositService;
use App\Services\FinancialControlService;
use App\Services\InventoryService;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use App\Services\WithdrawalService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/** @return array{user: User, manager: User, customer: Customer, waste: WasteType, deposit: Deposit, sale: Sale} */
function financialRecords(): array
{
    test()->travelTo(Carbon::parse('2026-09-14 10:00:00'));
    $user = User::factory()->create();
    $manager = User::factory()->financeManager()->create();
    $customer = Customer::create(['customer_code' => 'NS-CONTROL', 'name' => 'Nasabah Kontrol']);
    $waste = WasteType::create(['code' => 'PET-CONTROL', 'name' => 'PET Kontrol', 'is_active' => true]);
    $deposit = Deposit::create(['deposit_number' => 'ST-CONTROL', 'customer_id' => $customer->id, 'status' => 'draft',
        'transaction_date' => '2026-09-14', 'total_weight' => '10.000', 'total_amount' => '100.00']);
    $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
    app(DepositService::class)->post($deposit, $user->id);
    $collector = Collector::create(['code' => 'PG-CONTROL', 'name' => 'Pengepul Kontrol', 'is_active' => true]);
    $sale = Sale::create(['sale_number' => 'PJ-CONTROL', 'collector_id' => $collector->id, 'status' => 'draft',
        'transaction_date' => '2026-09-14', 'total_weight' => '2.000', 'total_amount' => '100.00']);
    $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => '2.000', 'price' => '50.00', 'subtotal' => '100.00']);

    return compact('user', 'manager', 'customer', 'waste', 'deposit', 'sale');
}

function controlWithdrawal(Customer $customer, string $amount = '60.00'): Withdrawal
{
    return Withdrawal::create(['withdrawal_number' => 'WD-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-09-14', 'status' => 'draft', 'amount' => $amount]);
}

test('dua penarikan dan permintaan lama tidak dapat memakai saldo yang sama', function (): void {
    ['customer' => $customer, 'user' => $user, 'manager' => $manager] = financialRecords();
    $first = controlWithdrawal($customer);
    $stale = $first->fresh();
    $second = controlWithdrawal($customer);
    $service = app(WithdrawalService::class);
    $service->post($first, $user->id);
    expect(fn () => $service->post($stale, $user->id))->toThrow(UnexpectedValueException::class);
    expect(fn () => $service->post($second, $user->id))->toThrow(UnexpectedValueException::class, 'Saldo nasabah tidak mencukupi');
    expect($first->fresh()->posted_by)->toBe($user->id);
    expect($second->fresh()->status)->toBe('draft');
    $service->cancel($first, 'Dana belum diserahkan', $manager->id);
    expect(fn () => $service->cancel($stale, 'Ulang', $manager->id))->toThrow(UnexpectedValueException::class);
    expect($first->fresh())->cancelled_by->toBe($manager->id)->cancellation_reason->toBe('Dana belum diserahkan');
    expect(DB::transaction(fn () => app(CustomerBalanceService::class)->getLockedBalance($customer->id)))->toBe('100.00');
    $this->assertDatabaseCount('balance_mutations', 3);
});

test('setoran yang saldonya sudah ditarik tidak dapat dibatalkan', function (): void {
    ['customer' => $customer, 'deposit' => $deposit, 'waste' => $waste, 'user' => $user, 'manager' => $manager] = financialRecords();
    app(WithdrawalService::class)->post(controlWithdrawal($customer), $user->id);
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Koreksi timbangan', $manager->id))
        ->toThrow(UnexpectedValueException::class, 'Saldo nasabah tidak mencukupi');
    expect($deposit->fresh()->status)->toBe('posted');
    expect(app(InventoryService::class)->getExactBalance($waste->id))->toMatchArray(['quantity' => '10.000', 'value' => '100.00']);
    $this->assertDatabaseCount('balance_mutations', 2);
    $this->assertDatabaseCount('inventory_movements', 1);
});

test('saldo besar tetap mempertahankan satu sen', function (): void {
    ['customer' => $customer, 'user' => $user] = financialRecords();
    BalanceMutation::create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => '9999999899.99', 'transaction_date' => '2026-09-14']);
    app(WithdrawalService::class)->post(controlWithdrawal($customer, '9999999999.98'), $user->id);
    expect(DB::transaction(fn () => app(CustomerBalanceService::class)->getLockedBalance($customer->id)))->toBe('0.01');
});

test('pengiriman ulang pembayaran menghasilkan satu kuitansi termasuk setelah pembatalan', function (): void {
    ['sale' => $sale, 'user' => $user, 'manager' => $manager] = financialRecords();
    app(SalePostingService::class)->post($sale, $user->id);
    $service = app(SalePaymentService::class);
    $key = (string) Str::uuid();
    $record = fn () => $service->recordPayment($sale, '40.01', '2026-09-14', 'cash', null, null, $user->id, $key);
    $payment = $record();
    expect($record()->id)->toBe($payment->id);
    expect($sale->fresh()->outstanding_amount)->toBe(59.99);
    expect(fn () => $service->recordPayment($sale, '40.02', '2026-09-14', 'cash', null, null, $user->id, $key))
        ->toThrow(UnexpectedValueException::class, 'rincian pembayaran berbeda');
    $service->cancelPayment($payment, 'Salah catat', $manager->id);
    expect($record())->id->toBe($payment->id)->status->toBe('cancelled');
    expect($sale->fresh()->outstanding_amount)->toBe(100.0);
    $this->assertDatabaseCount('sale_payments', 1);
});

test('operator tidak dapat menyetujui pembatalan atau menutup periode', function (string $operation): void {
    ['sale' => $sale, 'deposit' => $deposit, 'user' => $user, 'customer' => $customer] = financialRecords();
    app(SalePostingService::class)->post($sale, $user->id);
    $payment = app(SalePaymentService::class)->recordPayment($sale, '10.00', '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid());
    $withdrawal = controlWithdrawal($customer);
    app(WithdrawalService::class)->post($withdrawal, $user->id);
    $action = match ($operation) {
        'deposit' => fn () => app(DepositService::class)->cancel($deposit, 'Koreksi', $user->id),
        'withdrawal' => fn () => app(WithdrawalService::class)->cancel($withdrawal, 'Koreksi', $user->id),
        'sale' => fn () => app(SalePostingService::class)->cancel($sale, $user->id, 'Koreksi'),
        'payment' => fn () => app(SalePaymentService::class)->cancelPayment($payment, 'Koreksi', $user->id),
        'period' => fn () => app(FinancialControlService::class)->closeThrough('2026-09-14', $user->id, 'Tutup'),
    };
    expect($action)->toThrow(UnexpectedValueException::class, 'tidak berwenang');
    expect($payment->fresh()->status)->toBe('posted');
    $this->assertDatabaseCount('financial_control_events', 0);
})->with(['deposit', 'withdrawal', 'sale', 'payment', 'period']);

test('verifikasi bukti memisahkan pencatat dan penyetuju serta refund membutuhkan bukti', function (): void {
    ['sale' => $sale, 'user' => $user, 'manager' => $manager] = financialRecords();
    app(SalePostingService::class)->post($sale, $user->id);
    $service = app(SalePaymentService::class);
    $payment = $service->recordPayment($sale, '50.00', '2026-09-14', 'transfer', 'TRF-001', null, $manager->id, (string) Str::uuid());
    expect(fn () => $service->verifyPayment($payment, 'RK-001', $manager->id))->toThrow(UnexpectedValueException::class, 'petugas berbeda');
    $reviewer = User::factory()->financeManager()->create();
    $service->verifyPayment($payment, 'RK-001 baris 3', $reviewer->id);
    expect(fn () => $service->cancelPayment($payment, 'Salah catat', $manager->id))->toThrow(UnexpectedValueException::class, 'pengembalian dana');
    expect(fn () => $service->cancelPayment($payment, 'Pengembalian', $manager->id, 'refund'))->toThrow(UnexpectedValueException::class, 'bukti pengembalian');
    $service->cancelPayment($payment, 'Pengembalian', $manager->id, 'refund', 'TRF-REFUND-001');
    expect($payment->fresh())->verification_reference->toBe('RK-001 baris 3')->verified_by->toBe($reviewer->id)
        ->cancellation_type->toBe('refund')->refund_reference->toBe('TRF-REFUND-001');
    expect($sale->fresh()->payment_status)->toBe('unpaid');
});

test('penutupan periode menolak pembayaran tanpa verifikasi dan mengunci tanggal lama', function (): void {
    ['sale' => $sale, 'user' => $user, 'manager' => $manager, 'deposit' => $deposit] = financialRecords();
    app(SalePostingService::class)->post($sale, $user->id);
    $payment = app(SalePaymentService::class)->recordPayment($sale, '10.00', '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid());
    $controls = app(FinancialControlService::class);
    expect(fn () => $controls->closeThrough('2026-09-14', $manager->id, 'Penutupan'))->toThrow(UnexpectedValueException::class, 'belum diverifikasi');
    app(SalePaymentService::class)->verifyPayment($payment, 'Kuitansi K-001', $manager->id);
    $controls->closeThrough('2026-09-14', $manager->id, 'Pemeriksaan K-001');
    $this->travelTo(now()->addDay());
    expect(fn () => app(DepositService::class)->cancel($deposit, 'Koreksi', $manager->id))->toThrow(UnexpectedValueException::class, 'sudah ditutup');
    expect(fn () => app(SalePaymentService::class)->recordPayment($sale, '10.00', '2026-09-14', 'cash', null, null, $user->id, (string) Str::uuid()))
        ->toThrow(UnexpectedValueException::class, 'sudah ditutup');
    expect(DB::table('financial_controls')->value('closed_through'))->toBe('2026-09-14');
    $this->assertDatabaseHas('financial_control_events', ['event_type' => 'period_closed', 'performed_by' => $manager->id]);
    expect(fn () => $controls->closeThrough('2026-09-13', $manager->id, 'Mundur'))->toThrow(UnexpectedValueException::class);
});

test('tanggal masa depan atau mundur sebelum stok terakhir tidak dapat diposting', function (string $date): void {
    ['sale' => $sale, 'user' => $user] = financialRecords();
    $sale->update(['transaction_date' => $date]);
    expect(fn () => app(SalePostingService::class)->post($sale, $user->id))->toThrow(UnexpectedValueException::class);
    expect($sale->fresh()->status)->toBe('draft');
    $this->assertDatabaseCount('inventory_movements', 1);
})->with(['2026-09-15', '2026-09-13']);

test('form setoran menghitung subtotal di server dan mengabaikan status browser', function (): void {
    ['user' => $user, 'customer' => $customer, 'waste' => $waste] = financialRecords();
    $this->actingAs($user);
    Livewire::test(CreateDeposit::class)->fillForm(['customer_id' => $customer->id, 'transaction_date' => '2026-09-14',
        'status' => 'posted', 'total_weight' => 999, 'total_amount' => 1,
        'items' => [['waste_type_id' => $waste->id, 'weight' => '1.005', 'price' => '10.00', 'subtotal' => '1.00']],
    ])->call('create')->assertHasNoFormErrors();
    $record = Deposit::query()->latest('id')->first();
    expect($record)->status->toBe('draft')->total_weight->toBe('1.005')->total_amount->toBe('10.05');
    expect($record->items()->sole()->subtotal)->toBe('10.05');
    $this->assertDatabaseCount('balance_mutations', 1);
});

test('form transaksi final dan penghapusan draft lama ditolak di server', function (): void {
    ['user' => $user, 'customer' => $customer, 'deposit' => $deposit] = financialRecords();
    $this->actingAs($user);
    Livewire::test(EditDeposit::class, ['record' => $deposit->id])->assertForbidden();
    $withdrawal = controlWithdrawal($customer);
    $stale = Livewire::test(EditWithdrawal::class, ['record' => $withdrawal->id]);
    $stale->fillForm(['amount' => '99.00', 'status' => 'cancelled']);
    app(WithdrawalService::class)->post($withdrawal, $user->id);
    $stale->call('save')->assertForbidden();
    expect(fn () => app(CustomerTransactionDraftService::class)->delete($withdrawal))->toThrow(ValidationException::class);
    expect($withdrawal->fresh())->amount->toBe('60.00')->status->toBe('posted');
});

test('tombol persetujuan disembunyikan dari operator', function (): void {
    ['user' => $user, 'sale' => $sale] = financialRecords();
    $this->actingAs($user);
    app(SalePostingService::class)->post($sale, $user->id);
    Livewire::test(ListSales::class)->assertActionHidden(TestAction::make('batalkanPenjualan')->table($sale))
        ->assertActionVisible(TestAction::make('catatPembayaran')->table($sale));
});

test('koreksi biaya lama tidak menambah perputaran fisik dan nilai historis mengikuti tanggal sumber', function (): void {
    ['waste' => $waste, 'user' => $user] = financialRecords();
    $original = InventoryMovement::query()->sole();
    InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'out', 'quantity' => '10.000',
        'unit_cost' => '10.00', 'total_cost' => '100.00', 'reference_type' => 'cost_reconciliation_reversal',
        'reference_id' => $original->id, 'transaction_date' => '2026-09-15']);
    $correction = InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '10.000',
        'unit_cost' => '12.00', 'total_cost' => '120.00', 'reference_type' => 'cost_reconciliation',
        'reference_id' => $original->id, 'transaction_date' => '2026-09-15']);
    expect(InventoryMovement::query()->physical()->sum('quantity'))->toEqual(10);
    $rows = InventoryMovement::query()->withStockReport()->get();
    expect($rows->pluck('running_quantity')->map(fn ($value): string => (string) $value)->all())->toBe(['10.000', '10.000', '10.000']);
    expect($rows->last()->effective_date)->toBe('2026-09-14');
    expect(app(InventoryService::class)->getExactBalance($waste->id, '2026-09-14'))->toMatchArray(['quantity' => '10.000', 'value' => '120.00']);
    expect($correction->fresh()->transaction_date->toDateString())->toBe('2026-09-15');
    $later = InventoryMovement::create(['waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '5.000', 'unit_cost' => '12.00', 'total_cost' => '60.00', 'transaction_date' => '2026-09-15']);
    expect((string) InventoryMovement::query()->withStockReport()->findOrFail($correction->id)->running_quantity)->toBe('10.000');
    $this->actingAs($user);
    Livewire::test(ListInventoryMovements::class)->filterTable('transaction_date', ['from' => '2026-09-14', 'until' => '2026-09-14'])
        ->assertCanSeeTableRecords([$original, $correction])->assertCanNotSeeTableRecords([$later]);
});

test('bukti penarikan harus diperiksa petugas berbeda dan dana kembali memiliki referensi', function (): void {
    ['customer' => $customer, 'user' => $user, 'manager' => $manager] = financialRecords();
    $withdrawal = controlWithdrawal($customer);
    $service = app(WithdrawalService::class);
    $service->post($withdrawal, $user->id);
    expect(fn () => app(FinancialControlService::class)->closeThrough('2026-09-14', $manager->id, 'Tutup'))
        ->toThrow(UnexpectedValueException::class, 'penarikan yang belum diverifikasi');
    $service->verifyWithdrawal($withdrawal, 'Tanda terima TT-001', $manager->id);
    expect(fn () => $service->cancel($withdrawal, 'Salah catat', $manager->id))->toThrow(UnexpectedValueException::class, 'bukti uang kembali');
    expect(fn () => $service->cancel($withdrawal, 'Dana kembali', $manager->id, 'refund'))->toThrow(UnexpectedValueException::class);
    $service->cancel($withdrawal, 'Dana kembali', $manager->id, 'refund', 'Kas masuk KM-001');
    expect($withdrawal->fresh())->verification_reference->toBe('Tanda terima TT-001')->refund_reference->toBe('Kas masuk KM-001')->cancellation_type->toBe('refund');
    expect(DB::transaction(fn () => app(CustomerBalanceService::class)->getLockedBalance($customer->id)))->toBe('100.00');
});

test('kegagalan ledger penarikan membatalkan status dan seluruh saldo', function (): void {
    ['customer' => $customer, 'user' => $user] = financialRecords();
    $withdrawal = controlWithdrawal($customer);
    BalanceMutation::creating(function (BalanceMutation $mutation): void {
        if ($mutation->reference_type === 'withdrawal') {
            throw new UnexpectedValueException('Simulasi kegagalan ledger.');
        }
    });
    try {
        expect(fn () => app(WithdrawalService::class)->post($withdrawal, $user->id))->toThrow(UnexpectedValueException::class, 'Simulasi');
        expect($withdrawal->fresh())->status->toBe('draft')->posted_at->toBeNull()->posted_by->toBeNull();
        expect(DB::transaction(fn () => app(CustomerBalanceService::class)->getLockedBalance($customer->id)))->toBe('100.00');
        $this->assertDatabaseCount('balance_mutations', 1);
    } finally {
        BalanceMutation::flushEventListeners();
    }
});

test('permintaan tidak dapat menaikkan peran lewat mass assignment', function (): void {
    ['user' => $user] = financialRecords();
    $user->fill(['financial_role' => 'administrator']);
    $user->save();
    expect($user->fresh()->financial_role)->toBe('operator');
    expect(fn () => app(FinancialControlService::class)->authorize('record', null))->toThrow(UnexpectedValueException::class);
});

test('ledger saldo dan stok tidak dapat diubah atau dihapus melalui model', function (): void {
    financialRecords();
    foreach ([BalanceMutation::query()->sole(), InventoryMovement::query()->sole()] as $ledger) {
        expect(fn () => $ledger->update(['description' => 'Menimpa riwayat']))->toThrow(UnexpectedValueException::class, 'tidak dapat diubah');
        expect(fn () => $ledger->delete())->toThrow(UnexpectedValueException::class, 'tidak dapat dihapus');
        $this->assertModelExists($ledger);
    }
});

test('pembayaran tanpa identitas permintaan ditolak sebelum menulis kuitansi', function (): void {
    ['sale' => $sale, 'user' => $user] = financialRecords();
    app(SalePostingService::class)->post($sale, $user->id);
    expect(fn () => app(SalePaymentService::class)->recordPayment($sale, '10.00', '2026-09-14', 'cash', null, null, $user->id))
        ->toThrow(UnexpectedValueException::class, 'Identitas permintaan');
    $this->assertDatabaseCount('sale_payments', 0);
});
