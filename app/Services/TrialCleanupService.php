<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

class TrialCleanupService
{
    public const TRANSACTIONS = ['balance_mutations', 'cash_mutations', 'deposit_items', 'deposits', 'inventory_cost_reconciliations', 'inventory_movements', 'journal_entries', 'journal_lines', 'sale_items', 'sale_payments', 'sales', 'withdrawals'];

    public const MASTERS = ['customers', 'waste_categories', 'waste_types', 'waste_prices'];

    public const PRESERVE = ['accounts', 'cache', 'cache_locks', 'cash_accounts', 'collectors', 'customers', 'exports', 'failed_import_rows', 'failed_jobs', 'financial_control_events', 'financial_controls', 'imports', 'jobs', 'job_batches', 'migrations', 'notifications', 'password_reset_tokens', 'sessions', 'users', 'waste_categories', 'waste_prices', 'waste_types'];

    public const OBSOLETE_BACKUP = 'b050b725e68d2be96dfdf0d9b13289c7b3c9db29749668dc314758791c811fb8';

    /** @return array<string, mixed> */
    public function manifest(Connection $db, string $backup, string $backupHash, bool $confirmedLatest = false): array
    {
        $this->verifyBackup($backup, $backupHash);
        $identity = $this->identity($db);
        if (! str_starts_with($identity['database'], 'banksampah_cleanup_testing_') || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Manifest hanya dibuat dari restore terpisah banksampah_cleanup_testing_*.');
        }
        if ($confirmedLatest && $backupHash === self::OBSOLETE_BACKUP) {
            throw new RuntimeException('Snapshot 24 September 14.05 diketahui usang; bukan backup terbaru.');
        }
        $this->beginIsolatedTransaction($db, readOnly: true);
        try {
            $schema = $this->schema($db);
            $snapshot = $this->snapshot($db, $schema);
            $blockers = $this->classificationBlockers($db, $schema);
            preg_match('/-- Database: `([a-zA-Z0-9_]+)`/', file_get_contents($backup), $source);

            return [
                'format' => 1, 'source_sha256' => $backupHash, 'source_database' => $source[1] ?? null,
                'source_confirmed_latest' => $confirmedLatest, 'restored_database' => $identity,
                'schema' => $schema, 'tables' => $snapshot, 'totals' => $this->totals($db),
                'deletion_order' => $blockers === [] ? $this->deletionOrder($schema) : [],
                'foreign_key_orphans' => $this->foreignKeyOrphans($db, $schema),
                'reference_findings' => $this->referenceFindings($db),
                'preservation_policy' => 'Preserve all master and system tables byte-for-byte, including import/export history, cache, sessions and job metadata.',
                'related_export_archives' => $this->normalize($db->table('exports')->select(['id', 'exporter', 'file_name', 'file_disk'])->orderBy('id')->get()->all()),
                'blockers' => $blockers,
                'decision' => 'DELETE all rows of the reviewed financial transaction tables after matching the full snapshot; preserve every master and system table. No opening balances.',
            ];
        } finally {
            $db->rollBack();
        }
    }

    public function verifyBackup(string $path, string $hash): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $hash) || ! is_file($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
            throw new RuntimeException('Backup/hash tidak cocok.');
        }
    }

    /** @return array{database: string, server: string} */
    public function identity(Connection $db): array
    {
        $db->useWriteConnectionWhenReading();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Operasi ini membutuhkan MySQL/MariaDB dengan InnoDB.');
        }
        $row = $db->selectOne('SELECT DATABASE() AS db, @@hostname AS server, @@foreign_key_checks AS fk');
        if ((int) $row->fk !== 1) {
            throw new RuntimeException('Foreign key harus tetap aktif.');
        }

        return ['database' => $row->db, 'server' => $row->server];
    }

    /** @return array<string, mixed> */
    public function schema(Connection $db): array
    {
        $tables = $db->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY TABLE_NAME');
        $result = [];
        foreach ($tables as $table) {
            if ($table->type !== 'BASE TABLE' || $table->engine !== 'InnoDB' || ! preg_match('/\A[a-z_]+\z/', $table->name)) {
                throw new RuntimeException('Tabel/view/engine belum didukung: '.$table->name);
            }
            $columns = $db->select('SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS full_type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, CHARACTER_MAXIMUM_LENGTH AS max_length, NUMERIC_PRECISION AS numeric_precision, NUMERIC_SCALE AS numeric_scale, DATETIME_PRECISION AS datetime_precision, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, EXTRA AS extra, GENERATION_EXPRESSION AS generation_expression FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ORDINAL_POSITION', [$table->name]);
            $columns = $this->normalizeColumns($columns);
            $indexes = $db->select('SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name, SUB_PART AS prefix_length, INDEX_TYPE AS type FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table->name]);
            $foreignKeys = $db->select('SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS parent_table, k.REFERENCED_COLUMN_NAME AS parent_column, r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.table_name = k.table_name AND r.constraint_name = k.constraint_name WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [$table->name]);
            $crossSchema = $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND referenced_table_schema <> DATABASE()', [$table->name]);
            if ((int) $crossSchema->n !== 0) {
                throw new RuntimeException('Foreign key lintas database harus direview.');
            }
            $result[$table->name] = $this->normalize(['engine' => $table->engine, 'columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys]);
        }
        foreach (['triggers' => 'TRIGGER_SCHEMA', 'routines' => 'ROUTINE_SCHEMA', 'events' => 'EVENT_SCHEMA'] as $table => $column) {
            if ((int) $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.'.$table.' WHERE '.$column.' = DATABASE()')->n !== 0) {
                throw new RuntimeException('Trigger/routine/event harus direview sebelum pembersihan.');
            }
        }

        return $result;
    }

    /** @param list<array<string, mixed>|object> $columns
     * @return list<array<string, mixed>>
     */
    public function normalizeColumns(array $columns): array
    {
        return array_map(function (array|object $definition): array {
            $column = (array) $definition;
            $column['full_type'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $column['full_type']);
            $column['generation_expression'] ??= '';
            $column['default_value'] = $column['default_value'] === 'NULL' ? null : $column['default_value'];
            if (is_string($column['default_value']) && str_starts_with($column['default_value'], "'") && str_ends_with($column['default_value'], "'")) {
                $column['default_value'] = str_replace("''", "'", substr($column['default_value'], 1, -1));
            }

            return $this->normalize($column);
        }, $columns);
    }

    /**
     * Compare every metadata field; format-1 manifests without an engine were
     * produced by schema(), which already rejected every non-InnoDB table.
     *
     * @return list<array{path: string, expected: mixed, actual: mixed}>
     */
    public function schemaDifferences(array $actual, array $expected): array
    {
        foreach ($expected as $table => &$definition) {
            if (! array_key_exists('engine', $definition)) {
                $definition['engine'] = 'InnoDB';
            }
            if (($actual[$table]['engine'] ?? null) === 'InnoDB' && $definition['engine'] === 'InnoDB') {
                foreach (['delete_rule', 'update_rule'] as $rule) {
                    foreach ($definition['foreign_keys'] as &$key) {
                        if ($key[$rule] === 'NO ACTION') {
                            $key[$rule] = 'RESTRICT';
                        }
                    }
                    unset($key);
                    foreach ($actual[$table]['foreign_keys'] as &$key) {
                        if ($key[$rule] === 'NO ACTION') {
                            $key[$rule] = 'RESTRICT';
                        }
                    }
                    unset($key);
                }
            }
            if ($table === 'failed_jobs') {
                foreach ($definition['columns'] as $position => &$column) {
                    $other = $actual[$table]['columns'][$position] ?? [];
                    if ($this->equivalentFailedAt($column, $other)) {
                        $column['default_value'] = $actual[$table]['columns'][$position]['default_value'] = 'CURRENT_TIMESTAMP';
                        $column['extra'] = $actual[$table]['columns'][$position]['extra'] = '';
                    }
                }
                unset($column);
            }
        }
        unset($definition);

        return $this->metadataDifferences($actual, $expected);
    }

    /** Only the default expression and its MySQL metadata marker may differ. */
    private function equivalentFailedAt(array $expected, array $actual): bool
    {
        foreach ([$expected, $actual] as $column) {
            if (($column['name'] ?? null) !== 'failed_at'
                || ($column['type'] ?? null) !== 'timestamp'
                || ($column['full_type'] ?? null) !== 'timestamp'
                || ($column['datetime_precision'] ?? null) !== '0'
                || ($column['generation_expression'] ?? null) !== ''
                || ! is_string($column['default_value'] ?? null)
                || ! preg_match('/\ACURRENT_TIMESTAMP(?:\(\))?\z/i', $column['default_value'])
                || ! in_array($column['extra'] ?? null, ['', 'DEFAULT_GENERATED'], true)) {
                return false;
            }
        }
        unset($expected['default_value'], $expected['extra'], $actual['default_value'], $actual['extra']);

        return $expected === $actual;
    }

    /** @return list<array{path: string, expected: mixed, actual: mixed}> */
    private function metadataDifferences(array $actual, array $expected, string $prefix = ''): array
    {
        $differences = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (! array_key_exists($key, $actual) || ! array_key_exists($key, $expected)) {
                $differences[] = ['path' => $path, 'expected' => $expected[$key] ?? null, 'actual' => $actual[$key] ?? null];
            } elseif (is_array($actual[$key]) && is_array($expected[$key])) {
                array_push($differences, ...$this->metadataDifferences($actual[$key], $expected[$key], $path));
            } elseif ($actual[$key] !== $expected[$key]) {
                $differences[] = ['path' => $path, 'expected' => $expected[$key], 'actual' => $actual[$key]];
            }
        }

        return $differences;
    }

    /** Session defaults are diagnostic only, not the active transaction isolation.
     * @return array{version: string, engine: string, isolation: string}
     */
    public function serverInfo(Connection $db): array
    {
        $version = (string) $db->selectOne('SELECT VERSION() AS version')->version;
        $maria = stripos($version, 'MariaDB') !== false;
        $pattern = $maria ? '/(\d+\.\d+\.\d+)-MariaDB/i' : '/\A(\d+\.\d+\.\d+)/';
        if (! preg_match($pattern, $version, $match) || version_compare($match[1], $maria ? '10.6.0' : '8.0.0', '<')) {
            throw new RuntimeException('Versi server di luar lingkup metadata yang didukung (MySQL 8+, MariaDB 10.6+).');
        }
        try {
            $isolation = $db->selectOne('SELECT @@transaction_isolation AS level')->level;
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1193) {
                throw $exception;
            }
            $isolation = $db->selectOne('SELECT @@tx_isolation AS level')->level;
        }

        return ['version' => $version, 'engine' => $maria ? 'MariaDB' : 'MySQL', 'isolation' => strtoupper($isolation)];
    }

    /**
     * SET TRANSACTION applies to the immediately following transaction only.
     * A savepoint cannot establish isolation for an existing outer transaction.
     *
     * @return array{version: string, engine: string, isolation: string, session_isolation: string, access_mode: string}
     */
    private function beginIsolatedTransaction(Connection $db, bool $readOnly): array
    {
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('Operasi harus memakai koneksi tanpa transaksi aktif.');
        }
        $server = $this->serverInfo($db);
        $db->statement("SET time_zone = '+00:00'");
        $access = $readOnly ? 'READ ONLY' : 'READ WRITE';
        if (! $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, '.$access)) {
            throw new RuntimeException('Tidak dapat menetapkan isolasi transaksi REPEATABLE READ.');
        }
        $db->beginTransaction();

        return [...$server, 'session_isolation' => $server['isolation'], 'isolation' => 'REPEATABLE-READ', 'access_mode' => $access];
    }

    /** @return array<string, mixed> */
    private function expectedAfter(array $manifest): array
    {
        if (($manifest['format'] ?? null) !== 1 || ($manifest['blockers'] ?? null) !== [] || ($manifest['foreign_key_orphans'] ?? null) !== []) {
            throw new RuntimeException('Manifest belum lengkap atau masih memiliki penghalang klasifikasi/FK.');
        }
        $after = $manifest['tables'];
        foreach (self::TRANSACTIONS as $table) {
            $expected = $after[$table] ?? null;
            if ($expected === null || $expected['action'] !== 'delete_all_verified_trial_rows' || count($expected['rows']) !== $expected['count'] || count(array_unique(array_column($expected['rows'], 'id'))) !== $expected['count']) {
                throw new RuntimeException('Daftar ID/jumlah manifest tidak lengkap: '.$table);
            }
            $after[$table] = ['count' => 0, 'sha256' => hash('sha256', ''), 'action' => 'delete_all_verified_trial_rows', 'rows' => []];
        }

        return $after;
    }

    /** Cache reads can delete expired database cache rows; only the file driver is safe here. */
    public function maintenanceConfirmed(): bool
    {
        return config('app.maintenance.driver', 'file') === 'file' && is_file(storage_path('framework/down'));
    }

    /** Nonlocking consistent read; never call clean() from this verification path.
     * @return array<string, mixed>
     */
    public function inspect(Connection $db, array $manifest, string $expectedDatabase, string $expectedServer): array
    {
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('Verifikasi read-only harus memakai koneksi tanpa transaksi aktif.');
        }
        $identity = $this->identity($db);
        if ($identity !== ['database' => $expectedDatabase, 'server' => $expectedServer] || $expectedDatabase === '' || $expectedServer === '') {
            throw new RuntimeException('Identitas koneksi/database tidak cocok.');
        }
        $local = str_starts_with($expectedDatabase, 'banksampah_cleanup_testing_') && in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true);
        if (! $local && $expectedDatabase !== ($manifest['source_database'] ?? null)) {
            throw new RuntimeException('Database sumber manifest tidak cocok.');
        }
        $afterExpected = $this->expectedAfter($manifest);
        $server = $this->beginIsolatedTransaction($db, readOnly: true);
        try {
            $schema = $this->schema($db);
            $actual = $this->snapshot($db, $schema);
            $reasons = $this->classificationBlockers($db, $schema);
            $schemaDifferences = $this->schemaDifferences($schema, $manifest['schema']);
            if ($schemaDifferences !== []) {
                $reasons[] = 'Skema/foreign key berbeda dari manifest.';
            }
            $state = $this->snapshotsMatch($actual, $manifest['tables']) ? 'not_cleaned' : ($this->snapshotsMatch($actual, $afterExpected) ? 'already_clean' : 'mismatch');
            $orphans = $this->foreignKeyOrphans($db, $schema);
            $totals = $this->totals($db);
            if ($orphans !== [] || ($state === 'not_cleaned' && $totals !== $manifest['totals'])) {
                $reasons[] = 'Foreign key/saldo berbeda.';
            }
            if ($state === 'already_clean') {
                if ($this->referenceFindings($db) !== [] || array_filter($totals, fn (string $value): bool => ! BigDecimal::of($value)->isZero()) !== []) {
                    $reasons[] = 'Saldo/referensi transaksi tertinggal.';
                }
            }
            if ($reasons !== []) {
                $state = 'mismatch';
            }
            $productionReady = $local || (app()->isProduction() && $this->maintenanceConfirmed() && ($manifest['source_confirmed_latest'] ?? false) && $manifest['source_sha256'] !== self::OBSOLETE_BACKUP);

            return ['state' => $state, 'identity' => $identity, 'server' => $server, 'foreign_key_checks' => 1,
                'innodb_tables' => count($schema), 'schema_sha256' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
                'execution_ready' => $state !== 'mismatch' && $productionReady, 'reasons' => $reasons, 'schema_differences' => $schemaDifferences, 'tables' => $actual, 'totals' => $totals];
        } finally {
            $db->rollBack();
        }
    }

    /** Compare copies with only table keys sorted; keep all table contents strict. */
    public function snapshotsMatch(array $actual, array $expected): bool
    {
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);

        return $actual === $expected;
    }

    /** @return array<string, mixed> */
    public function snapshot(Connection $db, array $schema, bool $lock = false): array
    {
        $result = [];
        foreach ($schema as $table => $definition) {
            $query = $db->table($table);
            foreach ($definition['indexes'] as $index) {
                if ($index['name'] === 'PRIMARY') {
                    $query->orderBy($index['column_name']);
                }
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            $rows = $query->get();
            $hashes = [];
            $entries = [];
            foreach ($rows as $row) {
                $normalized = $this->normalize((array) $row);
                $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
                $hashes[] = $hash;
                if (in_array($table, self::TRANSACTIONS, true)) {
                    $references = array_filter($normalized, fn (string $key): bool => preg_match('/(?:_id|_type|_number|_date)\z|\A(?:status|amount|quantity|total_cost|total_amount|weight|price|subtotal|debit|credit)\z/', $key) === 1, ARRAY_FILTER_USE_KEY);
                    $entries[] = ['id' => $normalized['id'], 'sha256' => $hash, 'evidence' => $references];
                }
            }
            sort($hashes, SORT_STRING);
            $result[$table] = ['count' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes)), 'action' => in_array($table, self::TRANSACTIONS, true) ? 'delete_all_verified_trial_rows' : 'preserve', 'rows' => $entries];
        }

        return $result;
    }

    /** @return array<string, string> */
    public function totals(Connection $db): array
    {
        $queries = [
            'savings' => "SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS value FROM balance_mutations",
            'inventory_quantity' => "SELECT COALESCE(SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END), 0) AS value FROM inventory_movements",
            'inventory_cost' => "SELECT COALESCE(SUM(CASE WHEN movement_type = 'in' THEN total_cost ELSE -total_cost END), 0) AS value FROM inventory_movements",
            'receivables' => "SELECT COALESCE((SELECT SUM(total_amount) FROM sales WHERE status = 'posted'), 0) - COALESCE((SELECT SUM(p.amount) FROM sale_payments p JOIN sales s ON s.id = p.sale_id WHERE s.status = 'posted' AND p.status = 'posted'), 0) AS value",
            'cash' => "SELECT COALESCE(SUM(CASE WHEN mutation_type = 'in' THEN amount ELSE -amount END), 0) AS value FROM cash_mutations",
            'gl_debit' => 'SELECT COALESCE(SUM(debit), 0) AS value FROM journal_lines',
            'gl_credit' => 'SELECT COALESCE(SUM(credit), 0) AS value FROM journal_lines',
        ];
        $result = [];
        foreach ($queries as $name => $sql) {
            $result[$name] = (string) $db->selectOne($sql)->value;
        }

        return $result;
    }

    /** @return list<string> */
    private function classificationBlockers(Connection $db, array $schema): array
    {
        $blockers = [];
        foreach (array_diff(self::TRANSACTIONS, array_keys($schema)) as $missing) {
            $blockers[] = 'Tabel transaksi wajib tidak ada: '.$missing;
        }
        foreach (array_keys($schema) as $table) {
            if (! in_array($table, [...self::TRANSACTIONS, ...self::PRESERVE], true)) {
                $blockers[] = 'Tabel belum diklasifikasikan: '.$table;
            }
        }
        foreach (self::MASTERS as $table) {
            foreach ($schema[$table]['columns'] ?? [] as $column) {
                if (preg_match('/\A(?:balance|balance_amount|stock|stock_kg|quantity|opening_balance)\z/', $column['name']) && $db->table($table)->where($column['name'], '<>', 0)->exists()) {
                    $blockers[] = 'Saldo tersimpan pada master nyata harus direview tanpa mengubah master: '.$table.'.'.$column['name'];
                }
            }
        }

        return $blockers;
    }

    /** @return list<array<string, mixed>> */
    public function foreignKeyOrphans(Connection $db, array $schema): array
    {
        $findings = [];
        foreach ($schema as $table => $definition) {
            $groups = [];
            foreach ($definition['foreign_keys'] as $key) {
                $groups[$key['name']][] = $key;
            }
            foreach ($groups as $name => $keys) {
                $predicates = [];
                $nonNull = [];
                foreach ($keys as $key) {
                    $predicates[] = 'p.`'.$key['parent_column'].'` = c.`'.$key['column_name'].'`';
                    $nonNull[] = 'c.`'.$key['column_name'].'` IS NOT NULL';
                }
                $count = (int) $db->selectOne('SELECT COUNT(*) AS n FROM `'.$table.'` c WHERE '.implode(' AND ', $nonNull).' AND NOT EXISTS (SELECT 1 FROM `'.$keys[0]['parent_table'].'` p WHERE '.implode(' AND ', $predicates).')')->n;
                if ($count > 0) {
                    $findings[] = ['table' => $table, 'foreign_key' => $name, 'count' => $count];
                }
            }
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    public function referenceFindings(Connection $db): array
    {
        $findings = [];
        $map = ['deposit' => 'deposits', 'deposit_cancellation' => 'deposits', 'withdrawal' => 'withdrawals', 'withdrawal_cancellation' => 'withdrawals', 'sale' => 'sales', 'sale_cancellation' => 'sales', 'sale_payment' => 'sale_payments', 'sale_payment_cancellation' => 'sale_payments', 'cost_reconciliation' => 'inventory_cost_reconciliations', 'cost_reconciliation_reversal' => 'inventory_cost_reconciliations'];
        foreach (['balance_mutations', 'inventory_movements', 'cash_mutations', 'journal_entries'] as $table) {
            foreach ($db->table($table)->select(['id', 'reference_type', 'reference_id'])->orderBy('id')->get() as $row) {
                $parent = $map[$row->reference_type] ?? null;
                if ($parent === null || $row->reference_id === null || ! $db->table($parent)->where('id', $row->reference_id)->exists()) {
                    $findings[] = ['table' => $table, 'id' => (string) $row->id, 'reference_type' => $row->reference_type, 'reference_id' => $row->reference_id === null ? null : (string) $row->reference_id, 'finding' => $parent === null ? 'reference type needs review; row classified as trial by table' : 'orphan source; ledger row remains included in the verified full-table cleanup'];
                }
            }
        }

        return $findings;
    }

    /** @return array<string, mixed> */
    public function clean(Connection $db, array $manifest, string $expectedDatabase, string $expectedServer, bool $commit = false, ?Closure $beforeCommit = null): array
    {
        if ($this->identity($db) !== ['database' => $expectedDatabase, 'server' => $expectedServer] || $expectedDatabase === '' || $expectedServer === '') {
            throw new RuntimeException('Identitas koneksi/database tidak cocok.');
        }
        $isLocalCopy = str_starts_with($expectedDatabase, 'banksampah_cleanup_testing_') && in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true);
        if (! $isLocalCopy && (! $commit || ! app()->isProduction() || ! $this->maintenanceConfirmed() || $expectedDatabase !== ($manifest['source_database'] ?? null) || ! ($manifest['source_confirmed_latest'] ?? false) || ($manifest['source_sha256'] ?? '') === self::OBSOLETE_BACKUP)) {
            throw new RuntimeException('Produksi membutuhkan backup terbaru terkonfirmasi, maintenance driver file aktif, database sumber cocok, dan eksekusi eksplisit. Simulasi hanya pada salinan lokal.');
        }
        if (($manifest['format'] ?? null) !== 1 || ($manifest['blockers'] ?? ['manifest incomplete']) !== [] || ($manifest['foreign_key_orphans'] ?? ['manifest incomplete']) !== []) {
            throw new RuntimeException('Manifest belum lengkap atau masih memiliki penghalang klasifikasi/FK.');
        }
        $server = $this->beginIsolatedTransaction($db, readOnly: false);
        try {
            $schema = $this->schema($db);
            $schemaDifferences = $this->schemaDifferences($schema, $manifest['schema']);
            if ($schemaDifferences !== []) {
                throw new RuntimeException('Skema/foreign key berbeda dari manifest: '.json_encode($schemaDifferences, JSON_THROW_ON_ERROR));
            }
            $before = $this->snapshot($db, $schema, lock: true);
            $expected = $manifest['tables'];
            $empty = ['count' => 0, 'sha256' => hash('sha256', ''), 'action' => 'delete_all_verified_trial_rows', 'rows' => []];
            $afterExpected = $expected;
            foreach (self::TRANSACTIONS as $table) {
                if (! isset($expected[$table]) || $expected[$table]['action'] !== 'delete_all_verified_trial_rows' || count($expected[$table]['rows']) !== $expected[$table]['count'] || count(array_unique(array_column($expected[$table]['rows'], 'id'))) !== $expected[$table]['count']) {
                    throw new RuntimeException('Daftar ID/jumlah manifest tidak lengkap: '.$table);
                }
                $afterExpected[$table] = $empty;
            }
            $alreadyClean = $this->snapshotsMatch($before, $afterExpected);
            if (! $alreadyClean && ! $this->snapshotsMatch($before, $expected)) {
                throw new RuntimeException('ID, jumlah, referensi, atau fingerprint data berbeda dari manifest.');
            }
            if ($this->classificationBlockers($db, $schema) !== [] || $this->foreignKeyOrphans($db, $schema) !== []) {
                throw new RuntimeException('Klasifikasi/foreign key tidak memenuhi prasyarat.');
            }
            $totalsBefore = $this->totals($db);
            if (! $alreadyClean && $totalsBefore !== $manifest['totals']) {
                throw new RuntimeException('Saldo sebelum berbeda dari manifest.');
            }
            $deleted = array_fill_keys(self::TRANSACTIONS, 0);
            if (! $alreadyClean) {
                foreach ($this->deletionOrder($schema) as $table) {
                    $query = $db->table($table);
                    $selfKeys = array_filter($schema[$table]['foreign_keys'], fn (array $key): bool => $key['parent_table'] === $table);
                    foreach ($selfKeys as $key) {
                        if ($key['parent_column'] !== 'id' || $db->table($table)->whereColumn($key['column_name'], '>=', 'id')->exists()) {
                            throw new RuntimeException('Urutan referensi internal '.$table.' harus direview; proses di-rollback.');
                        }
                    }
                    if ($selfKeys !== []) {
                        $query->orderByDesc('id');
                    }
                    $count = $query->delete();
                    if ($count !== $expected[$table]['count']) {
                        throw new RuntimeException('Jumlah DELETE tidak cocok: '.$table);
                    }
                    $deleted[$table] = $count;
                }
            }
            $after = $this->snapshot($db, $schema, lock: true);
            if (! $this->snapshotsMatch($after, $afterExpected) || $this->schema($db) !== $schema || $this->foreignKeyOrphans($db, $schema) !== [] || $this->referenceFindings($db) !== []) {
                throw new RuntimeException('Verifikasi akhir gagal: data master berubah atau transaksi/referensi tertinggal.');
            }
            $totalsAfter = $this->totals($db);
            foreach ($totalsAfter as $value) {
                if (! BigDecimal::of($value)->isZero()) {
                    throw new RuntimeException('Saldo uji masih tersisa.');
                }
            }
            $report = ['status' => $alreadyClean ? 'already_clean_noop' : ($commit ? 'committed' : 'simulated_rolled_back'), 'identity' => $this->identity($db), 'server' => $server, 'deleted' => $deleted, 'before' => $before, 'after' => $after, 'totals_before' => $totalsBefore, 'totals_after' => $totalsAfter, 'master_fingerprints_identical' => $this->snapshotsMatch(array_intersect_key($before, array_flip(self::MASTERS)), array_intersect_key($after, array_flip(self::MASTERS))), 'all_preserved_tables_identical' => $this->snapshotsMatch(array_diff_key($before, array_flip(self::TRANSACTIONS)), array_diff_key($after, array_flip(self::TRANSACTIONS))), 'foreign_key_orphans_after' => [], 'reference_findings_after' => []];
            if ($commit) {
                $beforeCommit?->__invoke();
                $db->commit();
            } else {
                $db->rollBack();
            }

            return $report;
        } catch (Throwable $exception) {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    private function deletionOrder(array $schema): array
    {
        $remaining = self::TRANSACTIONS;
        $result = [];
        while ($remaining !== []) {
            $leaves = [];
            foreach ($remaining as $candidate) {
                $hasChild = false;
                foreach ($remaining as $child) {
                    foreach ($schema[$child]['foreign_keys'] as $key) {
                        if ($child !== $candidate && $key['parent_table'] === $candidate) {
                            $hasChild = true;
                        }
                    }
                }
                if (! $hasChild) {
                    $leaves[] = $candidate;
                }
            }
            if ($leaves === []) {
                throw new RuntimeException('Siklus relasi antar tabel membutuhkan review.');
            }
            array_push($result, ...$leaves);
            $remaining = array_values(array_diff($remaining, $leaves));
        }

        return $result;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            $result = [];
            foreach ((array) $value as $key => $item) {
                $result[$key] = $this->normalize($item);
            }

            return $result;
        }

        return $value === null ? null : (string) $value;
    }
}
