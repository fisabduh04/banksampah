<?php

use App\Services\FinancialDocumentService;
use App\Services\TrialBackupRestorer;
use App\Services\TrialCleanupReport;
use App\Services\TrialCleanupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bank-sampah:renumber-documents {--apply}', function (FinancialDocumentService $service): int {
    $apply = (bool) $this->option('apply');
    $changes = $service->renumberLegacyDocuments($apply);
    $this->table(['Jenis', 'ID', 'Nomor lama', 'Nomor baru'], $changes);
    $this->info($apply ? 'Penomoran dan rujukan diperbarui; riwayat perubahan tersimpan.' : 'Pratinjau saja; belum ada perubahan tersimpan.');

    return 0;
})->purpose('Rapikan nomor ULID lama tanpa mengubah nilai transaksi; gunakan --apply untuk menyimpan');

Artisan::command('bank-sampah:trial-prepare {--backup=} {--backup-sha=} {--database=} {--server=} {--out=} {--latest}', function (TrialBackupRestorer $restorer, TrialCleanupService $cleanup): int {
    $output = $this->option('out');
    if (! $output || ! is_dir(dirname($output)) || realpath(dirname($output)) !== realpath(storage_path('app/private')) || file_exists($output)) {
        $this->error('Output harus file baru dalam storage/app/private.');

        return 1;
    }
    try {
        $restored = $restorer->restore((string) $this->option('backup'), (string) $this->option('backup-sha'), (string) $this->option('database'), (string) $this->option('server'));
        config(['database.connections.trial_prepare' => [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '', 'database' => $restored['target_database']]]);
        DB::purge('trial_prepare');
        $manifest = $cleanup->manifest(DB::connection('trial_prepare'), (string) $this->option('backup'), (string) $this->option('backup-sha'), (bool) $this->option('latest'));
        $manifest['restore'] = $restored;
        $handle = fopen($output, 'x');
        if ($handle === false) {
            throw new RuntimeException('Tidak dapat menyimpan manifest.');
        }
        fwrite($handle, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        fclose($handle);
        $this->line(json_encode(['manifest' => $output, 'manifest_sha256' => hash_file('sha256', $output), 'restored' => $restored, 'blockers' => $manifest['blockers']], JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Restore a full backup to a new local test database and review every transaction table before DELETE');

Artisan::command('bank-sampah:trial-cleanup {--connection=} {--database=} {--server=} {--manifest=} {--manifest-sha=} {--backup=} {--backup-sha=} {--report=} {--execute} {--preflight} {--verify}', function (TrialCleanupService $cleanup, TrialCleanupReport $reporter): int {
    $commitAttempted = false;
    $readOnly = (bool) $this->option('preflight') || (bool) $this->option('verify');
    try {
        if (array_sum([(int) $this->option('execute'), (int) $this->option('preflight'), (int) $this->option('verify')]) > 1) {
            throw new RuntimeException('Pilih hanya satu mode: execute, preflight, atau verify.');
        }
        $manifestPath = (string) $this->option('manifest');
        $cleanup->verifyBackup($manifestPath, (string) $this->option('manifest-sha'));
        $cleanup->verifyBackup((string) $this->option('backup'), (string) $this->option('backup-sha'));
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['source_sha256'] ?? '') !== $this->option('backup-sha') || ! $this->option('connection')) {
            throw new RuntimeException('Backup tidak cocok dengan manifest atau koneksi belum dipilih secara eksplisit.');
        }
        if ($this->option('connection') === 'trial_cleanup_local') {
            config(['database.connections.trial_cleanup_local' => [...config('database.connections.mysql'), 'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '', 'database' => $this->option('database')]]);
            DB::purge('trial_cleanup_local');
        }
        $db = DB::connection($this->option('connection'));
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('Command harus memakai koneksi tanpa transaksi aktif.');
        }
        if ($readOnly) {
            $inspection = $cleanup->inspect($db, $manifest, (string) $this->option('database'), (string) $this->option('server'));
            if ($this->option('preflight')) {
                $reporter->probe((string) $this->option('report'), filesize($manifestPath));
                $inspection['report_ready'] = true;
            }
            $this->line(json_encode($inspection, JSON_THROW_ON_ERROR));

            return $inspection['state'] === 'mismatch' || ($this->option('preflight') && ! $inspection['execution_ready']) ? 1 : 0;
        }
        $reporter->start((string) $this->option('report'), filesize($manifestPath), [
            'manifest_sha256' => $this->option('manifest-sha'), 'backup_sha256' => $this->option('backup-sha'),
            'expected_database' => $this->option('database'), 'expected_server' => $this->option('server'),
        ]);
        $report = $cleanup->clean($db, $manifest, (string) $this->option('database'), (string) $this->option('server'), (bool) $this->option('execute'), function () use (&$commitAttempted): void {
            $commitAttempted = true;
        });
        $reporter->finish(['status' => $report['status'], 'manifest_sha256' => $this->option('manifest-sha'), 'result' => $report]);
        $this->line(json_encode(['status' => $report['status'], 'deleted' => $report['deleted'], 'totals_before' => $report['totals_before'], 'totals_after' => $report['totals_after'], 'master_fingerprints_identical' => $report['master_fingerprints_identical'], 'report' => $this->option('report')], JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $exception) {
        if ($commitAttempted) {
            $this->error('COMMIT_MAY_HAVE_SUCCEEDED: commit mungkin sudah berhasil. Jangan ulangi DELETE. Jalankan bank-sampah:trial-cleanup --verify dengan koneksi, database, server, backup, manifest dan hash yang sama; tanpa --execute. '.$exception->getMessage());

            return 2;
        }
        $this->error(($readOnly ? 'VERIFICATION_FAILED: ' : '').$exception->getMessage());

        return 1;
    } finally {
        $reporter->close();
    }
})->purpose('DELETE reviewed trial rows; --preflight and --verify only read the database, default dry-run is local only');
