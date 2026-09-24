<?php

use App\Models\Account;
use App\Models\BalanceMutation;
use App\Models\Customer;
use App\Models\User;
use App\Services\JournalService;
use App\Services\ReconciliationService;
use Brick\Math\BigDecimal;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // Semua penulisan selama test dibatasi ke database testing.
    if (! app()->environment('testing')) {
        throw new RuntimeException(
            'Pengujian hanya boleh berjalan dalam environment testing.'
        );
    }

    config([
        'database.connections.savings_reconciliation_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'savings_reconciliation_test',
    ]);

    $connection = DB::connection();

    if (
        $connection->getDriverName() !== 'mysql'
        || $connection->selectOne('SELECT DATABASE() AS name')->name
            !== 'banksampah_testing'
    ) {
        throw new RuntimeException(
            'Pengujian hanya boleh memakai MySQL banksampah_testing.'
        );
    }

    $connection->beginTransaction();
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') === 'savings_reconciliation_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

test('rekonsiliasi tabungan membandingkan GL dan mutasi sampai tanggal laporan', function (): void {
    $previousDate = now()->subDay()->toDateString();
    $reportDate = now()->toDateString();
    $futureDate = now()->addDay()->toDateString();

    $user = User::factory()->create();
    $customer = Customer::query()->create([
        'customer_code' => 'RC-'.Str::ulid(),
        'name' => 'Nasabah Rekonsiliasi',
    ]);

    $cash = Account::query()->where('system_key', 'cash')->sole();
    $savings = Account::query()
        ->where('system_key', 'customer_savings')->sole();

    $journal = app(JournalService::class);
    $service = app(ReconciliationService::class);

    // Catat baseline agar test tetap benar jika database testing punya histori.
    $baselinePrevious = $service->customerSavingsAsOf($previousDate);
    $baselineCurrent = $service->customerSavingsAsOf($reportDate);
    $plus = fn (string $amount, string $increment): string => (string) BigDecimal::of($amount)->plus($increment)->toScale(2);

    // Setoran sebelum tanggal laporan menambah mutasi dan kewajiban GL.
    BalanceMutation::query()->create([
        'customer_id' => $customer->id,
        'type' => 'credit',
        'amount' => '100.05',
        'transaction_date' => $previousDate,
        'reference_type' => 'savings_reconciliation_credit_test',
        'reference_id' => $customer->id,
    ]);

    $journal->post(
        transactionDate: $previousDate,
        referenceType: 'savings_reconciliation_credit_test',
        referenceId: $customer->id,
        referenceNumber: null,
        description: 'Setoran uji rekonsiliasi',
        userId: $user->id,
        lines: [
            ['account_id' => $cash->id, 'debit' => '100.05', 'credit' => '0.00'],
            ['account_id' => $savings->id, 'debit' => '0.00', 'credit' => '100.05'],
        ]
    );

    // Penarikan pada tanggal akhir harus masuk pada kedua sisi.
    BalanceMutation::query()->create([
        'customer_id' => $customer->id,
        'type' => 'debit',
        'amount' => '40.04',
        'transaction_date' => $reportDate,
        'reference_type' => 'savings_reconciliation_debit_test',
        'reference_id' => $customer->id,
    ]);

    $journal->post(
        transactionDate: $reportDate,
        referenceType: 'savings_reconciliation_debit_test',
        referenceId: $customer->id,
        referenceNumber: null,
        description: 'Penarikan uji rekonsiliasi',
        userId: $user->id,
        lines: [
            ['account_id' => $savings->id, 'debit' => '40.04', 'credit' => '0.00'],
            ['account_id' => $cash->id, 'debit' => '0.00', 'credit' => '40.04'],
        ]
    );

    // Mutasi masa depan tidak boleh mengubah laporan hari ini.
    BalanceMutation::query()->create([
        'customer_id' => $customer->id,
        'type' => 'credit',
        'amount' => '999.99',
        'transaction_date' => $futureDate,
    ]);

    $previous = $service->customerSavingsAsOf($previousDate);
    $current = $service->customerSavingsAsOf($reportDate);

    expect($previous['gl_balance'])
        ->toBe($plus($baselinePrevious['gl_balance'], '100.05'));
    expect($previous['customer_balance'])
        ->toBe($plus($baselinePrevious['customer_balance'], '100.05'));
    expect($previous['difference'])->toBe($baselinePrevious['difference']);
    expect($current['gl_balance'])
        ->toBe($plus($baselineCurrent['gl_balance'], '60.01'));
    expect($current['customer_balance'])
        ->toBe($plus($baselineCurrent['customer_balance'], '60.01'));
    expect($current['difference'])->toBe($baselineCurrent['difference']);
    expect($current['balanced'])->toBe($baselineCurrent['balanced']);

    // Satu mutasi tanpa jurnal harus terlihat sebagai selisih satu sen.
    BalanceMutation::query()->create([
        'customer_id' => $customer->id,
        'type' => 'credit',
        'amount' => '0.01',
        'transaction_date' => $reportDate,
    ]);

    $mismatch = $service->customerSavingsAsOf($reportDate);

    expect($mismatch['gl_balance'])->toBe($current['gl_balance']);
    expect($mismatch['customer_balance'])
        ->toBe($plus($current['customer_balance'], '0.01'));
    expect($mismatch['difference'])
        ->toBe($plus($baselineCurrent['difference'], '0.01'));
    expect(fn () => $service->customerSavingsAsOf('tanggal-salah'))
        ->toThrow(InvalidArgumentException::class);
});
