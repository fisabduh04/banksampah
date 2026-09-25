<?php

use App\Services\TrialBackupRestorer;
use App\Services\TrialCleanupReport;
use App\Services\TrialCleanupService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->ownedCleanupDatabase = null;
    $this->cleanupFiles = [];
});

afterEach(function (): void {
    if ($this->ownedCleanupDatabase !== null) {
        DB::purge('hardening');
        $admin = DB::connection('hardening_admin');
        if (! app()->environment('testing') || ! preg_match('/\Abanksampah_cleanup_testing_hard_[a-f0-9]{12}\z/', $this->ownedCleanupDatabase)
            || $admin->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
            throw new RuntimeException('Refusing to drop an unowned database.');
        }
        $admin->statement('DROP DATABASE `'.$this->ownedCleanupDatabase.'`');
        DB::purge('hardening_admin');
    }
    foreach ($this->cleanupFiles as $file) {
        foreach ([$file, $file.'.pending'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

function arrangeCleanupCopy(TestCase $test): array
{
    if (! app()->environment('testing')) {
        throw new RuntimeException('Disposable test environment required.');
    }
    $backup = base_path('u846702626_banksampah (1).sql');
    if (! is_file($backup)) {
        $test->markTestSkipped('Private Hostinger backup is not available.');
    }
    $hash = '82e828f0212e987fee214c729bf250d0ca0e307c2911bd2d34d270f0e4787e9f';
    $database = 'banksampah_cleanup_testing_hard_'.bin2hex(random_bytes(6));
    $restored = app(TrialBackupRestorer::class)->restore($backup, $hash, $database, 'DESKTOP-PDMMRQ1');
    $test->ownedCleanupDatabase = $restored['target_database'];
    $connection = [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => ''];
    config(['database.connections.hardening' => [...$connection, 'database' => $database], 'database.connections.hardening_admin' => [...$connection, 'database' => null]]);
    DB::purge('hardening');
    $service = app(TrialCleanupService::class);
    $manifest = $service->manifest(DB::connection('hardening'), $backup, $hash, true);
    $manifestPath = storage_path('app/private/hardening-test-'.bin2hex(random_bytes(8)).'.json');
    $reportPath = storage_path('app/private/hardening-test-'.bin2hex(random_bytes(8)).'.jsonl');
    $test->cleanupFiles = [$manifestPath, $reportPath];
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

    return [$manifest, [
        '--connection' => 'hardening', '--database' => $database, '--server' => 'DESKTOP-PDMMRQ1',
        '--manifest' => $manifestPath, '--manifest-sha' => hash_file('sha256', $manifestPath),
        '--backup' => $backup, '--backup-sha' => $hash, '--report' => $reportPath,
    ]];
}

test('MariaDB ordinary-column metadata matches MySQL without hiding meaningful differences', function (): void {
    $service = app(TrialCleanupService::class);
    $mysql = ['name' => 'id', 'type' => 'bigint', 'full_type' => 'bigint unsigned', 'nullable' => 'NO', 'default_value' => null, 'numeric_precision' => 20, 'numeric_scale' => 0, 'extra' => 'auto_increment', 'generation_expression' => ''];
    $maria = [...$mysql, 'full_type' => 'bigint(20) unsigned', 'generation_expression' => null];
    expect($service->normalizeColumns([$maria]))->toBe($service->normalizeColumns([$mysql]));
    expect($service->normalizeColumns([[...$maria, 'full_type' => 'bigint(20)']]))->not->toBe($service->normalizeColumns([$mysql]));
    expect($service->normalizeColumns([[...$maria, 'generation_expression' => 'other_id + 1']]))->not->toBe($service->normalizeColumns([$mysql]));
    expect($service->normalizeColumns([[...$maria, 'numeric_precision' => 19]]))->not->toBe($service->normalizeColumns([$mysql]));
    expect($service->normalizeColumns([[...$maria, 'default_value' => "'draft'"]])[0]['default_value'])->toBe('draft');
    expect($service->normalizeColumns([[...$maria, 'default_value' => "'NULL'"]])[0]['default_value'])->toBe('NULL');
});

test('preflight and verify use a nonlocking read-only transaction and preserve the full restored export', function (): void {
    [$manifest, $options] = arrangeCleanupCopy($this);
    $db = DB::connection('hardening');
    $queries = [];
    $db->beforeExecuting(function (string $sql) use (&$queries): void {
        $queries[] = $sql;
        if (preg_match('/\A\s*(?:DELETE|INSERT|UPDATE|CREATE|ALTER|DROP|TRUNCATE|LOCK)|FOR UPDATE|LOCK IN SHARE MODE/i', $sql)) {
            throw new RuntimeException('Read-only path attempted a write or locking read.');
        }
    });
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--preflight' => true])->expectsOutputToContain('"state":"not_cleaned"')->assertExitCode(0);
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--verify' => true])->expectsOutputToContain('"state":"not_cleaned"')->assertExitCode(0);
    expect($queries)->toContain('SET TRANSACTION READ ONLY');
    expect(is_file($options['--report']))->toBeFalse();
    expect(app(TrialCleanupService::class)->snapshot($db, $manifest['schema']))->toBe($manifest['tables']);
    expect((int) $db->selectOne("SELECT COUNT(*) AS n FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_type='FOREIGN KEY'")->n)->toBe(42);
});

test('verification distinguishes a committed cleanup from new or partially changed data', function (): void {
    [$manifest, $options] = arrangeCleanupCopy($this);
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--execute' => true])->expectsOutputToContain('"status":"committed"')->assertExitCode(0);
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--verify' => true])->expectsOutputToContain('"state":"already_clean"')->assertExitCode(0);
    $lines = file($options['--report'], FILE_IGNORE_NEW_LINES);
    expect(json_decode($lines[0], true)['status'])->toBe('started');
    $result = json_decode($lines[1], true)['result'];
    expect(array_sum($result['deleted']))->toBe(60);
    expect($result['master_fingerprints_identical'])->toBeTrue();
    expect($result['all_preserved_tables_identical'])->toBeTrue();
    DB::connection('hardening')->table('deposits')->insert(['id' => 900001, 'deposit_number' => 'NEW-TRANSACTION', 'customer_id' => 3, 'transaction_date' => '2026-09-25', 'status' => 'draft', 'total_weight' => 0, 'total_amount' => 0]);
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--verify' => true])->expectsOutputToContain('"state":"mismatch"')->assertExitCode(1);
    expect(DB::connection('hardening')->table('deposits')->count())->toBe(1);
});

test('report failure after commit warns explicitly and verification finds committed data without replaying DELETE', function (): void {
    [$manifest, $options] = arrangeCleanupCopy($this);
    $this->instance(TrialCleanupReport::class, new class extends TrialCleanupReport
    {
        public function append(array $entry): void
        {
            if (($entry['status'] ?? '') === 'committed') {
                throw new RuntimeException('Injected final disk write failure');
            }
            parent::append($entry);
        }
    });
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--execute' => true])->expectsOutputToContain('COMMIT_MAY_HAVE_SUCCEEDED')->assertExitCode(2);
    expect(is_file($options['--report'].'.pending'))->toBeTrue();
    expect(DB::connection('hardening')->table('deposits')->count())->toBe(0);
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--verify' => true])->expectsOutputToContain('"state":"already_clean"')->assertExitCode(0);
    $actual = app(TrialCleanupService::class)->snapshot(DB::connection('hardening'), $manifest['schema']);
    foreach (TrialCleanupService::PRESERVE as $table) {
        expect($actual[$table])->toBe($manifest['tables'][$table]);
    }
});

test('insufficient report space or failed started write prevents all DELETE statements', function (string $failure): void {
    [$manifest, $options] = arrangeCleanupCopy($this);
    $writer = $failure === 'space' ? new class extends TrialCleanupReport
    {
        protected function freeBytes(string $directory): float
        {
            return 0;
        }
    } : new class extends TrialCleanupReport
    {
        public function append(array $entry): void
        {
            throw new RuntimeException('Injected started write failure');
        }
    };
    $this->instance(TrialCleanupReport::class, $writer);
    $db = DB::connection('hardening');
    $deletes = 0;
    $db->beforeExecuting(function (string $sql) use (&$deletes): void {
        if (str_starts_with(strtolower($sql), 'delete')) {
            $deletes++;
        }
    });
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--execute' => true])->assertExitCode(1);
    expect($deletes)->toBe(0);
    expect(app(TrialCleanupService::class)->snapshot($db, $manifest['schema']))->toBe($manifest['tables']);
})->with(['space', 'started']);

test('wrong identity checksum and schema are rejected without changing restored data', function (string $failure): void {
    [$manifest, $options] = arrangeCleanupCopy($this);
    if ($failure === 'hostname') {
        $options['--server'] = 'WRONG-SERVER';
    } elseif ($failure === 'database') {
        $options['--database'] = 'banksampah';
    } elseif ($failure === 'hash') {
        $options['--manifest-sha'] = str_repeat('0', 64);
    } else {
        $manifest['schema']['deposit_items']['foreign_keys'] = [];
        file_put_contents($options['--manifest'], json_encode($manifest, JSON_THROW_ON_ERROR));
        $options['--manifest-sha'] = hash_file('sha256', $options['--manifest']);
    }
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--preflight' => true])->assertExitCode(1);
    expect(DB::connection('hardening')->table('deposits')->count())->toBe(7);
    expect(DB::connection('hardening')->table('customers')->count())->toBe(75);
})->with(['hostname', 'database', 'hash', 'schema']);

test('report publication failure after commit preserves evidence and requires read-only verification', function (): void {
    [$manifest, $options] = arrangeCleanupCopy($this);
    $writer = new class extends TrialCleanupReport
    {
        public string $target;

        public function finish(array $entry): void
        {
            file_put_contents($this->target, 'another report owns this name');
            parent::finish($entry);
        }
    };
    $writer->target = $options['--report'];
    $this->instance(TrialCleanupReport::class, $writer);
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--execute' => true])->expectsOutputToContain('COMMIT_MAY_HAVE_SUCCEEDED')->assertExitCode(2);
    expect(file_get_contents($options['--report']))->toBe('another report owns this name');
    $lines = file($options['--report'].'.pending', FILE_IGNORE_NEW_LINES);
    expect(json_decode($lines[1], true)['status'])->toBe('committed');
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--verify' => true])->expectsOutputToContain('"state":"already_clean"')->assertExitCode(0);
});

test('report paths cannot overwrite files or escape the private report directory', function (string $case): void {
    $path = storage_path('app/private/hardening-test-'.bin2hex(random_bytes(8)).'.jsonl');
    $this->cleanupFiles = [$path];
    if ($case === 'existing') {
        file_put_contents($path, 'keep');
    } elseif ($case === 'pending') {
        file_put_contents($path.'.pending', 'keep');
    } elseif ($case === 'outside') {
        $path = base_path('outside-report.jsonl');
    } else {
        $path = storage_path('app/private/.hidden.jsonl');
    }
    expect(fn () => app(TrialCleanupReport::class)->probe($path, 100))->toThrow(RuntimeException::class, 'Laporan harus');
    if ($case === 'existing') {
        expect(file_get_contents($path))->toBe('keep');
    }
    if ($case === 'pending') {
        expect(file_get_contents($path.'.pending'))->toBe('keep');
    }
})->with(['existing', 'pending', 'outside', 'hidden']);

test('injected DELETE failure rolls back and verify reports the untouched snapshot', function (): void {
    [$manifest, $options] = arrangeCleanupCopy($this);
    $deletes = 0;
    DB::connection('hardening')->beforeExecuting(function (string $sql) use (&$deletes): void {
        if (str_starts_with(strtolower($sql), 'delete') && ++$deletes === 3) {
            throw new RuntimeException('Injected cleanup failure');
        }
    });
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--execute' => true])->expectsOutputToContain('Injected cleanup failure')->assertExitCode(1);
    expect($deletes)->toBe(3);
    $this->artisan('bank-sampah:trial-cleanup', [...$options, '--verify' => true])->expectsOutputToContain('"state":"not_cleaned"')->assertExitCode(0);
    expect(app(TrialCleanupService::class)->snapshot(DB::connection('hardening'), $manifest['schema']))->toBe($manifest['tables']);
});

test('MariaDB server metadata uses the legacy isolation variable when the MySQL variable is unavailable', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')->once()->with('SELECT VERSION() AS version')->andReturn((object) ['version' => '11.8.9-MariaDB-log']);
    $connection->shouldReceive('selectOne')->once()->with('SELECT @@transaction_isolation AS level')->andThrow(new RuntimeException('Unknown system variable'));
    $connection->shouldReceive('selectOne')->once()->with('SELECT @@tx_isolation AS level')->andReturn((object) ['level' => 'REPEATABLE-READ']);
    expect(app(TrialCleanupService::class)->serverInfo($connection))->toBe(['version' => '11.8.9-MariaDB-log', 'engine' => 'MariaDB', 'isolation' => 'REPEATABLE-READ']);
});

test('unsupported server versions cannot pass preflight', function (string $version): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')->once()->with('SELECT VERSION() AS version')->andReturn((object) ['version' => $version]);
    expect(fn () => app(TrialCleanupService::class)->serverInfo($connection))->toThrow(RuntimeException::class, 'Versi server');
})->with(['5.7.44', '10.5.29-MariaDB']);

test('read-only and execution flags cannot be combined', function (): void {
    $this->artisan('bank-sampah:trial-cleanup', ['--execute' => true, '--verify' => true])->expectsOutputToContain('Pilih hanya satu mode')->assertExitCode(1);
});

test('maintenance readiness reads only an isolated file and never resolves the database cache store', function (): void {
    $originalStorage = storage_path();
    $testStorage = $originalStorage.'/app/private/hardening-maintenance-'.bin2hex(random_bytes(8));
    mkdir($testStorage.'/framework', 0700, true);
    app()->useStoragePath($testStorage);
    Cache::shouldReceive('store')->never();
    try {
        config(['app.maintenance.driver' => 'file']);
        expect(app(TrialCleanupService::class)->maintenanceConfirmed())->toBeFalse();
        file_put_contents($testStorage.'/framework/down', '{}');
        expect(app(TrialCleanupService::class)->maintenanceConfirmed())->toBeTrue();
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
        expect(app(TrialCleanupService::class)->maintenanceConfirmed())->toBeFalse();
    } finally {
        app()->useStoragePath($originalStorage);
        if (is_file($testStorage.'/framework/down')) {
            unlink($testStorage.'/framework/down');
        }
        rmdir($testStorage.'/framework');
        rmdir($testStorage);
    }
});
