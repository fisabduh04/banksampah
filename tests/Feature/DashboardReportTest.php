<?php

use App\Filament\Pages\CashFlow;
use App\Filament\Pages\TrialBalance;
use App\Filament\Widgets\FinancialOverview;
use App\Filament\Widgets\MonthlyActivityChart;
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
use App\Services\DashboardReportingService;
use App\Services\DepositService;
use App\Services\JournalService;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use App\Services\WithdrawalService;
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
        'database.connections.dashboard_test' => [
            ...config('database.connections.mysql'), 'url' => null, 'database' => 'banksampah_testing',
        ],
        'database.default' => 'dashboard_test',
    ]);
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql'
        || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing') {
        throw new RuntimeException('Pengujian hanya boleh memakai MySQL banksampah_testing.');
    }
    $connection->beginTransaction();
    app(AccountSeeder::class)->run();
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(10, 0));
});

afterEach(function (): void {
    if (config('database.default') === 'dashboard_test' && DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
});

function dashboardCash(string $type = 'cash'): CashAccount
{
    return CashAccount::query()->create([
        'code' => 'DB-'.Str::ulid(), 'name' => 'Rekening dashboard uji', 'account_type' => $type, 'is_active' => true,
    ]);
}

function dashboardCashMutation(CashAccount $account, string $date, string $amount, string $type = 'in', string $reference = 'manual_receipt'): void
{
    CashMutation::query()->create([
        'cash_account_id' => $account->id, 'transaction_date' => $date, 'amount' => $amount,
        'mutation_type' => $type, 'reference_type' => $reference,
    ]);
}

function dashboardJournal(string $date, string $debit, string $credit, string $amount, string $reference = 'dashboard_test'): JournalEntry
{
    return app(JournalService::class)->post(
        transactionDate: $date, referenceType: $reference, referenceId: random_int(100000, 999999999),
        referenceNumber: null, description: 'Jurnal dashboard uji', userId: null,
        lines: [
            ['account_id' => Account::query()->where('system_key', $debit)->sole()->id, 'debit' => $amount, 'credit' => '0.00'],
            ['account_id' => Account::query()->where('system_key', $credit)->sole()->id, 'debit' => '0.00', 'credit' => $amount],
        ],
    );
}

function dashboardBalance(Customer $customer, string $date, string $amount, string $type, string $source): void
{
    BalanceMutation::query()->create([
        'customer_id' => $customer->id, 'transaction_date' => $date, 'amount' => $amount,
        'type' => $type, 'reference_type' => $source, 'reference_id' => random_int(100000, 999999999),
    ]);
}

test('kartu menampilkan saldo ledger presisi termasuk akun historis dan selisih GL per kelompok', function (): void {
    $cash = dashboardCash();
    $bank = dashboardCash('bank');
    $customer = Customer::query()->create(['customer_code' => 'DB-'.Str::ulid(), 'name' => 'Nasabah uji']);
    $waste = WasteType::query()->create(['code' => 'DB-'.Str::ulid(), 'name' => 'Sampah uji']);
    dashboardCashMutation($cash, '2026-09-24', '100.01');
    dashboardCashMutation($cash, '2026-09-25', '999.99');
    dashboardCashMutation($bank, '2026-09-24', '20.02');
    dashboardJournal('2026-09-24', 'cash', 'opening_balance', '100.01');
    dashboardJournal('2026-09-24', 'bank', 'opening_balance', '20.02');
    dashboardJournal('2026-09-24', 'inventory', 'customer_savings', '30.03');
    dashboardBalance($customer, '2026-09-24', '30.03', 'credit', 'deposit');
    InventoryMovement::query()->create([
        'waste_type_id' => $waste->id, 'movement_type' => 'in', 'quantity' => '1.000',
        'unit_cost' => '30.03', 'total_cost' => '30.03', 'transaction_date' => '2026-09-24', 'reference_type' => 'deposit',
    ]);
    $cash->update(['is_active' => false]);
    Account::query()->whereIn('system_key', ['cash', 'inventory'])->update(['is_active' => false, 'is_postable' => false]);
    $service = app(DashboardReportingService::class);

    $report = $service->snapshot('2026-09-24');

    expect(array_column($report['cards'], 'balance'))->toBe(['100.01', '20.02', '30.03', '30.03']);
    expect($report['balanced'])->toBeTrue();
    dashboardCashMutation($cash, '2026-09-24', '1.01');
    dashboardCashMutation($bank, '2026-09-24', '1.01', 'out');
    $mismatch = $service->snapshot('2026-09-24');
    expect($mismatch['cards']['cash']['difference'])->toBe('1.01');
    expect($mismatch['cards']['bank']['difference'])->toBe('-1.01');
    expect($mismatch['balanced'])->toBeFalse();
    Livewire::test(FinancialOverview::class, ['snapshot' => $mismatch])
        ->assertSee('Rekonsiliasi perlu diperiksa')->assertDontSee('Rekonsiliasi seimbang')
        ->assertSee('Ada saldo yang berbeda dengan Buku Besar.')
        ->assertSee('Rp 1,01')->assertSee('Rp -1,01');
});

test('enam bulan melintasi tahun dan membatasi awal bulan serta hari laporan dengan pembalik pada bulan sendiri', function (): void {
    $customer = Customer::query()->create(['customer_code' => 'DB-'.Str::ulid(), 'name' => 'Nasabah uji']);
    $cash = dashboardCash();
    foreach ([['2025-08-31', '999.00'], ['2025-09-01', '10.01'], ['2025-12-31', '20.02'],
        ['2026-01-01', '30.03'], ['2026-02-15', '40.04'], ['2026-02-16', '888.00']] as [$date, $amount]) {
        dashboardBalance($customer, $date, $amount, 'credit', 'deposit');
    }
    dashboardBalance($customer, '2026-02-01', '20.02', 'debit', 'deposit_cancellation');
    dashboardBalance($customer, '2026-01-31', '5.05', 'debit', 'withdrawal');
    dashboardBalance($customer, '2026-02-01', '5.05', 'credit', 'withdrawal_cancellation');
    $sale = dashboardJournal('2026-01-31', 'collector_receivable', 'sales_revenue', '60.06', 'sale');
    app(JournalService::class)->reverse(
        journalEntry: $sale, transactionDate: '2026-02-01', referenceType: 'sale_cancellation', referenceId: $sale->id,
        referenceNumber: null, description: 'Pembalik uji', userId: User::factory()->create()->id,
    );
    dashboardJournal('2026-01-10', 'cash', 'sales_revenue', '123.00', 'manual_receipt');
    dashboardJournal('2026-02-16', 'collector_receivable', 'sales_revenue', '888.00', 'sale');
    $draft = dashboardJournal('2026-01-10', 'collector_receivable', 'sales_revenue', '222.00', 'sale');
    $draft->update(['status' => 'draft']);
    dashboardCashMutation($cash, '2026-01-31', '15.01', 'in', 'sale_payment');
    dashboardCashMutation($cash, '2026-02-01', '15.01', 'out', 'sale_payment_cancellation');
    dashboardCashMutation($cash, '2026-02-01', '777.00', 'in', 'transfer');

    $report = app(DashboardReportingService::class)->snapshot('2026-02-15');

    expect($report['months'])->toBe(['2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02']);
    expect($report['series'])->toBe([
        'deposits' => ['10.01', '0.00', '0.00', '20.02', '30.03', '20.02'],
        'withdrawals' => ['0.00', '0.00', '0.00', '0.00', '5.05', '-5.05'],
        'sales' => ['0.00', '0.00', '0.00', '0.00', '60.06', '-60.06'],
        'payments' => ['0.00', '0.00', '0.00', '0.00', '15.01', '-15.01'],
    ]);
});

test('transaksi bisnis dan pembatalan nyata mempertahankan bulan asal pada dashboard', function (): void {
    $this->travelTo(now()->setDate(2026, 8, 31));
    $user = User::factory()->create();
    $customer = Customer::query()->create(['customer_code' => 'DB-'.Str::ulid(), 'name' => 'Nasabah uji']);
    $waste = WasteType::query()->create(['code' => 'DB-'.Str::ulid(), 'name' => 'Sampah uji', 'is_active' => true]);
    $deposit = Deposit::query()->create([
        'deposit_number' => 'DB-'.Str::ulid(), 'customer_id' => $customer->id, 'transaction_date' => '2026-08-30',
        'status' => 'draft', 'total_weight' => '10.000', 'total_amount' => '100.00',
    ]);
    $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
    app(DepositService::class)->post($deposit);
    $collector = Collector::query()->create(['code' => 'DB-'.Str::ulid(), 'name' => 'Pengepul uji', 'is_active' => true]);
    $sale = Sale::query()->create([
        'sale_number' => 'DB-'.Str::ulid(), 'collector_id' => $collector->id, 'transaction_date' => '2026-08-31',
        'status' => 'draft', 'total_weight' => '4.000', 'total_amount' => '60.00', 'payment_status' => 'unpaid',
    ]);
    $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => '4.000', 'price' => '15.00', 'subtotal' => '60.00']);
    app(SalePostingService::class)->post($sale, $user->id);
    $bank = dashboardCash('bank');
    $payment = app(SalePaymentService::class)->recordPayment(
        sale: $sale, amount: '20.00', paymentDate: '2026-08-31', paymentMethod: 'transfer',
        referenceNumber: null, notes: null, userId: $user->id, idempotencyKey: (string) Str::uuid(), cashAccountId: $bank->id,
    );
    $withdrawal = Withdrawal::query()->create([
        'withdrawal_number' => 'DB-'.Str::ulid(), 'customer_id' => $customer->id, 'cash_account_id' => $bank->id,
        'transaction_date' => '2026-08-31', 'amount' => '10.00', 'status' => 'draft',
    ]);
    app(WithdrawalService::class)->post($withdrawal, $user->id);
    $service = app(DashboardReportingService::class);
    $before = $service->snapshot('2026-08-31');
    $this->travelTo(now()->setDate(2026, 9, 1));
    app(WithdrawalService::class)->cancel($withdrawal, 'Koreksi penarikan uji', $user->id, true);
    app(SalePaymentService::class)->cancelPayment($payment, 'Koreksi pembayaran uji', $user->id, true);
    app(SalePostingService::class)->cancel($sale, $user->id, 'Koreksi penjualan uji', true);
    app(DepositService::class)->cancel($deposit, 'Koreksi setoran uji', $user->id, true);

    $historical = $service->snapshot('2026-08-31');
    $current = $service->snapshot('2026-09-01');

    expect($historical)->toBe($before);
    expect(array_column($historical['cards'], 'balance'))->toBe(['0.00', '10.00', '90.00', '60.00']);
    expect($current['series']['deposits'])->toBe(['0.00', '0.00', '0.00', '0.00', '100.00', '-100.00']);
    expect($current['series']['sales'])->toBe(['0.00', '0.00', '0.00', '0.00', '60.00', '-60.00']);
    expect($current['series']['payments'])->toBe(['0.00', '0.00', '0.00', '0.00', '20.00', '-20.00']);
    expect($current['series']['withdrawals'])->toBe(['0.00', '0.00', '0.00', '0.00', '10.00', '-10.00']);
    expect(array_column($current['cards'], 'balance'))->toBe(['0.00', '0.00', '0.00', '0.00']);
    expect($current['balanced'])->toBeTrue();
});

test('data kosong menghasilkan empat kartu nol dan enam bulan kosong tanpa menganggap neto nol sebagai tanpa aktivitas', function (): void {
    $service = app(DashboardReportingService::class);
    $empty = $service->snapshot('2026-09-24');
    expect(array_column($empty['cards'], 'balance'))->toBe(['0.00', '0.00', '0.00', '0.00']);
    expect($empty['series']['sales'])->toBe(array_fill(0, 6, '0.00'));
    expect($empty['activity'])->toBe(['deposits' => false, 'withdrawals' => false, 'sales' => false, 'payments' => false]);
    Livewire::test(MonthlyActivityChart::class, ['snapshot' => $empty])->assertSee('Belum ada aktivitas pada periode ini');
    Livewire::test(MonthlyActivityChart::class, ['snapshot' => $empty, 'category' => 'sales'])
        ->assertSee('Belum ada penjualan atau pembayaran.')
        ->assertSee('fi-compact', false)
        ->assertDontSee('min-height: 280px', false);
    $customer = Customer::query()->create(['customer_code' => 'DB-'.Str::ulid(), 'name' => 'Nasabah uji']);
    dashboardBalance($customer, '2026-09-01', '10.00', 'credit', 'deposit');
    dashboardBalance($customer, '2026-09-02', '10.00', 'debit', 'deposit_cancellation');
    $active = $service->snapshot('2026-09-24');
    expect($active['series']['deposits'])->toBe(array_fill(0, 6, '0.00'));
    expect($active['activity']['deposits'])->toBeTrue();
    Livewire::test(MonthlyActivityChart::class, ['snapshot' => $active])->assertDontSee('Belum ada aktivitas pada periode ini');
});

test('presisi seri dan kartu tidak hilang pada nominal besar', function (): void {
    $cash = dashboardCash();
    dashboardCashMutation($cash, '2026-09-01', '9999999999999.99', 'in', 'sale_payment');
    dashboardCashMutation($cash, '2026-09-01', '9999999999999.99', 'in', 'sale_payment');
    dashboardCashMutation($cash, '2026-09-02', '0.01', 'out', 'sale_payment_cancellation');

    $report = app(DashboardReportingService::class)->snapshot('2026-09-24');

    expect($report['cards']['cash']['balance'])->toBe('19999999999999.97');
    expect($report['series']['payments'][5])->toBe('19999999999999.97');
    Livewire::test(FinancialOverview::class, ['snapshot' => $report])->assertSee('19.999.999.999.999,97')
        ->assertSee('Rekonsiliasi perlu diperiksa')->assertSee('Selisih dengan GL')
        ->assertSee('@4xl:fi-grid-cols', false)->assertSee('--cols-c4xl: repeat(4, minmax(0, 1fr));', false);
    $chart = Livewire::test(MonthlyActivityChart::class, ['snapshot' => $report, 'category' => 'sales'])->instance();
    $data = (new ReflectionMethod($chart, 'getData'))->invoke($chart);
    expect($data['datasets'][1]['data'][5])->toBe('19999999999999.97');
});

test('jumlah query snapshot tetap dan widget tidak menjalankan query laporan sendiri', function (): void {
    $service = app(DashboardReportingService::class);
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();
    $service->snapshot('2026-09-24');
    $initialCount = count(DB::connection()->getQueryLog());
    $cash = dashboardCash();
    foreach (range(1, 20) as $index) {
        dashboardCashMutation($cash, '2026-09-01', '0.01');
    }
    DB::connection()->flushQueryLog();
    $report = $service->snapshot('2026-09-24');
    $queries = DB::connection()->getQueryLog();
    expect(count($queries))->toBe($initialCount)->toBeLessThanOrEqual(25);
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    DB::connection()->flushQueryLog();
    Livewire::test(FinancialOverview::class, ['snapshot' => $report]);
    Livewire::test(MonthlyActivityChart::class, ['snapshot' => $report]);
    expect(DB::connection()->getQueryLog())->toBeEmpty();
    DB::connection()->disableQueryLog();
});

test('halaman dashboard memerlukan login dan membagikan satu snapshot ke seluruh widget', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->actingAs(User::factory()->create());
    $snapshot = app(DashboardReportingService::class)->snapshot('2026-09-24');
    $this->mock(DashboardReportingService::class)->shouldReceive('snapshot')->once()->with('2026-09-24')->andReturn($snapshot);

    $this->get('/admin')->assertOk()->assertSee('Kas Tunai')->assertSee('Tabungan Nasabah')
        ->assertSee('Nilai Persediaan')->assertSee('Rekonsiliasi seimbang')
        ->assertSee('Ringkasan saldo')->assertSee('Per 24 Sep 2026')->assertDontSee('Ringkasan keuangan')
        ->assertSeeInOrder(['Ringkasan saldo', 'Per 24 Sep 2026', 'Rekonsiliasi seimbang'])
        ->assertSee('Sesuai GL')->assertDontSee('Cocok dengan GL')
        ->assertSee('--cols-c4xl: repeat(2, minmax(0, 1fr));', false)
        ->assertSee('Setoran dan Penarikan')->assertSee('Penjualan dan Pembayaran Pengepul')
        ->assertSee('bukan pendapatan')->assertSee('Belum ada aktivitas pada periode ini')
        ->assertSee('Perbarui')->assertSee('Laporan')
        ->assertSee(CashFlow::getUrl())
        ->assertSee(TrialBalance::getUrl());
});

test('tanggal tidak valid ditolak tanpa pembacaan ledger', function (): void {
    expect(fn () => app(DashboardReportingService::class)->snapshot('2026-02-30'))
        ->toThrow(InvalidArgumentException::class, 'Tanggal dashboard tidak valid.');
});
