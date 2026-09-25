<?php

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Menjalankan beberapa proses PHP secara paralel.
 *
 * Parent process lebih dahulu mengunci satu record database.
 * Worker kemudian dilepas bersamaan dan dipastikan benar-benar
 * menunggu lock database sebelum parent melepaskan lock.
 *
 * @param  array<int, string>  $operations
 * @return array<int, array{
 *     status:string,
 *     id?:int,
 *     message?:string
 * }>
 */
function raceJournalProcesses(
    string $database,
    string $table,
    int $id,
    array $operations
): array {
    $processes = [];
    $temporaryFiles = [];

    /**
     * File barrier.
     *
     * Worker menunggu sampai file ini dibuat.
     */
    $goPath = tempnam(
        sys_get_temp_dir(),
        'banksampah-journal-go-'
    );

    unlink($goPath);

    $temporaryFiles[] = $goPath;

    /**
     * Kunci record yang nantinya diperlukan
     * oleh seluruh worker.
     */
    DB::beginTransaction();

    $locked = DB::table($table)
        ->where('id', $id)
        ->lockForUpdate()
        ->first();

    if (! $locked) {
        DB::rollBack();

        throw new RuntimeException(
            'Record barrier pengujian concurrency tidak ditemukan.'
        );
    }

    try {
        foreach ($operations as $operation) {
            $code = <<<'PHP'
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';

$app->make(
    Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

if (
    config('database.default') !== 'mysql'
    || ! preg_match(
        '/^banksampah_journal_test_[a-f0-9]{12}$/',
        Illuminate\Support\Facades\DB::connection()
            ->getDatabaseName()
    )
) {
    throw new RuntimeException(
        'Proses pengujian menolak database di luar lingkup uji.'
    );
}

if (Illuminate\Support\Facades\DB::connection()->selectOne('SELECT DATABASE() AS db, @@hostname AS server')->db !== getenv('DB_DATABASE') || Illuminate\Support\Facades\DB::connection()->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
    throw new RuntimeException('Worker menolak identitas database/server yang berbeda.');
}

/**
 * Tandai worker sudah siap.
 */
touch(getenv('JOURNAL_RACE_READY'));

$deadline = microtime(true) + 30;

/**
 * Tunggu sinyal GO dari parent.
 */
while (! file_exists(getenv('JOURNAL_RACE_GO'))) {
    if (microtime(true) > $deadline) {
        exit(2);
    }

    usleep(10000);
    clearstatcache();
}

try {
    OPERATION

    fwrite(
        STDOUT,
        'RESULT '.json_encode([
            'status' => 'ok',
            'id' => $resultId ?? 0,
        ])."\n"
    );
} catch (Throwable $exception) {
    /**
     * QueryException yang tidak berhasil ditangani service
     * merupakan kegagalan test.
     */
    if (
        $exception
        instanceof Illuminate\Database\QueryException
    ) {
        throw $exception;
    }

    fwrite(
        STDOUT,
        'RESULT '.json_encode([
            'status' => 'denied',
            'message' => $exception->getMessage(),
        ])."\n"
    );
}
PHP;

            $code = str_replace(
                'OPERATION',
                $operation,
                $code
            );

            $scriptPath = tempnam(
                sys_get_temp_dir(),
                'banksampah-journal-worker-'
            );

            $readyPath = $scriptPath.'.ready';

            $temporaryFiles[] = $scriptPath;
            $temporaryFiles[] = $readyPath;

            file_put_contents(
                $scriptPath,
                "<?php\n".$code
            );

            $process = new Process(
                [
                    PHP_BINARY,
                    $scriptPath,
                ],
                base_path(),
                [
                    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_SOCKET' => '',
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'mysql',
                    'DB_DATABASE' => $database,
                    'DB_URL' => '',

                    'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync',

                    'JOURNAL_RACE_READY' => $readyPath,
                    'JOURNAL_RACE_GO' => $goPath,
                ]
            );

            $process->setTimeout(40);
            $process->start();

            $processes[] = $process;

            /**
             * Pastikan worker sudah benar-benar boot.
             */
            $deadline = microtime(true) + 20;

            while (
                ! file_exists($readyPath)
                && $process->isRunning()
                && microtime(true) < $deadline
            ) {
                usleep(10000);
            }

            expect(
                file_exists($readyPath)
            )->toBeTrue(
                $process->getErrorOutput()
            );
        }

        /**
         * Lepaskan kedua worker bersamaan.
         */
        touch($goPath);

        /**
         * Tunggu sampai dua worker benar-benar
         * tertahan pada SELECT ... FOR UPDATE.
         */
        $deadline = microtime(true) + 15;
        $waiting = 0;

        do {
            $waiting = DB::connection('journal_admin')
                ->selectOne(
                    "SELECT COUNT(*) AS total
                     FROM information_schema.PROCESSLIST
                     WHERE DB = ?
                       AND COMMAND <> 'Sleep'
                       AND LOWER(INFO) LIKE '%for update%'",
                    [$database]
                )
                ->total;

            if ((int) $waiting >= 2) {
                break;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        if ((int) $waiting < 2) {
            throw new RuntimeException(
                json_encode(
                    DB::connection('journal_admin')
                        ->select(
                            'SELECT COMMAND, STATE, INFO
                             FROM information_schema.PROCESSLIST
                             WHERE DB = ?',
                            [$database]
                        ),
                    JSON_THROW_ON_ERROR
                )
            );
        }

        expect(
            (int) $waiting
        )->toBeGreaterThanOrEqual(
            2,
            implode(
                "\n",
                array_map(
                    fn (Process $process): string => $process->getOutput()
                        .$process->getErrorOutput(),
                    $processes
                )
            )
        );

        /**
         * Sekarang lepaskan database lock parent.
         *
         * Kedua worker akan berlomba sungguhan.
         */
        DB::commit();

        $results = [];

        foreach ($processes as $process) {
            $process->wait();

            expect(
                $process->isSuccessful()
            )->toBeTrue(
                $process->getErrorOutput()
            );

            preg_match(
                '/RESULT (.+)/',
                $process->getOutput(),
                $matches
            );

            expect(
                isset($matches[1])
            )->toBeTrue(
                $process->getOutput()
                .$process->getErrorOutput()
            );

            $results[] = json_decode(
                $matches[1],
                true,
                flags: JSON_THROW_ON_ERROR
            );
        }

        return $results;
    } finally {
        /**
         * Jangan pernah meninggalkan transaction parent terbuka.
         */
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

/**
 * Membentuk perintah posting jurnal
 * yang akan dieksekusi worker PHP.
 */
function journalPostOperation(
    string $transactionDate,
    string $referenceType,
    int $referenceId,
    string $referenceNumber,
    string $description,
    int $userId,
    int $debitAccountId,
    int $creditAccountId,
    string $amount
): string {
    return sprintf(
        <<<'PHP'
$entry = app(App\Services\JournalService::class)->post(
    transactionDate: %s,
    referenceType: %s,
    referenceId: %d,
    referenceNumber: %s,
    description: %s,
    userId: %d,
    lines: [
        [
            'account_id' => %d,
            'debit' => %s,
            'credit' => '0.00',
            'description' => 'Kas',
        ],
        [
            'account_id' => %d,
            'debit' => '0.00',
            'credit' => %s,
            'description' => 'Saldo awal',
        ],
    ]
);

$resultId = $entry->id;
PHP,
        var_export($transactionDate, true),
        var_export($referenceType, true),
        $referenceId,
        var_export($referenceNumber, true),
        var_export($description, true),
        $userId,
        $debitAccountId,
        var_export($amount, true),
        $creditAccountId,
        var_export($amount, true)
    );
}

/**
 * Membentuk perintah reversal jurnal
 * untuk worker PHP.
 */
function journalReverseOperation(
    int $journalId,
    string $transactionDate,
    string $referenceType,
    int $referenceId,
    string $referenceNumber,
    string $description,
    int $userId
): string {
    return sprintf(
        <<<'PHP'
$reversal = app(App\Services\JournalService::class)->reverse(
    journalEntry: App\Models\JournalEntry::findOrFail(%d),
    transactionDate: %s,
    referenceType: %s,
    referenceId: %d,
    referenceNumber: %s,
    description: %s,
    userId: %d
);

$resultId = $reversal->id;
PHP,
        $journalId,
        var_export($transactionDate, true),
        var_export($referenceType, true),
        $referenceId,
        var_export($referenceNumber, true),
        var_export($description, true),
        $userId
    );
}

/**
 * ============================================================
 * TEST 1
 *
 * Dua proses memposting jurnal yang sama pada saat bersamaan.
 *
 * Keduanya harus berhasil secara idempotent, tetapi database
 * hanya boleh memiliki satu journal_entries.
 * ============================================================
 */
test(
    'dua posting jurnal identik secara bersamaan hanya membuat satu jurnal',
    function (): void {
        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'Pengujian hanya boleh berjalan pada environment testing.'
            );
        }

        $database =
            'banksampah_journal_test_'
            .bin2hex(random_bytes(6));

        $previous = config('database.default');

        $connection = [
            ...config('database.connections.mysql'),
            'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '',
        ];

        config([
            'database.connections.journal_admin' => [
                ...$connection,
                'database' => null,
            ],
        ]);

        if (! preg_match('/^banksampah_(?:journal|finance)_test_[a-f0-9]{12}$/', $database) || DB::connection('journal_admin')->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
            throw new RuntimeException('Server/nama database disposable tidak sesuai.');
        }
        DB::connection('journal_admin')->statement(
            'CREATE DATABASE `'.$database.'`'
        );
        $databaseCreatedByThisTest = true;

        try {
            config([
                'database.connections.journal_race' => [
                    ...$connection,
                    'database' => $database,
                ],
                'database.default' => 'journal_race',
            ]);

            if (
                DB::connection()
                    ->selectOne(
                        'SELECT DATABASE() AS name'
                    )->name !== $database
            ) {
                throw new RuntimeException(
                    'Koneksi database pengujian tidak sesuai.'
                );
            }

            if (! $databaseCreatedByThisTest || DB::connection()->selectOne('SELECT DATABASE() AS db, @@hostname AS server')->db !== $database || DB::connection()->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
                throw new RuntimeException('Migrasi menolak target di luar database disposable milik proses ini.');
            }
            Artisan::call('migrate', [
                '--database' => 'journal_race',
                '--force' => true,
                '--no-interaction' => true,
            ]);

            app(AccountSeeder::class)->run();

            $user = User::factory()->create();

            $cash = Account::query()
                ->where('system_key', 'cash')
                ->sole();

            $openingBalance = Account::query()
                ->where('system_key', 'opening_balance')
                ->sole();

            $date = now()->toDateString();

            $operation = journalPostOperation(
                transactionDate: $date,
                referenceType: 'journal_concurrency_same',
                referenceId: 1001,
                referenceNumber: 'JCON-SAME-001',
                description: 'Uji concurrency jurnal identik',
                userId: $user->id,
                debitAccountId: $cash->id,
                creditAccountId: $openingBalance->id,
                amount: '100000.00'
            );

            /**
             * Parent mengunci akun Kas.
             *
             * Kedua JournalService nantinya harus melewati
             * lock akun yang sama.
             */
            $results = raceJournalProcesses(
                database: $database,
                table: 'accounts',
                id: $cash->id,
                operations: [
                    $operation,
                    $operation,
                ]
            );

            /**
             * Keduanya merupakan request identik.
             *
             * Maka keduanya harus dianggap berhasil.
             */
            expect(
                collect($results)
                    ->where('status', 'ok')
                    ->count()
            )->toBe(2);

            expect(
                collect($results)
                    ->where('status', 'denied')
                    ->count()
            )->toBe(0);

            /**
             * Kedua proses harus mendapatkan ID jurnal
             * yang sama.
             */
            $resultIds = collect($results)
                ->pluck('id')
                ->unique()
                ->values();

            expect($resultIds)->toHaveCount(1);

            /**
             * Database hanya memiliki satu jurnal.
             */
            $journal = JournalEntry::query()
                ->where(
                    'reference_type',
                    'journal_concurrency_same'
                )
                ->where('reference_id', 1001)
                ->sole();

            expect(
                $journal->id
            )->toBe($resultIds->first());

            expect(
                JournalEntry::query()
                    ->where(
                        'reference_type',
                        'journal_concurrency_same'
                    )
                    ->where('reference_id', 1001)
                    ->count()
            )->toBe(1);

            /**
             * Jurnal tunggal hanya mempunyai dua baris.
             */
            expect(
                JournalLine::query()
                    ->where(
                        'journal_entry_id',
                        $journal->id
                    )
                    ->count()
            )->toBe(2);
        } finally {
            DB::purge('journal_race');

            config([
                'database.default' => $previous,
            ]);

            if (
                ! preg_match(
                    '/^banksampah_journal_test_[a-f0-9]{12}$/',
                    $database
                )
            ) {
                throw new RuntimeException(
                    'Pembersihan menolak database di luar lingkup uji.'
                );
            }

            if (! $databaseCreatedByThisTest || DB::connection('journal_admin')->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
                throw new RuntimeException('Database bukan milik proses pengujian ini.');
            }
            DB::connection('journal_admin')->statement(
                'DROP DATABASE `'.$database.'`'
            );

            DB::purge('journal_admin');
        }
    }
);

/**
 * ============================================================
 * TEST 2
 *
 * Referensi sama tetapi payload berbeda dikirim bersamaan.
 *
 * Hanya satu yang boleh menang.
 * Request lainnya harus ditolak sebagai conflict.
 * ============================================================
 */
test(
    'dua posting bersamaan dengan referensi sama tetapi payload berbeda menolak salah satunya',
    function (): void {
        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'Pengujian hanya boleh berjalan pada environment testing.'
            );
        }

        $database =
            'banksampah_journal_test_'
            .bin2hex(random_bytes(6));

        $previous = config('database.default');

        $connection = [
            ...config('database.connections.mysql'),
            'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '',
        ];

        config([
            'database.connections.journal_admin' => [
                ...$connection,
                'database' => null,
            ],
        ]);

        if (! preg_match('/^banksampah_(?:journal|finance)_test_[a-f0-9]{12}$/', $database) || DB::connection('journal_admin')->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
            throw new RuntimeException('Server/nama database disposable tidak sesuai.');
        }
        DB::connection('journal_admin')->statement(
            'CREATE DATABASE `'.$database.'`'
        );
        $databaseCreatedByThisTest = true;

        try {
            config([
                'database.connections.journal_race' => [
                    ...$connection,
                    'database' => $database,
                ],
                'database.default' => 'journal_race',
            ]);

            if (! $databaseCreatedByThisTest || DB::connection()->selectOne('SELECT DATABASE() AS db, @@hostname AS server')->db !== $database || DB::connection()->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
                throw new RuntimeException('Migrasi menolak target di luar database disposable milik proses ini.');
            }
            Artisan::call('migrate', [
                '--database' => 'journal_race',
                '--force' => true,
                '--no-interaction' => true,
            ]);

            app(AccountSeeder::class)->run();

            $user = User::factory()->create();

            $cash = Account::query()
                ->where('system_key', 'cash')
                ->sole();

            $openingBalance = Account::query()
                ->where('system_key', 'opening_balance')
                ->sole();

            $date = now()->toDateString();

            $operation100 = journalPostOperation(
                transactionDate: $date,
                referenceType: 'journal_concurrency_diff',
                referenceId: 2001,
                referenceNumber: 'JCON-DIFF-001',
                description: 'Uji concurrency payload berbeda',
                userId: $user->id,
                debitAccountId: $cash->id,
                creditAccountId: $openingBalance->id,
                amount: '100000.00'
            );

            $operation150 = journalPostOperation(
                transactionDate: $date,
                referenceType: 'journal_concurrency_diff',
                referenceId: 2001,
                referenceNumber: 'JCON-DIFF-001',
                description: 'Uji concurrency payload berbeda',
                userId: $user->id,
                debitAccountId: $cash->id,
                creditAccountId: $openingBalance->id,
                amount: '150000.00'
            );

            $results = raceJournalProcesses(
                database: $database,
                table: 'accounts',
                id: $cash->id,
                operations: [
                    $operation100,
                    $operation150,
                ]
            );

            /**
             * Salah satu menang.
             * Satu lainnya harus ditolak.
             */
            expect(
                collect($results)
                    ->where('status', 'ok')
                    ->count()
            )->toBe(1);

            expect(
                collect($results)
                    ->where('status', 'denied')
                    ->count()
            )->toBe(1);

            /**
             * Tetap hanya ada satu jurnal.
             */
            $journal = JournalEntry::query()
                ->where(
                    'reference_type',
                    'journal_concurrency_diff'
                )
                ->where('reference_id', 2001)
                ->sole();

            expect(
                JournalEntry::query()
                    ->where(
                        'reference_type',
                        'journal_concurrency_diff'
                    )
                    ->where('reference_id', 2001)
                    ->count()
            )->toBe(1);

            expect(
                JournalLine::query()
                    ->where(
                        'journal_entry_id',
                        $journal->id
                    )
                    ->count()
            )->toBe(2);

            /**
             * Jurnal pemenang boleh bernilai Rp100.000
             * atau Rp150.000 tergantung proses mana yang
             * memperoleh lock lebih dahulu.
             */
            $debit = JournalLine::query()
                ->where(
                    'journal_entry_id',
                    $journal->id
                )
                ->sum('debit');

            expect(
                (string) $debit
            )->toBeIn([
                '100000.00',
                '150000.00',
            ]);
        } finally {
            DB::purge('journal_race');

            config([
                'database.default' => $previous,
            ]);

            if (
                ! preg_match(
                    '/^banksampah_journal_test_[a-f0-9]{12}$/',
                    $database
                )
            ) {
                throw new RuntimeException(
                    'Pembersihan menolak database di luar lingkup uji.'
                );
            }

            if (! $databaseCreatedByThisTest || DB::connection('journal_admin')->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
                throw new RuntimeException('Database bukan milik proses pengujian ini.');
            }
            DB::connection('journal_admin')->statement(
                'DROP DATABASE `'.$database.'`'
            );

            DB::purge('journal_admin');
        }
    }
);

/**
 * ============================================================
 * TEST 3
 *
 * Dua proses mereversal jurnal yang sama pada saat bersamaan.
 *
 * Keduanya boleh selesai secara idempotent, tetapi hanya
 * boleh ada satu jurnal reversal.
 * ============================================================
 */
test(
    'dua reversal jurnal identik secara bersamaan hanya membuat satu jurnal pembalik',
    function (): void {
        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'Pengujian hanya boleh berjalan pada environment testing.'
            );
        }

        $database =
            'banksampah_journal_test_'
            .bin2hex(random_bytes(6));

        $previous = config('database.default');

        $connection = [
            ...config('database.connections.mysql'),
            'url' => null, 'host' => '127.0.0.1', 'port' => 3306, 'unix_socket' => '',
        ];

        config([
            'database.connections.journal_admin' => [
                ...$connection,
                'database' => null,
            ],
        ]);

        if (! preg_match('/^banksampah_(?:journal|finance)_test_[a-f0-9]{12}$/', $database) || DB::connection('journal_admin')->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
            throw new RuntimeException('Server/nama database disposable tidak sesuai.');
        }
        DB::connection('journal_admin')->statement(
            'CREATE DATABASE `'.$database.'`'
        );
        $databaseCreatedByThisTest = true;

        try {
            config([
                'database.connections.journal_race' => [
                    ...$connection,
                    'database' => $database,
                ],
                'database.default' => 'journal_race',
            ]);

            if (! $databaseCreatedByThisTest || DB::connection()->selectOne('SELECT DATABASE() AS db, @@hostname AS server')->db !== $database || DB::connection()->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
                throw new RuntimeException('Migrasi menolak target di luar database disposable milik proses ini.');
            }
            Artisan::call('migrate', [
                '--database' => 'journal_race',
                '--force' => true,
                '--no-interaction' => true,
            ]);

            app(AccountSeeder::class)->run();

            $user = User::factory()->create();

            $cash = Account::query()
                ->where('system_key', 'cash')
                ->sole();

            $openingBalance = Account::query()
                ->where('system_key', 'opening_balance')
                ->sole();

            $date = now()->toDateString();

            /**
             * Buat jurnal asal terlebih dahulu.
             */
            $original = app(JournalService::class)->post(
                transactionDate: $date,
                referenceType: 'journal_concurrency_original',
                referenceId: 3001,
                referenceNumber: 'JCON-ORI-001',
                description: 'Jurnal asal concurrency reversal',
                userId: $user->id,
                lines: [
                    [
                        'account_id' => $cash->id,
                        'debit' => '75000.00',
                        'credit' => '0.00',
                        'description' => 'Kas',
                    ],
                    [
                        'account_id' => $openingBalance->id,
                        'debit' => '0.00',
                        'credit' => '75000.00',
                        'description' => 'Saldo awal',
                    ],
                ]
            );

            $operation = journalReverseOperation(
                journalId: $original->id,
                transactionDate: $date,
                referenceType: 'journal_concurrency_reversal',
                referenceId: 3001,
                referenceNumber: 'REV-JCON-001',
                description: 'Reversal concurrency',
                userId: $user->id
            );

            /**
             * Parent mengunci jurnal asal.
             *
             * Kedua worker reverse() akan tertahan
             * pada lock jurnal yang sama.
             */
            $results = raceJournalProcesses(
                database: $database,
                table: 'journal_entries',
                id: $original->id,
                operations: [
                    $operation,
                    $operation,
                ]
            );

            expect(
                collect($results)
                    ->where('status', 'ok')
                    ->count()
            )->toBe(2);

            expect(
                collect($results)
                    ->where('status', 'denied')
                    ->count()
            )->toBe(0);

            /**
             * Kedua proses memperoleh reversal yang sama.
             */
            expect(
                collect($results)
                    ->pluck('id')
                    ->unique()
                    ->count()
            )->toBe(1);

            /**
             * Database hanya mempunyai satu reversal.
             */
            $reversal = JournalEntry::query()
                ->where(
                    'reversal_of_id',
                    $original->id
                )
                ->sole();

            expect(
                JournalEntry::query()
                    ->where(
                        'reversal_of_id',
                        $original->id
                    )
                    ->count()
            )->toBe(1);

            expect(
                JournalLine::query()
                    ->where(
                        'journal_entry_id',
                        $reversal->id
                    )
                    ->count()
            )->toBe(2);

            /**
             * Jurnal asal harus telah berubah
             * dari posted menjadi reversed.
             */
            expect(
                $original->fresh()->status
            )->toBe(
                JournalEntry::STATUS_REVERSED
            );
        } finally {
            DB::purge('journal_race');

            config([
                'database.default' => $previous,
            ]);

            if (
                ! preg_match(
                    '/^banksampah_journal_test_[a-f0-9]{12}$/',
                    $database
                )
            ) {
                throw new RuntimeException(
                    'Pembersihan menolak database di luar lingkup uji.'
                );
            }

            if (! $databaseCreatedByThisTest || DB::connection('journal_admin')->selectOne('SELECT @@hostname AS server')->server !== 'DESKTOP-PDMMRQ1') {
                throw new RuntimeException('Database bukan milik proses pengujian ini.');
            }
            DB::connection('journal_admin')->statement(
                'DROP DATABASE `'.$database.'`'
            );

            DB::purge('journal_admin');
        }
    }
);
