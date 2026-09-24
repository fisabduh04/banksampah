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
    // Ikuti pengamanan database pada test accounting Fase 8.
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
    // Akhir bulan lalu dan awal bulan ini selalu bukan tanggal masa depan.
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

    // Gunakan Page baru agar cache tidak terbawa ke periode berikutnya.
    $reportFor = function (string $start, string $end): array {
        $page = new TrialBalance;
        $page->startDate = $start;
        $page->endDate = $end;

        return $page->getTrialBalanceData();
    };

    // Semua laporan dibaca setelah jurnal pembalik dibuat.
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

test('akun historis dan saldo berlawanan tetap tampil pada sisi sebenarnya', function (): void {
    $journalDate = now()->subDay()->toDateString();
    $reportDate = now()->toDateString();

    $user = User::factory()->create();

    $cash = Account::query()
        ->where('system_key', 'cash')
        ->sole();

    $openingBalance = Account::query()
        ->where('system_key', 'opening_balance')
        ->sole();

    // Kas normalnya debit, tetapi jurnal ini membuat saldonya kredit.
    app(JournalService::class)->post(
        transactionDate: $journalDate,
        referenceType: 'trial_balance_historical_test',
        referenceId: $user->id,
        referenceNumber: 'TB-HISTORICAL',
        description: 'Uji saldo historis satu sen',
        userId: $user->id,
        lines: [
            [
                'account_id' => $cash->id,
                'debit' => '0.00',
                'credit' => '0.01',
            ],
            [
                'account_id' => $openingBalance->id,
                'debit' => '0.01',
                'credit' => '0.00',
            ],
        ]
    );

    // Perubahan master akun tidak boleh menghapus histori jurnal.
    $cash->update([
        'is_active' => false,
        'is_postable' => false,
    ]);

    $page = new TrialBalance;
    $page->startDate = $reportDate;
    $page->endDate = $reportDate;

    $report = $page->getTrialBalanceData();

    expect($report['balanced'])->toBeTrue();
    expect($report['rows']->has($cash->id))->toBeTrue();
    expect($report['rows'][$cash->id]['opening_credit'])->toBe('0.01');
    expect($report['rows'][$cash->id]['closing_credit'])->toBe('0.01');
    expect($report['rows'][$cash->id]['closing_debit'])->toBe('0.00');
    expect($report['rows'][$openingBalance->id]['closing_debit'])
        ->toBe('0.01');
    expect($report['totals']['period_debit'])->toBe('0.00');
    expect($report['totals']['period_credit'])->toBe('0.00');
});
