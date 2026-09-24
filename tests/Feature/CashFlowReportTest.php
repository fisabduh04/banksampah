<?php

use App\Filament\Pages\CashFlow;
use App\Models\Account;
use App\Models\BalanceMutation;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Models\Withdrawal;
use App\Services\CashFlowReportingService;
use App\Services\DepositService;
use App\Services\JournalService;
use App\Services\ReconciliationService;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use App\Services\WithdrawalService;
use Brick\Math\BigDecimal;
use Database\Seeders\AccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }
    config([
        'database.connections.cash_flow_test' => [
            ...config('database.connections.mysql'), 'url' => null, 'database' => 'banksampah_testing',
        ],
        'database.default' => 'cash_flow_test',
    ]);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql'
        || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian hanya boleh memakai MySQL banksampah_testing.');
    }
    $connection->beginTransaction();
    app(AccountSeeder::class)->run();
    $this->travelTo(now()->setDate(2026, 9, 3)->setTime(10, 0));
});

afterEach(function (): void {
    if (config('database.default') === 'cash_flow_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

function cashFlowAccount(string $type = 'cash'): CashAccount
{
    return CashAccount::query()->create([
        'code' => 'CF-'.Str::ulid(), 'name' => 'Rekening laporan uji',
        'account_type' => $type, 'is_active' => true,
    ]);
}

function cashFlowMutation(CashAccount $account, string $date, string $type, string $amount, string $source = 'manual_receipt'): CashMutation
{
    return CashMutation::query()->create([
        'cash_account_id' => $account->id, 'transaction_date' => $date,
        'mutation_type' => $type, 'amount' => $amount, 'reference_type' => $source,
    ]);
}

function cashFlowJournal(string $date, string $debit, string $credit, string $amount): JournalEntry
{
    return app(JournalService::class)->post(
        transactionDate: $date, referenceType: 'cash_flow_test_'.Str::ulid(), referenceId: 1,
        referenceNumber: null, description: 'Jurnal laporan uji', userId: null,
        lines: [
            ['account_id' => Account::query()->where('system_key', $debit)->sole()->id, 'debit' => $amount, 'credit' => '0.00'],
            ['account_id' => Account::query()->where('system_key', $credit)->sole()->id, 'debit' => '0.00', 'credit' => $amount],
        ],
    );
}

test('arus kas memakai batas tanggal inklusif rekening historis dan cocok dengan rekonsiliasi kas bank', function (): void {
    $cash = cashFlowAccount();
    $second = cashFlowAccount();
    cashFlowAccount('bank');
    foreach ([['2026-07-31', 'in', '100.10'], ['2026-08-01', 'in', '50.20'],
        ['2026-08-31', 'out', '20.03'], ['2026-09-01', 'in', '999.99']] as [$date, $type, $amount]) {
        cashFlowMutation($cash, $date, $type, $amount);
        cashFlowJournal($date, $type === 'in' ? 'cash' : 'operating_expense', $type === 'in' ? 'opening_balance' : 'cash', $amount);
    }
    cashFlowMutation($second, '2026-08-31', 'in', '0.01');
    cashFlowJournal('2026-08-31', 'cash', 'opening_balance', '0.01');
    $cash->update(['is_active' => false]);
    Account::query()->where('system_key', 'cash')->update(['is_active' => false, 'is_postable' => false]);

    $report = app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31');
    $reconciliation = app(ReconciliationService::class)->cashAndBankAsOf('2026-08-31');

    expect($report['totals'])->toMatchArray([
        'opening' => '100.10', 'receipts' => '50.21', 'payments' => '20.03',
        'net_change' => '30.18', 'closing' => '130.28', 'difference' => '0.00',
    ]);
    expect($report['balanced'])->toBeTrue();
    foreach (['cash', 'bank'] as $type) {
        expect($report['groups'][$type]['closing'])->toBe($reconciliation['groups'][$type]['cash_balance']);
        expect($report['groups'][$type]['gl_closing'])->toBe($reconciliation['groups'][$type]['gl_balance']);
    }
    expect($report['groups']['bank']['closing'])->toBe('0.00');
    expect((string) BigDecimal::of($report['totals']['opening'])->plus($report['totals']['net_change']))
        ->toBe($report['totals']['closing']);
});

test('transfer antar rekening termasuk sesama kas tidak menggandakan penerimaan dan pengeluaran', function (): void {
    $cash = cashFlowAccount();
    $second = cashFlowAccount();
    $bank = cashFlowAccount('bank');
    cashFlowMutation($cash, '2026-07-31', 'in', '100.00', 'opening_balance');
    cashFlowJournal('2026-07-31', 'cash', 'opening_balance', '100.00');
    cashFlowMutation($cash, '2026-08-01', 'out', '30.00', 'transfer');
    cashFlowMutation($bank, '2026-08-01', 'in', '30.00', 'transfer');
    cashFlowJournal('2026-08-01', 'bank', 'cash', '30.00');
    cashFlowMutation($cash, '2026-08-02', 'out', '20.00', 'transfer');
    cashFlowMutation($second, '2026-08-02', 'in', '20.00', 'transfer');

    $report = app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31');

    expect($report['totals'])->toMatchArray([
        'receipts' => '0.00', 'payments' => '0.00', 'transfer_in' => '50.00',
        'transfer_out' => '50.00', 'net_change' => '0.00', 'closing' => '100.00',
    ]);
    expect($report['rows'])->toBeEmpty();
    expect($report['groups']['cash']['closing'])->toBe('70.00');
    expect($report['groups']['bank']['closing'])->toBe('30.00');
    expect($report['balanced'])->toBeTrue();
});

test('transfer lintas periode atau satu sisi tetap masuk jembatan saldo dan ditandai', function (): void {
    $cash = cashFlowAccount();
    $bank = cashFlowAccount('bank');
    cashFlowMutation($cash, '2026-08-01', 'in', '100.00');
    cashFlowMutation($cash, '2026-08-31', 'out', '30.00', 'transfer');
    cashFlowMutation($bank, '2026-09-01', 'in', '30.00', 'transfer');
    $service = app(CashFlowReportingService::class);

    $august = $service->cashFlow('2026-08-31', '2026-08-31');
    $september = $service->cashFlow('2026-09-01', '2026-09-01');

    expect($august['totals'])->toMatchArray(['opening' => '100.00', 'net_change' => '-30.00', 'closing' => '70.00', 'payments' => '0.00']);
    expect($september['totals'])->toMatchArray(['opening' => '70.00', 'net_change' => '30.00', 'closing' => '100.00', 'receipts' => '0.00']);
    expect($august['transfer_balanced'])->toBeFalse();
    expect($september['transfer_balanced'])->toBeFalse();
});

test('setoran dan penjualan non tunai tidak menjadi arus kas dan pembatalan mengikuti tanggal mutasi pembalik', function (): void {
    $this->travelTo(now()->setDate(2026, 8, 31));
    $user = User::factory()->create();
    $customer = Customer::query()->create(['customer_code' => 'CF-'.Str::ulid(), 'name' => 'Nasabah uji']);
    $waste = WasteType::query()->create(['code' => 'CF-'.Str::ulid(), 'name' => 'Sampah uji', 'is_active' => true]);
    $deposit = Deposit::query()->create([
        'deposit_number' => 'CF-'.Str::ulid(), 'customer_id' => $customer->id,
        'transaction_date' => '2026-08-30', 'status' => 'draft', 'total_weight' => '10.000', 'total_amount' => '100.00',
    ]);
    $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
    app(DepositService::class)->post($deposit);
    $collector = Collector::query()->create(['code' => 'CF-'.Str::ulid(), 'name' => 'Pengepul uji', 'is_active' => true]);
    $sale = Sale::query()->create([
        'sale_number' => 'CF-'.Str::ulid(), 'collector_id' => $collector->id, 'transaction_date' => '2026-08-31',
        'status' => 'draft', 'total_weight' => '4.000', 'total_amount' => '60.00', 'payment_status' => 'unpaid',
    ]);
    $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => '4.000', 'price' => '15.00', 'subtotal' => '60.00']);
    app(SalePostingService::class)->post($sale, $user->id);
    $service = app(CashFlowReportingService::class);
    expect($service->cashFlow('2026-08-01', '2026-08-31')['totals']['receipts'])->toBe('0.00');
    $bank = cashFlowAccount('bank');
    $payment = app(SalePaymentService::class)->recordPayment(
        sale: $sale, amount: '60.00', paymentDate: '2026-08-31', paymentMethod: 'transfer',
        referenceNumber: null, notes: null, userId: $user->id, idempotencyKey: (string) Str::uuid(), cashAccountId: $bank->id,
    );
    $this->travelTo(now()->setDate(2026, 9, 1));
    app(SalePaymentService::class)->cancelPayment($payment, 'Koreksi transaksi uji', $user->id, true);
    app(SalePostingService::class)->cancel($sale, $user->id, 'Koreksi transaksi uji', true);
    app(DepositService::class)->cancel($deposit, 'Koreksi transaksi uji', $user->id, true);

    $august = $service->cashFlow('2026-08-01', '2026-08-31');
    $september = $service->cashFlow('2026-09-01', '2026-09-01');

    expect($august['totals'])->toMatchArray(['receipts' => '60.00', 'payments' => '0.00', 'transfer_in' => '0.00', 'closing' => '60.00']);
    expect($september['totals'])->toMatchArray(['opening' => '60.00', 'payments' => '60.00', 'closing' => '0.00']);
    expect($august['operations']['deposit'])->toMatchArray(['quantity_in' => '10.000', 'value_in' => '100.00']);
    expect($august['operations']['sale'])->toMatchArray(['quantity_out' => '4.000', 'value_out' => '40.00']);
    expect($september['operations']['sale_cancellation'])->toMatchArray(['quantity_in' => '4.000', 'value_in' => '40.00']);
    expect($september['operations']['deposit_cancellation'])->toMatchArray(['quantity_out' => '10.000', 'value_out' => '100.00']);
    expect($august['balanced'])->toBeTrue();
    expect($september['balanced'])->toBeTrue();
    expect($august['groups']['bank']['closing'])->toBe(app(ReconciliationService::class)->cashAndBankAsOf('2026-08-31')['groups']['bank']['cash_balance']);
});

test('selisih antarkelompok tidak disembunyikan meskipun total gabungan cocok dan pembacaan tidak menulis', function (): void {
    cashFlowMutation(cashFlowAccount(), '2026-08-01', 'in', '10.01', 'legacy_other');
    cashFlowJournal('2026-08-01', 'bank', 'opening_balance', '10.01');
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();
    try {
        $report = app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31');
        $queries = DB::connection()->getQueryLog();
    } finally {
        DB::connection()->disableQueryLog();
    }
    expect($report['totals']['difference'])->toBe('0.00');
    expect($report['groups']['cash']['difference'])->toBe('10.01');
    expect($report['groups']['bank']['difference'])->toBe('-10.01');
    expect($report['balanced'])->toBeFalse();
    expect($report['rows']['legacy_other']['receipts'])->toBe('10.01');
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
});

test('nilai besar tetap presisi desimal', function (): void {
    $cash = cashFlowAccount();
    cashFlowMutation($cash, '2026-08-01', 'in', '9999999999999.99');
    cashFlowMutation($cash, '2026-08-01', 'in', '9999999999999.99');
    cashFlowMutation($cash, '2026-08-31', 'out', '0.01');

    $report = app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31');

    expect($report['totals']['closing'])->toBe('19999999999999.97');
});

test('pencatatan saldo awal dalam periode terpisah dari penerimaan eksternal', function (): void {
    cashFlowMutation(cashFlowAccount(), '2026-08-01', 'in', '100.00', 'opening_balance');
    cashFlowJournal('2026-08-01', 'cash', 'opening_balance', '100.00');

    $report = app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31');

    expect($report['totals'])->toMatchArray([
        'opening' => '0.00', 'receipts' => '0.00', 'opening_entries_net' => '100.00',
        'net_change' => '100.00', 'closing' => '100.00',
    ]);
    expect($report['balanced'])->toBeTrue();
});

test('penarikan dan pembatalannya lintas bulan mempertahankan arus dan saldo historis', function (): void {
    $cash = cashFlowAccount();
    cashFlowMutation($cash, '2026-07-31', 'in', '100.00', 'opening_balance');
    cashFlowJournal('2026-07-31', 'cash', 'opening_balance', '100.00');
    $user = User::factory()->create();
    $customer = Customer::query()->create(['customer_code' => 'CF-'.Str::ulid(), 'name' => 'Nasabah uji']);
    BalanceMutation::query()->create([
        'customer_id' => $customer->id, 'type' => 'credit', 'amount' => '100.00',
        'transaction_date' => '2026-07-31', 'reference_type' => 'opening_balance', 'reference_id' => $customer->id,
    ]);
    $withdrawal = Withdrawal::query()->create([
        'withdrawal_number' => 'CF-'.Str::ulid(), 'customer_id' => $customer->id, 'cash_account_id' => $cash->id,
        'transaction_date' => '2026-08-31', 'amount' => '40.05', 'status' => 'draft',
    ]);
    app(WithdrawalService::class)->post($withdrawal, $user->id);
    $this->travelTo(now()->setDate(2026, 9, 1));
    app(WithdrawalService::class)->cancel($withdrawal, 'Koreksi penarikan uji', $user->id, true);
    $service = app(CashFlowReportingService::class);

    $august = $service->cashFlow('2026-08-01', '2026-08-31');
    $september = $service->cashFlow('2026-09-01', '2026-09-01');

    expect($august['rows']['withdrawal']['payments'])->toBe('40.05');
    expect($august['totals']['closing'])->toBe('59.95');
    expect($september['rows']['withdrawal_cancellation']['receipts'])->toBe('40.05');
    expect($september['totals'])->toMatchArray(['opening' => '59.95', 'closing' => '100.00']);
    expect($august['balanced'])->toBeTrue();
    expect($september['balanced'])->toBeTrue();
});

test('ringkasan biaya mempertahankan snapshot nol dan koreksi tanpa berat', function (): void {
    $waste = WasteType::query()->create(['code' => 'CF-'.Str::ulid(), 'name' => 'Sampah historis uji']);
    foreach ([['deposit', '10.000', '0.00'], ['cost_reconciliation', '0.000', '100.01']] as [$source, $quantity, $cost]) {
        InventoryMovement::query()->create([
            'waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => $quantity,
            'unit_cost' => '0.00', 'total_cost' => $cost, 'reference_type' => $source, 'transaction_date' => '2026-08-31',
        ]);
    }

    $report = app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31');

    expect($report['operations']['deposit'])->toMatchArray(['quantity_in' => '10.000', 'value_in' => '0.00']);
    expect($report['operations']['cost_reconciliation'])->toMatchArray(['quantity_in' => '0.000', 'value_in' => '100.01']);
    expect($report['totals']['receipts'])->toBe('0.00');
});

test('mutasi persediaan tidak valid menghentikan ringkasan', function (array $attributes): void {
    $waste = WasteType::query()->create(['code' => 'CF-'.Str::ulid(), 'name' => 'Sampah uji']);
    InventoryMovement::query()->create([
        'waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '1.000',
        'unit_cost' => '0.00', 'total_cost' => '0.00', 'reference_type' => 'deposit', 'transaction_date' => '2026-08-31',
        ...$attributes,
    ]);

    expect(fn () => app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31'))->toThrow(RuntimeException::class);
})->with([[['movement_type' => 'adjustment']], [['quantity' => '-0.001']], [['total_cost' => '-0.01']]]);

test('rekening dengan jenis asing ditolak termasuk tanpa transaksi', function (): void {
    cashFlowAccount('other');

    expect(fn () => app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31'))->toThrow(RuntimeException::class);
});

test('selisih awal dan neraca saldo tidak seimbang tetap ditandai ketika saldo akhir cocok', function (): void {
    cashFlowMutation(cashFlowAccount(), '2026-07-31', 'in', '100.00');
    cashFlowJournal('2026-08-01', 'cash', 'opening_balance', '100.00');
    $unbalanced = cashFlowJournal('2026-08-01', 'operating_expense', 'opening_balance', '1.00');
    $unbalanced->lines()->where('debit', '>', '0')->update(['debit' => '2.00']);

    $report = app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31');

    expect($report['groups']['cash'])->toMatchArray(['opening_difference' => '100.00', 'difference' => '0.00', 'balanced' => false]);
    expect($report['trial_balance_balanced'])->toBeFalse();
    expect($report['balanced'])->toBeFalse();
});

test('mutasi kas tidak valid ditolak', function (string $type, string $amount): void {
    cashFlowMutation(cashFlowAccount(), '2026-08-01', $type, $amount);

    expect(fn () => app(CashFlowReportingService::class)->cashFlow('2026-08-01', '2026-08-31'))->toThrow(RuntimeException::class);
})->with([['IN', '1.00'], ['in', '-0.01'], ['out', '0.00']]);

test('periode tidak valid tidak menghasilkan angka laporan', function (?string $start, ?string $end): void {
    $report = app(CashFlowReportingService::class)->cashFlow($start, $end);

    expect($report['valid'])->toBeFalse();
    expect($report['rows'])->toBeEmpty();
    expect($report['totals'])->toBeEmpty();
})->with([[null, '2026-08-31'], ['2026-02-30', '2026-08-31'], ['2026-09-01', '2026-08-31']]);

test('halaman arus kas memfilter periode dan menampilkan selisih tanpa menghilangkan angka', function (): void {
    $this->get('/admin/cash-flow')->assertRedirect('/admin/login');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->actingAs(User::factory()->create());
    cashFlowMutation(cashFlowAccount(), '2026-08-31', 'in', '100.05');

    Livewire::test(CashFlow::class)
        ->set('startDate', '2026-08-01')->set('endDate', '2026-08-31')
        ->assertSee('Peringatan: laporan perlu diperiksa')->assertSee('100,05')
        ->assertCanSeeTableRecords(['manual_receipt'])
        ->set('endDate', '2026-08-30')->assertCanNotSeeTableRecords(['manual_receipt'])
        ->set('startDate', '2026-09-01')->assertSee('Periode tidak valid.');
});
