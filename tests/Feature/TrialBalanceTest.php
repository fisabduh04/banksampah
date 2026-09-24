<?php

use App\Filament\Pages\TrialBalance;
use App\Models\Account;
use App\Models\User;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // Ikuti pengamanan database yang dipakai test accounting Fase 8.
    if (! app()->environment('testing')) {
        throw new RuntimeException(
            'Pengujian hanya boleh berjalan dalam environment testing.'
        );
    }

    config([
        'database.connections.trial_balance_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'trial_balance_test',
    ]);

    $connection = DB::connection();

    if (
        $connection->getDriverName() !== 'mysql'
        || $connection->selectOne(
            'SELECT DATABASE() AS name'
        )->name !== 'banksampah_testing'
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
        config('database.default') === 'trial_balance_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

test('reversal lintas periode dihitung pada tanggal jurnal masing-masing', function (): void {
    // Gunakan akhir bulan lalu dan awal bulan ini agar kedua tanggal
    // selalu berada sebelum atau pada tanggal saat test dijalankan.
    $dateOriginal = now()->startOfMonth()->subDay()->toDateString();
    $dateReversal = now()->startOfMonth()->toDateString();

    $user = User::factory()->create();

    $cash = Account::query()
        ->where('system_key', 'cash')
        ->sole();

    $openingBalance = Account::query()
        ->where('system_key', 'opening_balance')
        ->sole();

    $service = app(JournalService::class);

    $original = $service->post(
        transactionDate: $dateOriginal,
        referenceType: 'trial_balance_test',
        referenceId: $user->id,
        referenceNumber: 'TB-ORIGINAL',
        description: 'Jurnal asal untuk Neraca Saldo',
        userId: $user->id,
        lines: [
            [
                'account_id' => $cash->id,
                'debit' => '100.00',
                'credit' => '0.00',
            ],
            [
                'account_id' => $openingBalance->id,
                'debit' => '0.00',
                'credit' => '100.00',
            ],
        ]
    );

    $service->reverse(
        journalEntry: $original,
        transactionDate: $dateReversal,
        referenceType: 'trial_balance_reversal_test',
        referenceId: $user->id,
        referenceNumber: 'TB-REVERSAL',
        description: 'Pembalik jurnal untuk Neraca Saldo',
        userId: $user->id
    );

    // Instans baru untuk setiap periode agar cache laporan tidak terbawa.
    $reportFor = function (string $start, string $end): array {
        $page = new TrialBalance;
        $page->startDate = $start;
        $page->endDate = $end;

        return $page->getTrialBalanceData();
    };

    // Periksa laporan setelah jurnal pembalik dibuat.
    $previousMonth = $reportFor($dateOriginal, $dateOriginal);
    $currentMonth = $reportFor($dateReversal, $dateReversal);
    $combined = $reportFor($dateOriginal, $dateReversal);

    expect($previousMonth['balanced'])->toBeTrue();
    expect($previousMonth['totals']['period_debit'])->toBe('100.00');
    expect($previousMonth['totals']['period_credit'])->toBe('100.00');
    expect($previousMonth['totals']['closing_debit'])->toBe('100.00');
    expect($previousMonth['totals']['closing_credit'])->toBe('100.00');
    expect($previousMonth['rows'][$cash->id]['closing_debit'])->toBe('100.00');
    expect($previousMonth['rows'][$openingBalance->id]['closing_credit'])
        ->toBe('100.00');

    expect($currentMonth['balanced'])->toBeTrue();
    expect($currentMonth['totals']['opening_debit'])->toBe('100.00');
    expect($currentMonth['totals']['opening_credit'])->toBe('100.00');
    expect($currentMonth['totals']['period_debit'])->toBe('100.00');
    expect($currentMonth['totals']['period_credit'])->toBe('100.00');
    expect($currentMonth['totals']['closing_debit'])->toBe('0.00');
    expect($currentMonth['totals']['closing_credit'])->toBe('0.00');
    expect($currentMonth['rows'][$cash->id]['closing_credit'])->toBe('0.00');

    expect($combined['balanced'])->toBeTrue();
    expect($combined['totals']['period_debit'])->toBe('200.00');
    expect($combined['totals']['period_credit'])->toBe('200.00');
    expect($combined['totals']['closing_debit'])->toBe('0.00');
    expect($combined['totals']['closing_credit'])->toBe('0.00');
});
