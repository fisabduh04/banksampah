<?php

use App\Filament\Pages\GeneralLedger;
use App\Models\Account;
use App\Models\User;
use App\Services\FinancialReportingService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // Test akuntansi hanya boleh memakai database khusus pengujian.
    if (! app()->environment('testing')) {
        throw new RuntimeException(
            'Pengujian hanya boleh berjalan dalam environment testing.'
        );
    }

    config([
        'database.connections.general_ledger_historical_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'general_ledger_historical_test',
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
        config('database.default') === 'general_ledger_historical_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

test('Buku Besar tetap membuka akun historis non-postable', function (): void {
    $journalDate = now()->subDay()->toDateString();
    $reportDate = now()->toDateString();
    $user = User::factory()->create();

    $cash = Account::query()->where('system_key', 'cash')->sole();
    $openingBalance = Account::query()
        ->where('system_key', 'opening_balance')->sole();

    // Saldo kredit satu sen pada Kas menguji saldo berlawanan.
    app(JournalService::class)->post(
        transactionDate: $journalDate,
        referenceType: 'general_ledger_historical_test',
        referenceId: $user->id,
        referenceNumber: 'GL-HISTORICAL',
        description: 'Uji akun historis Buku Besar',
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

    // Perubahan master tidak menghilangkan riwayat jurnal.
    $cash->update(['is_active' => false, 'is_postable' => false]);

    $withoutJournal = Account::query()->create([
        'code' => 'GL-TEST-'.$user->id,
        'name' => 'Akun induk uji tanpa jurnal',
        'account_type' => Account::TYPE_ASSET,
        'normal_balance' => Account::NORMAL_DEBIT,
        'is_active' => false,
        'is_postable' => false,
    ]);

    $page = new GeneralLedger;
    $page->accountId = $cash->id;
    $page->startDate = $reportDate;
    $page->endDate = $reportDate;

    $accounts = $page->getAccounts();
    $ledger = $page->getLedgerData();
    $trialBalance = app(FinancialReportingService::class)
        ->trialBalance($reportDate, $reportDate);

    expect($accounts->contains('id', $cash->id))->toBeTrue();
    expect($accounts->contains('id', $withoutJournal->id))->toBeFalse();
    expect($ledger['valid'])->toBeTrue();
    expect($ledger['account']->id)->toBe($cash->id);
    expect($ledger['opening_balance'])->toBe('-0.01');
    expect($ledger['total_debit'])->toBe('0.00');
    expect($ledger['total_credit'])->toBe('0.00');
    expect($ledger['closing_balance'])->toBe('-0.01');
    expect($ledger['transaction_count'])->toBe(0);
    expect($trialBalance['rows'][$cash->id]['closing_credit'])->toBe('0.01');

    // Tanpa akun postable, halaman memilih akun historis yang masih ada.
    Account::query()->update(['is_postable' => false]);
    $fallbackPage = new GeneralLedger;
    $fallbackPage->mount();

    expect($fallbackPage->accountId)->not->toBeNull();
    expect($fallbackPage->getAccounts()
        ->contains('id', $fallbackPage->accountId))->toBeTrue();
});
