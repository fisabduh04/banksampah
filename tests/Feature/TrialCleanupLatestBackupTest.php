<?php

use App\Services\TrialBackupRestorer;
use App\Services\TrialCleanupService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->ownedCleanupDatabase = null;
    if (getenv('TRIAL_CLEANUP_LATEST') !== '82e828f0') {
        $this->markTestSkipped('Requires the latest full production backup restored to a dedicated local test copy.');
    }
    if (! app()->environment('testing')) {
        throw new RuntimeException('Testing environment required.');
    }
    $database = 'banksampah_cleanup_testing_latest_'.bin2hex(random_bytes(6));
    app(TrialBackupRestorer::class)->restore(base_path('u846702626_banksampah (1).sql'), '82e828f0212e987fee214c729bf250d0ca0e307c2911bd2d34d270f0e4787e9f', $database, 'DESKTOP-PDMMRQ1');
    $this->ownedCleanupDatabase = $database;
    config(['database.connections.latest_cleanup_test' => [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '', 'database' => $database], 'database.default' => 'latest_cleanup_test']);
    DB::purge('latest_cleanup_test');
    $this->cleanup = app(TrialCleanupService::class);
    expect($this->cleanup->identity(DB::connection()))->toBe(['database' => $database, 'server' => 'DESKTOP-PDMMRQ1']);
    $this->manifest = json_decode(file_get_contents(storage_path('app/private/trial-cleanup-latest-tests-manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->cleanup->verifyBackup(base_path('u846702626_banksampah (1).sql'), $this->manifest['source_sha256']);
    DB::statement("SET time_zone = '+00:00'");
    $this->before = $this->cleanup->snapshot(DB::connection(), $this->manifest['schema']);
});

afterEach(function (): void {
    if ($this->ownedCleanupDatabase !== null) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (! app()->environment('testing') || ! preg_match('/\Abanksampah_cleanup_testing_latest_[a-f0-9]{12}\z/', $this->ownedCleanupDatabase)
            || DB::selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
            throw new RuntimeException('Refusing to drop an unowned test database.');
        }
        DB::statement('DROP DATABASE `'.$this->ownedCleanupDatabase.'`');
        DB::purge('latest_cleanup_test');
    }
});

function cleanLatestTrialCopy(array $manifest, bool $commit = false): array
{
    return app(TrialCleanupService::class)->clean(DB::connection(), $manifest, DB::connection()->getDatabaseName(), 'DESKTOP-PDMMRQ1', $commit);
}

test('latest full backup cleanup includes new deposits and journals while preserving all master and system data', function (): void {
    $masters = [];
    foreach (['customers' => 75, 'waste_categories' => 14, 'waste_types' => 85, 'waste_prices' => 55] as $table => $count) {
        $this->assertDatabaseCount($table, $count);
        $masters[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    expect((int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'")->n)->toBe(42);

    $report = cleanLatestTrialCopy($this->manifest, commit: true);

    expect(array_sum($report['deleted']))->toBe(60);
    expect($report['deleted'])->toBe(['balance_mutations' => 12, 'cash_mutations' => 0, 'deposit_items' => 19, 'deposits' => 7, 'inventory_cost_reconciliations' => 0, 'inventory_movements' => 11, 'journal_entries' => 2, 'journal_lines' => 4, 'sale_items' => 1, 'sale_payments' => 1, 'sales' => 1, 'withdrawals' => 2]);
    expect($report['totals_before'])->toBe(['savings' => '203000.00', 'inventory_quantity' => '360.000', 'inventory_cost' => '72600.00', 'receivables' => '500.00', 'cash' => '0.00', 'gl_debit' => '66000.00', 'gl_credit' => '66000.00']);
    expect($report['totals_after'])->toBe(['savings' => '0.00', 'inventory_quantity' => '0.000', 'inventory_cost' => '0.00', 'receivables' => '0.00', 'cash' => '0.00', 'gl_debit' => '0.00', 'gl_credit' => '0.00']);
    expect($report['all_preserved_tables_identical'])->toBeTrue();
    foreach (TrialCleanupService::TRANSACTIONS as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    foreach ($masters as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
    $this->assertDatabaseCount('exports', 17);
    $this->assertDatabaseCount('notifications', 2);
    $this->assertDatabaseCount('users', 1);
    expect($this->cleanup->foreignKeyOrphans(DB::connection(), $this->manifest['schema']))->toBe([]);
    expect($this->cleanup->referenceFindings(DB::connection()))->toBe([]);
});

test('latest cleanup replay does nothing and rejects transactions added after the reviewed snapshot', function (): void {
    cleanLatestTrialCopy($this->manifest, commit: true);
    $second = cleanLatestTrialCopy($this->manifest, commit: true);
    expect($second['status'])->toBe('already_clean_noop');
    expect(array_sum($second['deleted']))->toBe(0);
    DB::table('deposits')->insert(['id' => 900002, 'deposit_number' => 'NEW-AFTER-CLEANUP', 'customer_id' => 3, 'transaction_date' => '2026-09-25', 'status' => 'draft', 'total_weight' => '0', 'total_amount' => '0']);

    expect(fn () => cleanLatestTrialCopy($this->manifest, commit: true))->toThrow(RuntimeException::class, 'fingerprint');
    $this->assertDatabaseHas('deposits', ['id' => 900002]);
});

test('the old 14:05 manifest cannot clean the latest 15:58 dataset', function (): void {
    $old = json_decode(file_get_contents(storage_path('app/private/trial-cleanup-old-full-manifest.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(fn () => cleanLatestTrialCopy($old, commit: true))->toThrow(RuntimeException::class, 'fingerprint');

    $this->assertDatabaseCount('journal_entries', 2);
    $this->assertDatabaseCount('deposits', 7);
});

test('cleanup command rejects a changed manifest checksum before touching the database', function (): void {
    $this->artisan('bank-sampah:trial-cleanup', [
        '--connection' => 'latest_cleanup_test', '--database' => 'banksampah_cleanup_testing_latest_tests', '--server' => 'DESKTOP-PDMMRQ1',
        '--manifest' => storage_path('app/private/trial-cleanup-latest-tests-manifest.json'), '--manifest-sha' => str_repeat('0', 64),
        '--backup' => base_path('u846702626_banksampah (1).sql'), '--backup-sha' => $this->manifest['source_sha256'], '--execute' => true,
    ])->expectsOutputToContain('Backup/hash tidak cocok')->assertExitCode(1);

    $this->assertDatabaseCount('balance_mutations', 12);
    $this->assertDatabaseCount('journal_lines', 4);
});
