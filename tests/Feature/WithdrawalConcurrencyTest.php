<?php

use App\Models\BalanceMutation;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\User;
use App\Models\WasteType;
use App\Models\Withdrawal;
use App\Services\DepositService;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @return array<int, array{status: string, id?: int, message?: string}> */
function raceWithdrawalProcesses(string $database, string $table, int $id, array $operations): array
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

test('MySQL melindungi saldo saat penarikan dan pembatalan bersamaan', function (string $scenario): void {
    if (! app()->environment('testing')) {
        throw new RuntimeException('Pengujian hanya boleh berjalan pada environment testing.');
    }
    $database = 'banksampah_finance_test_'.bin2hex(random_bytes(6));
    $previous = config('database.default');
    $connection = [...config('database.connections.mysql'), 'url' => null];
    config(['database.connections.finance_admin' => [...$connection, 'database' => null]]);
    DB::connection('finance_admin')->statement('CREATE DATABASE `'.$database.'`');
    try {
        config(['database.connections.withdrawal_race' => [...$connection, 'database' => $database], 'database.default' => 'withdrawal_race']);
        if (DB::connection()->selectOne('SELECT DATABASE() AS name')->name !== $database) {
            throw new RuntimeException('Koneksi database pengujian tidak sesuai.');
        }
        Artisan::call('migrate', ['--database' => 'withdrawal_race', '--force' => true, '--no-interaction' => true]);
        $user = User::factory()->create();
        $customer = Customer::create(['customer_code' => 'RACE', 'name' => 'Nasabah Uji Persaingan']);
        $waste = WasteType::create(['code' => 'RACE', 'name' => 'Bahan Uji Persaingan']);
        $deposit = Deposit::create(['deposit_number' => 'RACE', 'customer_id' => $customer->id,
            'transaction_date' => now()->toDateString(), 'status' => 'draft', 'total_weight' => '10.000', 'total_amount' => '100.00']);
        $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
        app(DepositService::class)->post($deposit);
        $withdrawal = Withdrawal::create(['withdrawal_number' => 'W1', 'customer_id' => $customer->id,
            'transaction_date' => now()->toDateString(), 'status' => 'draft', 'amount' => '80.00']);
        $withdrawCode = 'app(App\\Services\\WithdrawalService::class)->post(App\\Models\\Withdrawal::findOrFail(%d));';
        if ($scenario === 'deposit_cancellation') {
            $operations = [sprintf($withdrawCode, $withdrawal->id), 'app(App\\Services\\DepositService::class)->cancel(App\\Models\\Deposit::findOrFail('.$deposit->id.'), "Koreksi pengujian", '.$user->id.', true);'];
        } elseif ($scenario === 'withdrawal_cancellation') {
            app(WithdrawalService::class)->post($withdrawal);
            $cancelCode = 'app(App\\Services\\WithdrawalService::class)->cancel(App\\Models\\Withdrawal::findOrFail('.$withdrawal->id.'), "Koreksi pengujian", '.$user->id.', true);';
            $operations = [$cancelCode, $cancelCode];
        } else {
            $second = $withdrawal;
            if ($scenario === 'different_withdrawals') {
                $second = $withdrawal->replicate();
                $second->withdrawal_number = 'W2';
                $second->save();
            }
            $operations = [sprintf($withdrawCode, $withdrawal->id), sprintf($withdrawCode, $second->id)];
        }
        $results = raceWithdrawalProcesses($database, 'customers', $customer->id, $operations);
        expect(collect($results)->where('status', 'ok')->count())->toBe(1);
        expect(collect($results)->where('status', 'denied')->count())->toBe(1);
        $balance = DB::table('balance_mutations')->selectRaw("SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) AS balance")->first()->balance;
        if ($scenario === 'withdrawal_cancellation') {
            expect($balance)->toBe('100.00');
            expect(BalanceMutation::where('reference_type', 'withdrawal_cancellation')->count())->toBe(1);
            expect($withdrawal->fresh()->status)->toBe('cancelled');
        } elseif ($scenario === 'deposit_cancellation') {
            expect($balance)->toBe($deposit->fresh()->status === 'cancelled' ? '0.00' : '20.00');
            expect(BalanceMutation::where('type', 'debit')->count())->toBe(1);
        } else {
            expect($balance)->toBe('20.00');
            expect(BalanceMutation::where('reference_type', 'withdrawal')->count())->toBe(1);
        }
    } finally {
        DB::purge('withdrawal_race');
        config(['database.default' => $previous]);
        if (! preg_match('/^banksampah_finance_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Pembersihan menolak database di luar lingkup uji.');
        }
        DB::connection('finance_admin')->statement('DROP DATABASE `'.$database.'`');
        DB::purge('finance_admin');
    }
})->with(['different_withdrawals', 'same_withdrawal', 'deposit_cancellation', 'withdrawal_cancellation']);
