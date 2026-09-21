<?php

use App\Models\Collector;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\SalePaymentService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @return array<int, array{status: string, id?: int, message?: string}> */
function raceSalePaymentProcesses(string $database, string $table, int $id, array $operations): array
{
    $processes = [];
    $temporaryFiles = [];
    $goPath = tempnam(sys_get_temp_dir(), 'banksampah-go-');
    unlink($goPath);
    $temporaryFiles[] = $goPath;
    DB::beginTransaction();
    DB::table($table)->where('id', $id)->lockForUpdate()->first();
    try {
        foreach ($operations as $operation) {
            $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! preg_match('/^banksampah_finance_test_[a-f0-9]{12}$/', Illuminate\Support\Facades\DB::connection()->getDatabaseName())) {
    throw new RuntimeException('Proses pengujian menolak database di luar lingkup uji.');
}
touch(getenv('FINANCE_RACE_READY'));
$deadline = microtime(true) + 30;
while (! file_exists(getenv('FINANCE_RACE_GO'))) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
    clearstatcache();
}
try {
    OPERATION
    fwrite(STDOUT, 'RESULT '.json_encode(['status' => 'ok', 'id' => $resultId ?? 0])."\n");
} catch (Exception $exception) {
    if ($exception instanceof Illuminate\Database\QueryException) { throw $exception; }
    fwrite(STDOUT, 'RESULT '.json_encode(['status' => 'denied', 'message' => $exception->getMessage()])."\n");
}
PHP;
            $code = str_replace('OPERATION', $operation, $code);
            $scriptPath = tempnam(sys_get_temp_dir(), 'banksampah-worker-');
            $readyPath = $scriptPath.'.ready';
            $temporaryFiles[] = $scriptPath;
            $temporaryFiles[] = $readyPath;
            file_put_contents($scriptPath, "<?php\n".$code);
            $process = new Process([PHP_BINARY, $scriptPath], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                'FINANCE_RACE_READY' => $readyPath, 'FINANCE_RACE_GO' => $goPath,
            ]);
            $process->setTimeout(40);
            $process->start();
            $processes[] = $process;
            $deadline = microtime(true) + 20;
            while (! file_exists($readyPath) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect(file_exists($readyPath))->toBeTrue($process->getErrorOutput());
        }
        touch($goPath);
        /** Barier database memastikan kedua proses benar-benar menunggu penguncian, bukan berjalan berurutan. */
        $deadline = microtime(true) + 15;
        do {
            $waiting = DB::connection('finance_admin')->selectOne(
                "SELECT COUNT(*) AS total FROM information_schema.PROCESSLIST WHERE DB = ? AND COMMAND <> 'Sleep' AND LOWER(INFO) LIKE '%for update%'",
                [$database],
            )->total;
            if ((int) $waiting >= 2) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        if ((int) $waiting < 2) {
            throw new RuntimeException(json_encode(DB::connection('finance_admin')->select('SELECT COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE DB = ?', [$database]), JSON_THROW_ON_ERROR));
        }
        expect((int) $waiting)->toBeGreaterThanOrEqual(2, implode("\n", array_map(fn (Process $process): string => $process->getOutput().$process->getErrorOutput(), $processes)));
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            preg_match('/RESULT (.+)/', $process->getOutput(), $matches);
            $results[] = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

test('MySQL melindungi pembayaran yang diproses bersamaan', function (string $scenario): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan pada environment testing.');
    }
    $database = 'banksampah_finance_test_'.bin2hex(random_bytes(6));
    $previous = config('database.default');
    $connection = [...config('database.connections.mysql'), 'url' => null];
    config(['database.connections.finance_admin' => [...$connection, 'database' => null]]);
    DB::connection('finance_admin')->statement('CREATE DATABASE `'.$database.'`');
    try {
        config(['database.connections.payment_race' => [...$connection, 'database' => $database], 'database.default' => 'payment_race']);
        if (DB::connection()->selectOne('SELECT DATABASE() AS name')->name !== $database) {
            throw new RuntimeException('Koneksi database pengujian tidak sesuai.');
        }
        Artisan::call('migrate', ['--database' => 'payment_race', '--force' => true, '--no-interaction' => true]);
        $user = User::factory()->create();
        $collector = Collector::create(['code' => 'RACE', 'name' => 'Pengepul Uji', 'is_active' => true]);
        $sale = Sale::create(['sale_number' => 'RACE', 'collector_id' => $collector->id,
            'transaction_date' => now()->toDateString(), 'status' => 'posted', 'total_amount' => '100.00']);
        $operation = fn (string $amount, string $key): string => '$payment = app(App\\Services\\SalePaymentService::class)->recordPayment(App\\Models\\Sale::findOrFail('.$sale->id.'), '.var_export($amount, true).', '.var_export(now()->toDateString(), true).', "cash", null, null, '.$user->id.', '.var_export($key, true).'); $resultId = $payment->id;';
        $key = (string) Str::uuid();
        $operations = [$operation('80.00', $key)];
        if ($scenario === 'cancel_and_pay') {
            $oldPayment = app(SalePaymentService::class)->recordPayment($sale, '80.00', now()->toDateString(), 'cash', null, null, $user->id, (string) Str::uuid());
            $operations[] = 'app(App\\Services\\SalePaymentService::class)->cancelPayment(App\\Models\\SalePayment::findOrFail('.$oldPayment->id.'), "Koreksi pengujian", '.$user->id.', true);';
        } else {
            $operations[] = $operation($scenario === 'conflicting_payload' ? '60.00' : '80.00', $scenario === 'different_requests' ? (string) Str::uuid() : $key);
        }

        $results = raceSalePaymentProcesses($database, 'sales', $sale->id, $operations);

        if ($scenario === 'same_request') {
            expect(collect($results)->where('status', 'ok')->count())->toBe(2);
            expect($results[0]['id'])->toBe($results[1]['id']);
        } elseif ($scenario === 'cancel_and_pay') {
            expect($oldPayment->fresh()->status)->toBe('cancelled');
        } else {
            expect(collect($results)->where('status', 'ok')->count())->toBe(1);
            expect(collect($results)->where('status', 'denied')->count())->toBe(1);
        }
        $paid = BigDecimal::of(SalePayment::where('status', 'posted')->sum('amount'));
        expect($paid->isLessThanOrEqualTo('100.00'))->toBeTrue();
        if ($scenario !== 'cancel_and_pay') {
            expect(SalePayment::count())->toBe(1);
            expect($sale->fresh()->payment_status)->toBe('partial');
        } else {
            expect((string) $paid->toScale(2))->toBeIn(['0.00', '80.00']);
            expect($sale->fresh()->payment_status)->toBe($paid->isZero() ? 'unpaid' : 'partial');
        }
        /** Migration boleh dijalankan pada schema yang sudah memiliki kunci tanpa mengubah pembayaran. */
        $before = SalePayment::orderBy('id')->get()->toArray();
        $migration = require glob(database_path('migrations/*ensure_sale_payment_idempotency_key.php'))[0];
        $migration->up();
        expect(SalePayment::orderBy('id')->get()->toArray())->toBe($before);
        if ($scenario === 'same_request') {
            /** Simulasi schema lama dengan pembayaran yang sudah ada, hanya pada database uji sementara. */
            $legacyBefore = SalePayment::orderBy('id')->get()->map(fn (SalePayment $row): array => collect($row->getAttributes())->except('idempotency_key')->all())->all();
            Schema::table('sale_payments', function (Blueprint $table): void {
                $table->dropUnique('sale_payments_idempotency_key_unique');
                $table->dropColumn('idempotency_key');
            });
            $migration->up();
            $legacyAfter = SalePayment::orderBy('id')->get()->map(fn (SalePayment $row): array => collect($row->getAttributes())->except('idempotency_key')->all())->all();
            expect($legacyAfter)->toBe($legacyBefore);
            expect(SalePayment::whereNotNull('idempotency_key')->count())->toBe(0);
            expect(Schema::hasIndex('sale_payments', ['idempotency_key'], 'unique'))->toBeTrue();
        }

    } finally {
        DB::purge('payment_race');
        config(['database.default' => $previous]);
        if (! preg_match('/^banksampah_finance_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Pembersihan menolak database di luar lingkup uji.');
        }
        DB::connection('finance_admin')->statement('DROP DATABASE `'.$database.'`');
        DB::purge('finance_admin');
    }
})->with(['different_requests', 'same_request', 'conflicting_payload', 'cancel_and_pay']);
