<?php

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalService;
use App\Services\ReconciliationService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan dalam environment testing.');
    }

    config([
        'database.connections.cash_reconciliation_test' => [
            ...config('database.connections.mysql'),
            'url' => null,
            'database' => 'banksampah_testing',
        ],
        'database.default' => 'cash_reconciliation_test',
    ]);

    $connection = DB::connection();

    if (
        $connection->getDriverName() !== 'mysql'
        || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'banksampah_testing'
    ) {
        throw new RuntimeException('Pengujian hanya boleh memakai MySQL banksampah_testing.');
    }

    $connection->beginTransaction();
    app(AccountSeeder::class)->run();
});

afterEach(function (): void {
    if (
        config('database.default') === 'cash_reconciliation_test'
        && DB::connection()->transactionLevel() > 0
    ) {
        DB::connection()->rollBack();
    }
});

function cashReconciliationAccount(string $type = 'cash', bool $active = true): CashAccount
{
    return CashAccount::query()->create([
        'code' => 'RC-'.Str::ulid(),
        'name' => 'Kas cadangan uji',
        'account_type' => $type,
        'is_active' => $active,
    ]);
}

function cashReconciliationMutation(
    CashAccount $account,
    string $type,
    string $amount,
    string $date = '2026-09-20'
): CashMutation {
    return CashMutation::query()->create([
        'cash_account_id' => $account->id,
        'mutation_type' => $type,
        'amount' => $amount,
        'transaction_date' => $date,
        'reference_type' => 'cash_reconciliation_test',
    ]);
}

function cashReconciliationJournal(CashMutation $mutation): JournalEntry
{
    $account = Account::query()->where('system_key', $mutation->cashAccount->account_type)->sole();
    $counter = Account::query()->where('system_key', 'opening_balance')->sole();
    $incoming = $mutation->mutation_type === CashMutation::TYPE_IN;

    return app(JournalService::class)->post(
        transactionDate: $mutation->transaction_date->toDateString(),
        referenceType: 'cash_reconciliation_test',
        referenceId: $mutation->id,
        referenceNumber: null,
        description: 'Jurnal uji rekonsiliasi Kas/Bank',
        userId: null,
        lines: [
            ['account_id' => $account->id, 'debit' => $incoming ? $mutation->amount : '0.00', 'credit' => $incoming ? '0.00' : $mutation->amount],
            ['account_id' => $counter->id, 'debit' => $incoming ? '0.00' : $mutation->amount, 'credit' => $incoming ? $mutation->amount : '0.00'],
        ]
    );
}

test('kas digabung per jenis termasuk rekening nonaktif dan bank nol tanpa menulis data', function (): void {
    $first = cashReconciliationAccount();
    $second = cashReconciliationAccount(active: false);
    $bank = cashReconciliationAccount('bank');
    cashReconciliationJournal(cashReconciliationMutation($first, 'in', '100.05'));
    cashReconciliationJournal(cashReconciliationMutation($first, 'out', '20.01'));
    cashReconciliationJournal(cashReconciliationMutation($second, 'in', '40.02'));
    $cashGl = Account::query()->where('system_key', 'cash')->sole();
    $cashGl->update(['is_active' => false, 'is_postable' => false]);
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    try {
        $report = app(ReconciliationService::class)->cashAndBankAsOf('2026-09-20');
        $queries = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    expect($report['as_of_date'])->toBe('2026-09-20');
    expect($report['balanced'])->toBeTrue();
    expect(array_keys($report['groups']))->toBe(['cash', 'bank']);
    $cash = $report['groups']['cash'];
    expect($cash['account_id'])->toBe($cashGl->id);
    expect($cash['gl_balance'])->toBe('120.06');
    expect($cash['cash_balance'])->toBe('120.06');
    expect($cash['difference'])->toBe('0.00');
    expect($cash['balanced'])->toBeTrue();
    $details = collect($cash['cash_accounts'])->keyBy('id');
    expect($details->get($first->id)['balance'])->toBe('80.04');
    expect($details->get($second->id)['balance'])->toBe('40.02');
    expect($details->get($second->id)['is_active'])->toBeFalse();
    expect($report['groups']['bank']['gl_balance'])->toBe('0.00');
    expect($report['groups']['bank']['cash_balance'])->toBe('0.00');
    expect($report['groups']['bank']['difference'])->toBe('0.00');
    expect($report['groups']['bank']['balanced'])->toBeTrue();
    expect(collect($report['groups']['bank']['cash_accounts'])->keyBy('id')->get($bank->id)['balance'])->toBe('0.00');
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
});

test('tanggal laporan mencakup batas akhir dan reversal hanya memengaruhi periode pembalik', function (): void {
    $cash = cashReconciliationAccount();
    $source = cashReconciliationJournal(cashReconciliationMutation($cash, 'in', '100.01', '2026-08-31'));
    $reversal = cashReconciliationMutation($cash, 'out', '100.01', '2026-09-01');
    app(JournalService::class)->reverse(
        journalEntry: $source,
        transactionDate: '2026-09-01',
        referenceType: 'cash_reconciliation_reversal',
        referenceId: $reversal->id,
        referenceNumber: null,
        description: 'Pembalik uji rekonsiliasi',
        userId: User::factory()->create()->id,
    );
    cashReconciliationJournal(cashReconciliationMutation($cash, 'in', '999.99', '2026-09-02'));
    cashReconciliationMutation($cash, 'invalid', '-1.00', '2026-09-02');
    $service = app(ReconciliationService::class);

    $previous = $service->cashAndBankAsOf('2026-08-31');
    $current = $service->cashAndBankAsOf('2026-09-01');

    expect($source->fresh()->status)->toBe(JournalEntry::STATUS_REVERSED);
    expect($previous['groups']['cash']['gl_balance'])->toBe('100.01');
    expect($previous['groups']['cash']['cash_balance'])->toBe('100.01');
    expect($previous['balanced'])->toBeTrue();
    expect($current['groups']['cash']['gl_balance'])->toBe('0.00');
    expect($current['groups']['cash']['cash_balance'])->toBe('0.00');
    expect($current['balanced'])->toBeTrue();
});

test('jenis rekening menentukan kelompok bank dan saldo kredit aset tetap negatif', function (): void {
    $bank = cashReconciliationAccount('bank');
    cashReconciliationJournal(cashReconciliationMutation($bank, 'out', '0.01'));

    $report = app(ReconciliationService::class)->cashAndBankAsOf('2026-09-20');

    expect($report['groups']['bank']['gl_balance'])->toBe('-0.01');
    expect($report['groups']['bank']['cash_balance'])->toBe('-0.01');
    expect($report['groups']['bank']['difference'])->toBe('0.00');
    expect($report['groups']['cash']['cash_accounts'])->toBe([]);
    expect($report['groups']['cash']['cash_balance'])->toBe('0.00');
    expect($report['balanced'])->toBeTrue();
});

test('selisih kas dan bank yang saling meniadakan tetap tidak balanced', function (): void {
    cashReconciliationMutation(cashReconciliationAccount(), 'in', '0.01');
    cashReconciliationMutation(cashReconciliationAccount('bank'), 'out', '0.01');

    $report = app(ReconciliationService::class)->cashAndBankAsOf('2026-09-20');

    expect($report['groups']['cash']['gl_balance'])->toBe('0.00');
    expect($report['groups']['cash']['difference'])->toBe('0.01');
    expect($report['groups']['cash']['balanced'])->toBeFalse();
    expect($report['groups']['bank']['gl_balance'])->toBe('0.00');
    expect($report['groups']['bank']['difference'])->toBe('-0.01');
    expect($report['groups']['bank']['balanced'])->toBeFalse();
    expect($report['balanced'])->toBeFalse();
});

test('mutasi kas dengan jenis atau nominal tidak sah ditolak', function (string $type, string $amount): void {
    cashReconciliationMutation(cashReconciliationAccount(), $type, $amount);

    expect(fn () => app(ReconciliationService::class)->cashAndBankAsOf('2026-09-20'))
        ->toThrow(RuntimeException::class, 'Terdapat mutasi Kas/Bank dengan jenis atau nominal tidak valid.');
})->with([
    'jenis tidak dikenal' => ['adjustment', '1.00'],
    'jenis berbeda kapitalisasi' => ['IN', '1.00'],
    'nominal nol' => ['in', '0.00'],
    'nominal negatif' => ['out', '-1.00'],
]);

test('rekening dengan jenis tidak dikenal ditolak termasuk rekening nonaktif', function (): void {
    cashReconciliationAccount('other', false);

    expect(fn () => app(ReconciliationService::class)->cashAndBankAsOf('2026-09-20'))
        ->toThrow(RuntimeException::class, 'Terdapat akun Kas/Bank dengan jenis tidak valid.');
});

test('tanggal rekonsiliasi kas harus sesuai format dan kalender', function (string $date): void {
    expect(fn () => app(ReconciliationService::class)->cashAndBankAsOf($date))
        ->toThrow(InvalidArgumentException::class, 'Tanggal laporan tidak valid.');
})->with(['tanggal-salah', '2026-02-30', '20-09-2026', '2026-9-2', '']);
