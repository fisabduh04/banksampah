<?php

use App\Services\TrialBackupRestorer;
use App\Services\TrialCleanupService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->mariaMetadataDatabase = null;
});

afterEach(function (): void {
    if ($this->mariaMetadataDatabase !== null) {
        DB::purge('maria_metadata');
        DB::purge('maria_metadata_writer');
        $admin = DB::connection('maria_metadata_admin');
        if (! app()->environment('testing')
            || ! preg_match('/\Abanksampah_cleanup_testing_maria_[a-f0-9]{12}\z/', $this->mariaMetadataDatabase)
            || $admin->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
            throw new RuntimeException('Refusing to drop an unowned test database.');
        }
        $admin->statement('DROP DATABASE `'.$this->mariaMetadataDatabase.'`');
        DB::purge('maria_metadata_admin');
    }
});

/** @return array{Connection, array<string, mixed>} */
function arrangeMariaMetadataCopy(TestCase $test, ?string $backup = null, string $hash = '82e828f0212e987fee214c729bf250d0ca0e307c2911bd2d34d270f0e4787e9f'): array
{
    if (! app()->environment('testing')) {
        throw new RuntimeException('Disposable test environment required.');
    }
    $backup ??= base_path('u846702626_banksampah (1).sql');
    if (! is_file($backup)) {
        $test->markTestSkipped('Private Hostinger backup is not available.');
    }
    $database = 'banksampah_cleanup_testing_maria_'.bin2hex(random_bytes(6));
    app(TrialBackupRestorer::class)->restore($backup, $hash, $database, 'DESKTOP-PDMMRQ1');
    $test->mariaMetadataDatabase = $database;
    $connection = [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '', 'database' => $database];
    config([
        'database.connections.maria_metadata' => $connection,
        'database.connections.maria_metadata_writer' => $connection,
        'database.connections.maria_metadata_admin' => [...$connection, 'database' => null],
    ]);
    $db = DB::connection('maria_metadata');
    $db->table('cache')->insert(['key' => 'maria-metadata-fixture', 'value' => 'before', 'expiration' => 1]);
    $db->table('sessions')->insert(['id' => 'maria-metadata-fixture', 'payload' => 'before', 'last_activity' => 1]);

    return [$db, app(TrialCleanupService::class)->manifest($db, $backup, $hash, true)];
}

/** MariaDB metadata fixture over a real isolated MySQL database, not a MariaDB server. */
function mariaMetadataService(): TrialCleanupService
{
    return new class extends TrialCleanupService
    {
        public function schema(Connection $db): array
        {
            $schema = parent::schema($db);
            foreach ($schema as &$definition) {
                foreach ($definition['foreign_keys'] as &$key) {
                    foreach (['delete_rule', 'update_rule'] as $rule) {
                        if ($key[$rule] === 'NO ACTION') {
                            $key[$rule] = 'RESTRICT';
                        }
                    }
                }
                unset($key);
            }
            unset($definition);
            foreach ($schema['failed_jobs']['columns'] as &$column) {
                if ($column['name'] === 'failed_at') {
                    $column['default_value'] = 'current_timestamp()';
                    $column['extra'] = '';
                }
            }

            return $schema;
        }
    };
}

test('all 42 foreign keys in the Hostinger MariaDB export are compared with the restored MySQL metadata', function (string $backup, string $hash): void {
    [$db, $manifest] = arrangeMariaMetadataCopy($this, $backup, $hash);
    $export = app(TrialBackupRestorer::class)->validateSql(file_get_contents($backup));
    $expected = $manifest['schema'];
    foreach ($expected as &$definition) {
        $definition['foreign_keys'] = [];
    }
    unset($definition);
    $count = 0;
    foreach ($export['statements'] as $statement) {
        if (! preg_match('/\AALTER TABLE `([a-z_]+)`/', $statement, $table)) {
            continue;
        }
        preg_match_all('/ADD CONSTRAINT `([^`]+)` FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)([^,;]*)/', $statement, $keys, PREG_SET_ORDER);
        foreach ($keys as $key) {
            preg_match('/ON DELETE (SET NULL|CASCADE|RESTRICT|NO ACTION)/', $key[5], $delete);
            preg_match('/ON UPDATE (SET NULL|CASCADE|RESTRICT|NO ACTION)/', $key[5], $update);
            $expected[$table[1]]['foreign_keys'][] = [
                'name' => $key[1], 'column_name' => $key[2], 'parent_table' => $key[3], 'parent_column' => $key[4],
                'delete_rule' => $delete[1] ?? 'RESTRICT', 'update_rule' => $update[1] ?? 'RESTRICT',
            ];
            $count++;
        }
    }
    foreach ($expected as &$definition) {
        usort($definition['foreign_keys'], fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
    }
    unset($definition);
    $service = app(TrialCleanupService::class);

    expect($count)->toBe(42);
    expect($service->schemaDifferences($service->schema($db), $expected))->toBe([]);
    foreach ($expected as $table => $definition) {
        foreach ($definition['foreign_keys'] as $position => $key) {
            foreach (['delete_rule', 'update_rule'] as $rule) {
                $changed = $expected;
                $changed[$table]['foreign_keys'][$position][$rule] = $key[$rule] === 'CASCADE' ? 'SET NULL' : 'CASCADE';
                expect(array_column($service->schemaDifferences($manifest['schema'], $changed), 'path'))
                    ->toBe([$table.'.foreign_keys.'.$position.'.'.$rule]);
            }
        }
    }
})->with([
    '24 September 15:58' => [__DIR__.'/../../u846702626_banksampah (1).sql', '82e828f0212e987fee214c729bf250d0ca0e307c2911bd2d34d270f0e4787e9f'],
    '25 September 01:55' => ['C:/Users/Lenovo/Downloads/u846702626_banksampah (2).sql', '152dfcbcda1866445ef58a211da38da65eea8d6195a1c54643d99b54778bdc2a'],
]);

test('MariaDB metadata fixture passes preflight and cleanup without altering any restored row after a dry run', function (): void {
    [$db, $manifest] = arrangeMariaMetadataCopy($this);
    $service = mariaMetadataService();
    foreach ($manifest['schema'] as &$definition) {
        unset($definition['engine']);
    }
    unset($definition);

    $inspection = $service->inspect($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1');
    $report = $service->clean($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1');

    expect($inspection['state'])->toBe('not_cleaned');
    expect($inspection['execution_ready'])->toBeTrue();
    expect($inspection['schema_differences'])->toBe([]);
    expect($report['status'])->toBe('simulated_rolled_back');
    expect($report['all_preserved_tables_identical'])->toBeTrue();
    expect($service->snapshot($db, $service->schema($db)))->toBe($manifest['tables']);
});

test('MariaDB metadata equivalence never excuses changed cache sessions master or transaction contents', function (string $table, string $field, string $value): void {
    [$db, $manifest] = arrangeMariaMetadataCopy($this);
    $service = mariaMetadataService();
    expect($service->inspect($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1')['state'])->toBe('not_cleaned');
    $db->table($table)->limit(1)->update([$field => $value]);
    $changed = $service->snapshot($db, $service->schema($db));
    $deletes = 0;
    $db->beforeExecuting(function (string $sql) use (&$deletes): void {
        if (preg_match('/\A\s*DELETE\b/i', $sql)) {
            $deletes++;
        }
    });

    $inspection = $service->inspect($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1');

    expect($inspection['schema_differences'])->toBe([]);
    expect($inspection['state'])->toBe('mismatch');
    expect($inspection['execution_ready'])->toBeFalse();
    expect(fn () => $service->clean($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1', true))->toThrow(RuntimeException::class, 'fingerprint');
    expect($deletes)->toBe(0);
    expect($service->snapshot($db, $service->schema($db)))->toBe($changed);
    expect($changed[$table]['count'])->toBe($manifest['tables'][$table]['count']);
})->with([
    'cache' => ['cache', 'value', 'drift'], 'sessions' => ['sessions', 'payload', 'drift'],
    'customers' => ['customers', 'name', 'drift'], 'categories' => ['waste_categories', 'name', 'drift'],
    'types' => ['waste_types', 'name', 'drift'], 'prices' => ['waste_prices', 'price', '12345.00'],
    'deposits' => ['deposits', 'notes', 'drift'], 'balance' => ['balance_mutations', 'reference_id', '900001'],
    'inventory' => ['inventory_movements', 'total_cost', '12345.00'], 'journals' => ['journal_entries', 'description', 'drift'],
]);

test('preflight reports every incompatible foreign key in the same result and cleanup refuses all deletes', function (): void {
    [$db, $manifest] = arrangeMariaMetadataCopy($this);
    $manifest['schema']['balance_mutations']['foreign_keys'][0]['delete_rule'] = 'CASCADE';
    $manifest['schema']['inventory_movements']['foreign_keys'][0]['update_rule'] = 'SET NULL';
    $service = mariaMetadataService();

    $inspection = $service->inspect($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1');

    expect($inspection['state'])->toBe('mismatch');
    expect(array_column($inspection['schema_differences'], 'path'))->toBe([
        'balance_mutations.foreign_keys.0.delete_rule', 'inventory_movements.foreign_keys.0.update_rule',
    ]);
    expect(fn () => $service->clean($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1', true))->toThrow(RuntimeException::class, 'Skema/foreign key');
    expect($service->snapshot($db, $service->schema($db)))->toBe($manifest['tables']);
});

test('preflight uses repeatable reads despite a read committed session and cleanup rechecks subsequent data drift', function (): void {
    [$db, $manifest] = arrangeMariaMetadataCopy($this);
    $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->statement('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    $global = $db->selectOne('SELECT @@global.transaction_isolation AS level')->level;
    $writer = DB::connection('maria_metadata_writer');
    $service = new class($writer) extends TrialCleanupService
    {
        public array $observed = [];

        public function __construct(private Connection $writer) {}

        public function snapshot(Connection $db, array $schema, bool $lock = false): array
        {
            $before = $db->table('cache')->where('key', 'maria-metadata-fixture')->value('value');
            $this->writer->table('cache')->where('key', 'maria-metadata-fixture')->update(['value' => 'concurrent drift']);
            $this->observed = [$before, $db->table('cache')->where('key', 'maria-metadata-fixture')->value('value')];

            return parent::snapshot($db, $schema, $lock);
        }
    };

    $inspection = $service->inspect($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1');

    expect($inspection['state'])->toBe('not_cleaned');
    expect($inspection['execution_ready'])->toBeTrue();
    expect($inspection['server'])->toMatchArray(['isolation' => 'REPEATABLE-READ', 'session_isolation' => 'READ-COMMITTED', 'access_mode' => 'READ ONLY']);
    expect($service->observed)->toBe(['before', 'before']);
    expect($db->selectOne('SELECT @@session.transaction_isolation AS level')->level)->toBe('READ-COMMITTED');
    expect($db->selectOne('SELECT @@global.transaction_isolation AS level')->level)->toBe($global);
    expect(fn () => app(TrialCleanupService::class)->clean($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1', true))->toThrow(RuntimeException::class, 'fingerprint');
});

test('cleanup establishes repeatable read range locks even when the session and pending transaction request weaker isolation', function (): void {
    [$db, $manifest] = arrangeMariaMetadataCopy($this);
    $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->statement('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    $global = $db->selectOne('SELECT @@global.transaction_isolation AS level')->level;
    $writer = DB::connection('maria_metadata_writer');
    $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $service = new class($writer) extends TrialCleanupService
    {
        public ?int $lockError = null;

        public function __construct(private Connection $writer) {}

        public function snapshot(Connection $db, array $schema, bool $lock = false): array
        {
            $snapshot = parent::snapshot($db, $schema, $lock);
            if ($lock && $this->lockError === null) {
                try {
                    $this->writer->table('cache')->insert(['key' => 'zz-concurrent-insert', 'value' => 'phantom', 'expiration' => 1]);
                } catch (QueryException $exception) {
                    $this->lockError = (int) $exception->errorInfo[1];
                }
            }

            return $snapshot;
        }
    };

    $report = $service->clean($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1');

    expect($service->lockError)->toBe(1205);
    expect($report['status'])->toBe('simulated_rolled_back');
    expect($report['server'])->toMatchArray(['isolation' => 'REPEATABLE-READ', 'session_isolation' => 'READ-COMMITTED', 'access_mode' => 'READ WRITE']);
    expect($db->selectOne('SELECT @@session.transaction_isolation AS level')->level)->toBe('READ-COMMITTED');
    expect($db->selectOne('SELECT @@global.transaction_isolation AS level')->level)->toBe($global);
    expect(app(TrialCleanupService::class)->snapshot($db, $manifest['schema']))->toBe($manifest['tables']);
});

test('cleanup cannot borrow an outer transaction whose actual isolation is unknown', function (): void {
    [$db, $manifest] = arrangeMariaMetadataCopy($this);
    $db->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    try {
        expect(fn () => app(TrialCleanupService::class)->clean($db, $manifest, $db->getDatabaseName(), 'DESKTOP-PDMMRQ1'))
            ->toThrow(RuntimeException::class, 'tanpa transaksi aktif');
        expect($db->transactionLevel())->toBe(1);
    } finally {
        $db->rollBack();
    }
    expect(app(TrialCleanupService::class)->snapshot($db, $manifest['schema']))->toBe($manifest['tables']);
});
