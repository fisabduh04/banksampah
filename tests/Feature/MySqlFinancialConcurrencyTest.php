<?php

use App\Models\BalanceMutation;
use App\Models\Collector;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Sale;
use App\Models\User;
use App\Models\WasteType;
use App\Models\Withdrawal;
use App\Services\CustomerBalanceService;
use App\Services\DepositService;
use App\Services\InventoryService;
use App\Services\SalePostingService;
use App\Services\WithdrawalService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/** @return array<int, array{status: string, id?: int, message?: string}> */
function raceFinancialProcesses(string $database, string $table, int $id, array $operations): array
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
if (! preg_match('/^banksampah_finance_test_[a-f0-9]{12}$/', config('database.connections.mysql.database'))) {
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
} catch (UnexpectedValueException $exception) {
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

test('MySQL menjaga saldo stok dan pembayaran ketika dua proses bersaing', function (string $scenario): void {
    if (getenv('RUN_MYSQL_FINANCIAL_TESTS') !== '1') {
        $this->markTestSkipped('Aktifkan RUN_MYSQL_FINANCIAL_TESTS=1 untuk pengujian MySQL pada database terisolasi.');
    }
    $database = 'banksampah_finance_test_'.bin2hex(random_bytes(6));
    $previousConnection = config('database.default');
    $connection = config('database.connections.mysql');
    $connection['url'] = null;
    config(['database.connections.finance_admin' => [...$connection, 'database' => null]]);
    DB::connection('finance_admin')->statement('CREATE DATABASE `'.$database.'`');
    try {
        config(['database.connections.finance_test' => [...$connection, 'database' => $database], 'database.default' => 'finance_test']);
        Artisan::call('migrate', ['--database' => 'finance_test', '--force' => true, '--no-interaction' => true]);
        $user = User::factory()->financeManager()->create();
        $customer = Customer::create(['customer_code' => 'NS-RACE', 'name' => 'Nasabah Uji Persaingan']);
        $waste = WasteType::create(['code' => 'PET-RACE', 'name' => 'PET Uji Persaingan', 'is_active' => true]);
        $date = now()->toDateString();
        $deposit = Deposit::create(['deposit_number' => 'ST-RACE', 'customer_id' => $customer->id,
            'transaction_date' => $date, 'status' => 'draft', 'total_weight' => '10.000', 'total_amount' => '100.00']);
        $deposit->items()->create(['waste_type_id' => $waste->id, 'weight' => '10.000', 'price' => '10.00', 'subtotal' => '100.00']);
        app(DepositService::class)->post($deposit, $user->id);
        $collector = Collector::create(['code' => 'PG-RACE', 'name' => 'Pengepul Uji Persaingan', 'is_active' => true]);
        $sale = Sale::create(['sale_number' => 'PJ-RACE', 'collector_id' => $collector->id,
            'transaction_date' => $date, 'status' => 'draft', 'total_weight' => '6.000', 'total_amount' => '120.00']);
        $sale->items()->create(['waste_type_id' => $waste->id, 'weight' => '6.000', 'price' => '20.00', 'subtotal' => '120.00']);
        $withdrawal = Withdrawal::create(['withdrawal_number' => 'WD-RACE-1', 'customer_id' => $customer->id,
            'transaction_date' => $date, 'status' => 'draft', 'amount' => '60.00']);
        $table = 'customers';
        $lockId = $customer->id;
        $withdrawCode = 'app(App\\Services\\WithdrawalService::class)->post(App\\Models\\Withdrawal::findOrFail(%d), '.$user->id.');';
        if ($scenario === 'withdrawal' || $scenario === 'same_withdrawal') {
            $second = $scenario === 'same_withdrawal' ? $withdrawal : $withdrawal->replicate();
            if ($scenario !== 'same_withdrawal') {
                $second->withdrawal_number = 'WD-RACE-2';
                $second->save();
            }
            $operations = [sprintf($withdrawCode, $withdrawal->id), sprintf($withdrawCode, $second->id)];
        } elseif ($scenario === 'deposit_cancellation') {
            $operations = [sprintf($withdrawCode, $withdrawal->id), 'app(App\\Services\\DepositService::class)->cancel(App\\Models\\Deposit::findOrFail('.$deposit->id.'), "Koreksi uji", '.$user->id.');'];
        } elseif ($scenario === 'stock') {
            $second = $sale->replicate();
            $second->sale_number = 'PJ-RACE-2';
            $second->save();
            $second->items()->create(['waste_type_id' => $waste->id, 'weight' => '6.000', 'price' => '20.00', 'subtotal' => '120.00']);
            $table = 'waste_types';
            $lockId = $waste->id;
            $operations = array_map(fn (int $saleId): string => 'app(App\\Services\\SalePostingService::class)->post(App\\Models\\Sale::findOrFail('.$saleId.'), '.$user->id.');', [$sale->id, $second->id]);
        } else {
            app(SalePostingService::class)->post($sale, $user->id);
            $table = 'sales';
            $lockId = $sale->id;
            $firstKey = (string) Str::uuid();
            $secondKey = $scenario === 'same_payment' ? $firstKey : (string) Str::uuid();
            $operations = array_map(fn (string $key): string => '$resultId = app(App\\Services\\SalePaymentService::class)->recordPayment(App\\Models\\Sale::findOrFail('.$sale->id.'), "80.01", "'.$date.'", "cash", null, null, '.$user->id.', "'.$key.'")->id;', [$firstKey, $secondKey]);
        }
        $results = raceFinancialProcesses($database, $table, $lockId, $operations);
        expect(collect($results)->where('status', 'ok')->count())->toBe($scenario === 'same_payment' ? 2 : 1);
        if (in_array($scenario, ['withdrawal', 'same_withdrawal'], true)) {
            expect(DB::transaction(fn () => app(CustomerBalanceService::class)->getLockedBalance($customer->id)))->toBe('40.00');
            expect(BalanceMutation::query()->where('reference_type', 'withdrawal')->count())->toBe(1);
        } elseif ($scenario === 'deposit_cancellation') {
            $cancelled = $deposit->fresh()->status === 'cancelled';
            expect(DB::transaction(fn () => app(CustomerBalanceService::class)->getLockedBalance($customer->id)))->toBe($cancelled ? '0.00' : '40.00');
            expect(app(InventoryService::class)->getExactBalance($waste->id)['quantity'])->toBe($cancelled ? '0.000' : '10.000');
        } elseif ($scenario === 'stock') {
            expect(app(InventoryService::class)->getExactBalance($waste->id))->toMatchArray(['quantity' => '4.000', 'value' => '40.00']);
        } else {
            expect($sale->payments()->count())->toBe(1);
            expect($sale->fresh()->payment_status)->toBe('partial');
            expect($sale->payments()->sole()->amount)->toBe('80.01');
            if ($scenario === 'same_payment') {
                expect($results[0]['id'])->toBe($results[1]['id']);
            }
        }
        /** DECIMAL MySQL diuji pada batas besar; SQLite menyimpan NUMERIC besar sebagai floating point. */
        $largeCustomer = Customer::create(['customer_code' => 'NS-LARGE', 'name' => 'Nasabah Uji Desimal']);
        BalanceMutation::create(['customer_id' => $largeCustomer->id, 'type' => 'credit', 'amount' => '9999999999999.99', 'transaction_date' => $date]);
        $largeWithdrawal = Withdrawal::create(['withdrawal_number' => 'WD-LARGE', 'customer_id' => $largeCustomer->id,
            'transaction_date' => $date, 'status' => 'draft', 'amount' => '9999999999999.98']);
        app(WithdrawalService::class)->post($largeWithdrawal, $user->id);
        expect(DB::transaction(fn () => app(CustomerBalanceService::class)->getLockedBalance($largeCustomer->id)))->toBe('0.01');
    } finally {
        DB::purge('finance_test');
        config(['database.default' => $previousConnection]);
        if (! preg_match('/^banksampah_finance_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Pembersihan menolak database di luar lingkup pengujian.');
        }
        DB::connection('finance_admin')->statement('DROP DATABASE `'.$database.'`');
        DB::purge('finance_admin');
    }
})->with(['withdrawal', 'same_withdrawal', 'deposit_cancellation', 'stock', 'payment', 'same_payment']);
