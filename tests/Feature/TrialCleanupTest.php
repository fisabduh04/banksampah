<?php

use App\Services\TrialBackupRestorer;
use App\Services\TrialCleanupService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->ownedCleanupDatabase = null;
    if (getenv('TRIAL_CLEANUP_AUDIT') !== 'old-b050b725') {
        $this->markTestSkipped('Requires the isolated full-schema old snapshot; never the main database.');
    }
    if (! app()->environment('testing')) {
        throw new RuntimeException('Testing environment required.');
    }
    $database = 'banksampah_cleanup_testing_old_'.bin2hex(random_bytes(6));
    app(TrialBackupRestorer::class)->restore('C:/Users/Lenovo/Downloads/u846702626_banksampah.sql', TrialCleanupService::OBSOLETE_BACKUP, $database, 'DESKTOP-PDMMRQ1');
    $this->ownedCleanupDatabase = $database;
    config(['database.connections.trial_cleanup_test' => [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '', 'database' => $database], 'database.default' => 'trial_cleanup_test']);
    DB::purge('trial_cleanup_test');
    $this->cleanup = app(TrialCleanupService::class);
    expect($this->cleanup->identity(DB::connection()))->toBe(['database' => $database, 'server' => 'DESKTOP-PDMMRQ1']);
    DB::statement("SET time_zone = '+00:00'");
    $this->manifest = json_decode(file_get_contents(storage_path('app/private/trial-cleanup-old-full-manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->baseline = $this->cleanup->snapshot(DB::connection(), $this->manifest['schema']);
});

afterEach(function (): void {
    if ($this->ownedCleanupDatabase !== null) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (! app()->environment('testing') || ! preg_match('/\Abanksampah_cleanup_testing_old_[a-f0-9]{12}\z/', $this->ownedCleanupDatabase)
            || DB::selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
            throw new RuntimeException('Refusing to drop an unowned test database.');
        }
        DB::statement('DROP DATABASE `'.$this->ownedCleanupDatabase.'`');
        DB::purge('trial_cleanup_test');
    }
});

function runTrialCleanup(array $manifest, bool $commit = false): array
{
    return app(TrialCleanupService::class)->clean(DB::connection(), $manifest, DB::connection()->getDatabaseName(), 'DESKTOP-PDMMRQ1', $commit);
}

test('full-schema dry run removes exactly the trial IDs and returns every master byte unchanged before rollback', function (): void {
    $masters = [];
    foreach (TrialCleanupService::MASTERS as $table) {
        $masters[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    $report = runTrialCleanup($this->manifest);

    expect($report['status'])->toBe('simulated_rolled_back');
    expect($report['deleted'])->toBe(['balance_mutations' => 10, 'cash_mutations' => 0, 'deposit_items' => 17, 'deposits' => 5, 'inventory_cost_reconciliations' => 0, 'inventory_movements' => 9, 'journal_entries' => 0, 'journal_lines' => 0, 'sale_items' => 1, 'sale_payments' => 1, 'sales' => 1, 'withdrawals' => 2]);
    expect($report['totals_before']['savings'])->toBe('137000.00');
    expect($report['totals_after'])->toBe(['savings' => '0.00', 'inventory_quantity' => '0.000', 'inventory_cost' => '0.00', 'receivables' => '0.00', 'cash' => '0.00', 'gl_debit' => '0.00', 'gl_credit' => '0.00']);
    expect($report['master_fingerprints_identical'])->toBeTrue();
    expect($report['all_preserved_tables_identical'])->toBeTrue();
    expect($report['foreign_key_orphans_after'])->toBe([]);
    expect((int) DB::selectOne('SELECT @@foreign_key_checks AS enabled')->enabled)->toBe(1);
    expect(array_column($this->manifest['tables']['deposit_items']['rows'], 'id'))->toContain('2', '3');
    foreach ($masters as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
});

test('second execution is a no-op and a later transaction is never swept by the old manifest', function (): void {
    runTrialCleanup($this->manifest, commit: true);
    $second = runTrialCleanup($this->manifest, commit: true);

    expect($second['status'])->toBe('already_clean_noop');
    expect(array_sum($second['deleted']))->toBe(0);
    DB::table('deposits')->insert(['id' => 900001, 'deposit_number' => 'CLEANUP-NEW-REAL-TRANSACTION', 'customer_id' => 3, 'transaction_date' => '2026-09-25', 'status' => 'draft', 'total_weight' => '0', 'total_amount' => '0']);
    expect(fn () => runTrialCleanup($this->manifest, commit: true))->toThrow(RuntimeException::class, 'fingerprint');
    $this->assertDatabaseHas('deposits', ['id' => 900001]);
});

test('ID count content and reference drift all fail before deleting any rows', function (string $change): void {
    match ($change) {
        'missing_id' => DB::table('deposit_items')->where('id', 2)->delete(),
        'changed_content' => DB::table('deposits')->where('id', 4)->update(['total_amount' => '54901.00']),
        'changed_reference' => DB::table('balance_mutations')->where('id', 2)->update(['reference_id' => 2]),
        'real_master_changed' => DB::table('waste_prices')->where('id', DB::table('waste_prices')->min('id'))->update(['price' => '12345.00']),
    };
    $before = $this->cleanup->snapshot(DB::connection(), $this->manifest['schema']);

    expect(fn () => runTrialCleanup($this->manifest, commit: true))->toThrow(RuntimeException::class, 'fingerprint');

    expect($this->cleanup->snapshot(DB::connection(), $this->manifest['schema']))->toBe($before);
    expect(DB::table('deposits')->count())->toBe(5);
})->with(['missing_id', 'changed_content', 'changed_reference', 'real_master_changed']);

test('a manifest missing a child ID or containing a duplicate ID is rejected', function (string $change): void {
    $manifest = $this->manifest;
    if ($change === 'missing_child') {
        array_shift($manifest['tables']['deposit_items']['rows']);
    } else {
        $manifest['tables']['deposit_items']['rows'][1] = $manifest['tables']['deposit_items']['rows'][0];
    }

    expect(fn () => runTrialCleanup($manifest, commit: true))->toThrow(RuntimeException::class, 'Daftar ID');

    expect(DB::table('deposit_items')->count())->toBe(17);
})->with(['missing_child', 'duplicate_child']);

test('an exception after several DELETE statements rolls back the entire cleanup', function (): void {
    $connection = DB::connection();
    $deletedStatements = 0;
    $connection->beforeExecuting(function (string $query) use (&$deletedStatements): void {
        if (str_starts_with(strtolower($query), 'delete ') && ++$deletedStatements === 3) {
            throw new RuntimeException('Injected third delete failure');
        }
    });

    expect(fn () => runTrialCleanup($this->manifest, commit: true))->toThrow(RuntimeException::class, 'Injected third delete failure');

    expect($deletedStatements)->toBe(3);
    expect($this->cleanup->snapshot($connection, $this->manifest['schema']))->toBe($this->baseline);
});

test('unclassified tables schema drift and the wrong database all block cleanup', function (string $change): void {
    $manifest = $this->manifest;
    if ($change === 'schema') {
        $manifest['schema']['deposit_items']['foreign_keys'] = [];
    } elseif ($change === 'unclassified') {
        $manifest['blockers'] = ['Unclassified transaction table'];
    } else {
        expect(fn () => $this->cleanup->clean(DB::connection(), $manifest, 'banksampah', 'DESKTOP-PDMMRQ1', true))->toThrow(RuntimeException::class, 'Identitas');

        return;
    }

    expect(fn () => runTrialCleanup($manifest, commit: true))->toThrow(RuntimeException::class);
    expect(DB::table('balance_mutations')->count())->toBe(10);
})->with(['schema', 'unclassified', 'database']);

test('obsolete backup cannot be promoted to the latest production source', function (): void {
    expect(fn () => $this->cleanup->manifest(DB::connection(), 'C:/Users/Lenovo/Downloads/u846702626_banksampah.sql', TrialCleanupService::OBSOLETE_BACKUP, confirmedLatest: true))->toThrow(RuntimeException::class, 'diketahui usang');
});

test('journal lines and a chain of reversals are deleted with every foreign key still enforced', function (): void {
    $account = DB::table('accounts')->min('id');
    foreach ([900001 => null, 900002 => 900001, 900003 => 900002] as $id => $parent) {
        DB::table('journal_entries')->insert(['id' => $id, 'entry_number' => 'CLEANUP-SYNTHETIC-'.$id, 'transaction_date' => '2026-09-24', 'reference_type' => 'test_fixture', 'reference_id' => $id, 'status' => $id === 900003 ? 'posted' : 'reversed', 'reversal_of_id' => $parent]);
        DB::table('journal_lines')->insert([
            ['journal_entry_id' => $id, 'account_id' => $account, 'line_number' => 1, 'debit' => '100.00', 'credit' => '0.00'],
            ['journal_entry_id' => $id, 'account_id' => $account, 'line_number' => 2, 'debit' => '0.00', 'credit' => '100.00'],
        ]);
    }
    $manifest = $this->manifest;
    $manifest['tables'] = $this->cleanup->snapshot(DB::connection(), $manifest['schema']);
    $manifest['totals'] = $this->cleanup->totals(DB::connection());

    $report = runTrialCleanup($manifest, commit: true);

    expect($report['deleted']['journal_entries'])->toBe(3);
    expect($report['deleted']['journal_lines'])->toBe(6);
    expect($report['totals_after']['gl_debit'])->toBe('0.00');
    expect($report['totals_after']['gl_credit'])->toBe('0.00');
    expect($report['all_preserved_tables_identical'])->toBeTrue();
    expect((int) DB::selectOne('SELECT @@foreign_key_checks AS enabled')->enabled)->toBe(1);
});

test('SQL restore parser preserves quoted semicolons and rejects executable comments outside charset setup', function (): void {
    $restorer = app(TrialBackupRestorer::class);

    expect($restorer->statements("-- comment\nINSERT INTO `samples` VALUES (1, 'a; b', 'it''s');\n"))->toBe(["INSERT INTO `samples` VALUES (1, 'a; b', 'it''s')"]);
    expect(fn () => $restorer->statements('/*!40101 SET FOREIGN_KEY_CHECKS=0 */;'))->toThrow(RuntimeException::class, 'Executable SQL comment');
    expect(fn () => $restorer->restore('does-not-exist.sql', str_repeat('0', 64), 'banksampah', 'DESKTOP-PDMMRQ1'))->toThrow(RuntimeException::class, 'Restore hanya');
});
