<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\SalePayment;
use App\Models\User;
use App\Models\Withdrawal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class CashMutationService
{
    /**
     * Mencatat mutasi Kas/Bank.
     *
     * Semua perubahan saldo Kas/Bank harus melalui service ini
     * agar validasi dan pengamanan transaksi tetap konsisten.
     */
    public function record(
        int $cashAccountId,
        string $transactionDate,
        string $mutationType,
        string $amount,
        string $referenceType,
        ?int $referenceId,
        ?string $referenceNumber,
        ?string $description,
        ?int $userId
    ): CashMutation {
        if (
            ! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', $amount)
            || BigDecimal::of($amount)->isLessThanOrEqualTo(0)
        ) {
            throw new RuntimeException(
                'Nominal mutasi harus lebih dari nol dan maksimal 2 angka desimal.'
            );
        }

        $amount = (string) BigDecimal::of($amount)->toScale(2);

        if (! in_array(
            $mutationType,
            [
                CashMutation::TYPE_IN,
                CashMutation::TYPE_OUT,
            ],
            true
        )) {
            throw new RuntimeException(
                'Jenis mutasi Kas/Bank tidak valid.'
            );
        }

        if (
            Validator::make(
                ['date' => $transactionDate],
                ['date' => ['required', 'date_format:Y-m-d']]
            )->fails()
            || $transactionDate > now()->toDateString()
        ) {
            throw new RuntimeException(
                'Tanggal mutasi Kas/Bank tidak valid atau melewati hari ini.'
            );
        }

        $referenceType = trim($referenceType);

        if (
            $referenceType === ''
            || mb_strlen($referenceType) > 50
        ) {
            throw new RuntimeException(
                'Jenis referensi mutasi tidak valid.'
            );
        }

        $referenceNumber = trim($referenceNumber ?? '');
        $referenceNumber = $referenceNumber === ''
            ? null
            : $referenceNumber;

        $description = trim($description ?? '');
        $description = $description === ''
            ? null
            : $description;

        if (
            mb_strlen($referenceNumber ?? '') > 100
            || mb_strlen($description ?? '') > 2000
        ) {
            throw new RuntimeException(
                'Nomor referensi atau keterangan mutasi terlalu panjang.'
            );
        }

        if (
            $userId !== null
            && ! User::query()->whereKey($userId)->exists()
        ) {
            throw new RuntimeException(
                'Pengguna pencatat mutasi tidak ditemukan.'
            );
        }

        return DB::transaction(function () use (
            $cashAccountId,
            $transactionDate,
            $mutationType,
            $amount,
            $referenceType,
            $referenceId,
            $referenceNumber,
            $description,
            $userId
        ): CashMutation {
            $cashAccount = CashAccount::query()
                ->whereKey($cashAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $cashAccount->isActive()) {
                throw new RuntimeException(
                    'Akun Kas/Bank sudah tidak aktif.'
                );
            }

            if ($referenceId !== null) {
                $existing = CashMutation::query()
                    ->where('cash_account_id', $cashAccount->id)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if (
                        $existing->mutation_type === $mutationType
                        && BigDecimal::of($existing->amount)
                            ->isEqualTo($amount)
                        && $existing->transaction_date
                            ->toDateString() === $transactionDate
                        && $existing->reference_number === $referenceNumber
                    ) {
                        return $existing;
                    }

                    throw new RuntimeException(
                        'Transaksi sumber ini sudah mempunyai mutasi Kas/Bank dengan data berbeda.'
                    );
                }
            }

            return CashMutation::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_date' => $transactionDate,
                'mutation_type' => $mutationType,
                'amount' => $amount,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_number' => $referenceNumber,
                'description' => $description,
                'created_by' => $userId,
            ]);
        }, attempts: 3);
    }

    /**
     * Membuat mutasi pembalik atas pembayaran penjualan.
     */
    public function reverseSalePayment(
        SalePayment $payment,
        int $userId
    ): CashMutation {
        if ($payment->cash_account_id === null) {
            throw new RuntimeException(
                'Pembayaran tidak memiliki akun Kas/Bank asal.'
            );
        }

        if (! User::query()->whereKey($userId)->exists()) {
            throw new RuntimeException(
                'Pengguna pencatat pembatalan tidak ditemukan.'
            );
        }

        return DB::transaction(function () use (
            $payment,
            $userId
        ): CashMutation {
            $cashAccount = CashAccount::query()
                ->whereKey($payment->cash_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $originalMutation = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('reference_type', 'sale_payment')
                ->where('reference_id', $payment->id)
                ->lockForUpdate()
                ->first();

            if (! $originalMutation) {
                throw new RuntimeException(
                    'Mutasi Kas/Bank pembayaran asal tidak ditemukan.'
                );
            }

            if (
                $originalMutation->mutation_type
                    !== CashMutation::TYPE_IN
                || ! BigDecimal::of($originalMutation->amount)
                    ->isEqualTo($payment->amount)
            ) {
                throw new RuntimeException(
                    'Mutasi Kas/Bank pembayaran asal tidak konsisten.'
                );
            }

            $existingReversal = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where(
                    'reference_type',
                    'sale_payment_cancellation'
                )
                ->where('reference_id', $payment->id)
                ->lockForUpdate()
                ->first();

            if ($existingReversal) {
                if (
                    $existingReversal->mutation_type
                        === CashMutation::TYPE_OUT
                    && BigDecimal::of($existingReversal->amount)
                        ->isEqualTo($payment->amount)
                ) {
                    return $existingReversal;
                }

                throw new RuntimeException(
                    'Reversal Kas/Bank pembayaran sudah ada tetapi datanya tidak konsisten.'
                );
            }

            return CashMutation::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_date' => now()->toDateString(),
                'mutation_type' => CashMutation::TYPE_OUT,
                'amount' => $payment->amount,
                'reference_type' => 'sale_payment_cancellation',
                'reference_id' => $payment->id,
                'reference_number' => 'REV-'.$payment->payment_number,
                'description' => 'Pembatalan pembayaran '
                    .$payment->payment_number,
                'created_by' => $userId,
            ]);
        }, attempts: 3);
    }

    /**
     * Mencatat penerimaan Kas/Bank manual sekaligus
     * jurnal akuntansinya.
     *
     * Debit  Kas / Bank
     * Kredit Akun Lawan
     */
    public function recordManualReceipt(
        int $cashAccountId,
        string $transactionDate,
        string $amount,
        ?string $referenceNumber,
        ?string $description,
        int $counterAccountId,
        int $userId,
        string $idempotencyKey
    ): CashMutation {
        if (! Str::isUuid($idempotencyKey)) {
            throw new RuntimeException(
                'Pengenal transaksi penerimaan tidak valid. Buka kembali form.'
            );
        }

        $idempotencyKey = strtolower($idempotencyKey);

        if (
            ! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', $amount)
            || BigDecimal::of($amount)->isLessThanOrEqualTo(0)
        ) {
            throw new RuntimeException(
                'Nominal penerimaan harus lebih dari nol dan maksimal 2 angka desimal.'
            );
        }

        $amount = (string) BigDecimal::of($amount)->toScale(2);

        if (
            Validator::make(
                ['date' => $transactionDate],
                ['date' => ['required', 'date_format:Y-m-d']]
            )->fails()
            || $transactionDate > now()->toDateString()
        ) {
            throw new RuntimeException(
                'Tanggal penerimaan tidak valid atau melewati hari ini.'
            );
        }

        if (! User::query()->whereKey($userId)->exists()) {
            throw new RuntimeException(
                'Pengguna pencatat penerimaan tidak ditemukan.'
            );
        }

        if ($counterAccountId <= 0) {
            throw new RuntimeException(
                'Akun lawan penerimaan tidak valid.'
            );
        }

        $referenceNumber = trim($referenceNumber ?? '');
        $referenceNumber = $referenceNumber === ''
            ? null
            : $referenceNumber;

        $description = trim($description ?? '');
        $description = $description === ''
            ? null
            : $description;

        if (
            mb_strlen($referenceNumber ?? '') > 100
            || mb_strlen($description ?? '') > 2000
        ) {
            throw new RuntimeException(
                'Nomor referensi atau keterangan penerimaan terlalu panjang.'
            );
        }

        return DB::transaction(function () use (
            $cashAccountId,
            $transactionDate,
            $amount,
            $referenceNumber,
            $description,
            $counterAccountId,
            $userId,
            $idempotencyKey
        ): CashMutation {
            $cashAccount = CashAccount::query()
                ->whereKey($cashAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $cashAccount->isActive()) {
                throw new RuntimeException(
                    'Akun Kas/Bank sudah tidak aktif.'
                );
            }

            $existing = CashMutation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            /**
             * Untuk penerimaan harus TYPE_IN
             * dan reference_type manual_receipt.
             */
            if ($existing) {
                if (
                    $existing->cash_account_id !== $cashAccountId
                    || $existing->counter_account_id
                        !== $counterAccountId
                    || $existing->mutation_type
                        !== CashMutation::TYPE_IN
                    || $existing->reference_type
                        !== 'manual_receipt'
                    || ! BigDecimal::of($existing->amount)
                        ->isEqualTo($amount)
                    || $existing->transaction_date
                        ->toDateString() !== $transactionDate
                    || $existing->reference_number
                        !== $referenceNumber
                    || $existing->description !== $description
                    || $existing->created_by !== $userId
                ) {
                    throw new RuntimeException(
                        'Pengenal transaksi sudah digunakan untuk penerimaan yang berbeda.'
                    );
                }

                return $existing;
            }

            $cashSystemKey = match ($cashAccount->account_type) {
                CashAccount::TYPE_CASH => 'cash',
                CashAccount::TYPE_BANK => 'bank',

                default => throw new RuntimeException(
                    'Jenis akun Kas/Bank tidak valid.'
                ),
            };

            $cashLedgerAccount = Account::query()
                ->where('system_key', $cashSystemKey)
                ->firstOrFail();

            if ($cashLedgerAccount->id === $counterAccountId) {
                throw new RuntimeException(
                    'Akun lawan tidak boleh sama dengan akun Kas/Bank.'
                );
            }

            $mutation = CashMutation::create([
                'cash_account_id' => $cashAccount->id,
                'counter_account_id' => $counterAccountId,
                'transaction_date' => $transactionDate,
                'mutation_type' => CashMutation::TYPE_IN,
                'amount' => $amount,
                'reference_type' => 'manual_receipt',
                'reference_id' => null,
                'reference_number' => $referenceNumber,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'created_by' => $userId,
            ]);

            app(JournalService::class)->post(
                transactionDate: $transactionDate,
                referenceType: 'manual_receipt',
                referenceId: (int) $mutation->id,
                referenceNumber: $referenceNumber,
                description: $description
                    ?? 'Penerimaan Kas/Bank manual',
                userId: $userId,
                lines: [
                    [
                        'account_id' => $cashLedgerAccount->id,
                        'debit' => $amount,
                        'credit' => '0.00',
                        'description' => 'Penerimaan '.$cashAccount->name,
                    ],
                    [
                        'account_id' => $counterAccountId,
                        'debit' => '0.00',
                        'credit' => $amount,
                        'description' => 'Akun lawan penerimaan Kas/Bank',
                    ],
                ]
            );

            return $mutation->fresh();
        }, attempts: 3);
    }

    /**
     * Mencatat pengeluaran Kas/Bank manual sekaligus
     * jurnal akuntansinya.
     *
     * Debit  Akun Lawan
     * Kredit Kas / Bank
     */
    public function recordManualExpense(
        int $cashAccountId,
        string $transactionDate,
        string $amount,
        ?string $referenceNumber,
        ?string $description,
        int $counterAccountId,
        int $userId,
        string $idempotencyKey
    ): CashMutation {
        if (! Str::isUuid($idempotencyKey)) {
            throw new RuntimeException(
                'Pengenal transaksi pengeluaran tidak valid. Buka kembali form.'
            );
        }

        $idempotencyKey = strtolower($idempotencyKey);

        if (
            ! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', $amount)
            || BigDecimal::of($amount)->isLessThanOrEqualTo(0)
        ) {
            throw new RuntimeException(
                'Nominal pengeluaran harus lebih dari nol dan maksimal 2 angka desimal.'
            );
        }

        $amount = (string) BigDecimal::of($amount)->toScale(2);

        if (
            Validator::make(
                ['date' => $transactionDate],
                ['date' => ['required', 'date_format:Y-m-d']]
            )->fails()
            || $transactionDate > now()->toDateString()
        ) {
            throw new RuntimeException(
                'Tanggal pengeluaran tidak valid atau melewati hari ini.'
            );
        }

        if (! User::query()->whereKey($userId)->exists()) {
            throw new RuntimeException(
                'Pengguna pencatat pengeluaran tidak ditemukan.'
            );
        }

        if ($counterAccountId <= 0) {
            throw new RuntimeException(
                'Akun lawan pengeluaran tidak valid.'
            );
        }

        $referenceNumber = trim($referenceNumber ?? '');
        $referenceNumber = $referenceNumber === ''
            ? null
            : $referenceNumber;

        $description = trim($description ?? '');
        $description = $description === ''
            ? null
            : $description;

        /**
         * Validasi ini yang tadi Anda tanyakan.
         *
         * reference_number maksimal 100 karakter
         * sesuai panjang kolom database.
         *
         * description dibatasi 2000 karakter
         * agar input tidak berlebihan.
         */
        if (
            mb_strlen($referenceNumber ?? '') > 100
            || mb_strlen($description ?? '') > 2000
        ) {
            throw new RuntimeException(
                'Nomor referensi atau keterangan pengeluaran terlalu panjang.'
            );
        }

        return DB::transaction(function () use (
            $cashAccountId,
            $transactionDate,
            $amount,
            $referenceNumber,
            $description,
            $counterAccountId,
            $userId,
            $idempotencyKey
        ): CashMutation {
            $cashAccount = CashAccount::query()
                ->whereKey($cashAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $cashAccount->isActive()) {
                throw new RuntimeException(
                    'Akun Kas/Bank sudah tidak aktif.'
                );
            }

            $existing = CashMutation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->cash_account_id !== $cashAccountId
                    || $existing->counter_account_id
                        !== $counterAccountId
                    || $existing->mutation_type
                        !== CashMutation::TYPE_OUT
                    || $existing->reference_type
                        !== 'manual_expense'
                    || ! BigDecimal::of($existing->amount)
                        ->isEqualTo($amount)
                    || $existing->transaction_date
                        ->toDateString() !== $transactionDate
                    || $existing->reference_number
                        !== $referenceNumber
                    || $existing->description !== $description
                    || $existing->created_by !== $userId
                ) {
                    throw new RuntimeException(
                        'Pengenal transaksi sudah digunakan untuk pengeluaran yang berbeda.'
                    );
                }

                return $existing;
            }

            /**
             * Hitung saldo dari histori ledger yang sudah dikunci.
             */
            $mutations = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $balance = BigDecimal::of('0.00');

            foreach ($mutations as $mutation) {
                /**
                 * Jangan menganggap tipe selain IN otomatis OUT.
                 * Data ledger yang tidak valid harus ditolak.
                 */
                if (
                    ! in_array(
                        $mutation->mutation_type,
                        [
                            CashMutation::TYPE_IN,
                            CashMutation::TYPE_OUT,
                        ],
                        true
                    )
                    || BigDecimal::of($mutation->amount)
                        ->isLessThan(0)
                ) {
                    throw new RuntimeException(
                        'Riwayat Kas/Bank tidak valid.'
                    );
                }

                $balance =
                    $mutation->mutation_type
                        === CashMutation::TYPE_IN
                    ? $balance->plus($mutation->amount)
                    : $balance->minus($mutation->amount);
            }

            if (
                BigDecimal::of($amount)
                    ->isGreaterThan($balance)
            ) {
                throw new RuntimeException(
                    'Saldo Kas/Bank tidak mencukupi. Saldo tersedia Rp '
                    .$balance->toScale(2).'.'
                );
            }

            $cashSystemKey = match ($cashAccount->account_type) {
                CashAccount::TYPE_CASH => 'cash',
                CashAccount::TYPE_BANK => 'bank',

                default => throw new RuntimeException(
                    'Jenis akun Kas/Bank tidak valid.'
                ),
            };

            $cashLedgerAccount = Account::query()
                ->where('system_key', $cashSystemKey)
                ->firstOrFail();

            if ($cashLedgerAccount->id === $counterAccountId) {
                throw new RuntimeException(
                    'Akun lawan tidak boleh sama dengan akun Kas/Bank.'
                );
            }

            $mutation = CashMutation::create([
                'cash_account_id' => $cashAccount->id,
                'counter_account_id' => $counterAccountId,
                'transaction_date' => $transactionDate,
                'mutation_type' => CashMutation::TYPE_OUT,
                'amount' => $amount,
                'reference_type' => 'manual_expense',
                'reference_id' => null,
                'reference_number' => $referenceNumber,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'created_by' => $userId,
            ]);

            app(JournalService::class)->post(
                transactionDate: $transactionDate,
                referenceType: 'manual_expense',
                referenceId: (int) $mutation->id,
                referenceNumber: $referenceNumber,
                description: $description
                    ?? 'Pengeluaran Kas/Bank manual',
                userId: $userId,
                lines: [
                    [
                        'account_id' => $counterAccountId,
                        'debit' => $amount,
                        'credit' => '0.00',
                        'description' => 'Akun lawan pengeluaran Kas/Bank',
                    ],
                    [
                        'account_id' => $cashLedgerAccount->id,
                        'debit' => '0.00',
                        'credit' => $amount,
                        'description' => 'Pengeluaran '.$cashAccount->name,
                    ],
                ]
            );

            return $mutation->fresh();
        }, attempts: 3);
    }

    /**
     * Mencatat uang keluar untuk penarikan saldo nasabah.
     */
    public function recordWithdrawal(
        Withdrawal $withdrawal,
        ?int $userId
    ): CashMutation {
        if ($withdrawal->cash_account_id === null) {
            throw new RuntimeException(
                'Penarikan belum memiliki sumber Kas/Bank.'
            );
        }

        if (
            $userId !== null
            && ! User::query()->whereKey($userId)->exists()
        ) {
            throw new RuntimeException(
                'Pengguna pencatat penarikan tidak ditemukan.'
            );
        }

        if (
            BigDecimal::of($withdrawal->amount)
                ->isLessThanOrEqualTo(0)
        ) {
            throw new RuntimeException(
                'Nominal penarikan tidak valid.'
            );
        }

        $transactionDate =
            $withdrawal->transaction_date?->toDateString();

        if (
            $transactionDate === null
            || $transactionDate > now()->toDateString()
        ) {
            throw new RuntimeException(
                'Tanggal penarikan Kas/Bank tidak valid.'
            );
        }

        return DB::transaction(function () use (
            $withdrawal,
            $userId,
            $transactionDate
        ): CashMutation {
            $cashAccount = CashAccount::query()
                ->whereKey($withdrawal->cash_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $cashAccount->isActive()) {
                throw new RuntimeException(
                    'Akun Kas/Bank sumber penarikan sudah tidak aktif.'
                );
            }

            $existing = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('reference_type', 'withdrawal')
                ->where('reference_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    $existing->mutation_type
                        === CashMutation::TYPE_OUT
                    && BigDecimal::of($existing->amount)
                        ->isEqualTo($withdrawal->amount)
                ) {
                    return $existing;
                }

                throw new RuntimeException(
                    'Mutasi Kas/Bank penarikan sudah ada tetapi datanya tidak konsisten.'
                );
            }

            $mutations = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $balance = BigDecimal::of('0.00');

            foreach ($mutations as $mutation) {
                if (
                    ! in_array(
                        $mutation->mutation_type,
                        [
                            CashMutation::TYPE_IN,
                            CashMutation::TYPE_OUT,
                        ],
                        true
                    )
                    || BigDecimal::of($mutation->amount)
                        ->isLessThan(0)
                ) {
                    throw new RuntimeException(
                        'Riwayat Kas/Bank tidak valid.'
                    );
                }

                $balance =
                    $mutation->mutation_type
                        === CashMutation::TYPE_IN
                    ? $balance->plus($mutation->amount)
                    : $balance->minus($mutation->amount);
            }

            if (
                BigDecimal::of($withdrawal->amount)
                    ->isGreaterThan($balance)
            ) {
                throw new RuntimeException(
                    'Saldo Kas/Bank tidak mencukupi untuk penarikan. '
                    .'Saldo tersedia Rp '
                    .$balance->toScale(2).'.'
                );
            }

            return CashMutation::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_date' => $transactionDate,
                'mutation_type' => CashMutation::TYPE_OUT,
                'amount' => $withdrawal->amount,
                'reference_type' => 'withdrawal',
                'reference_id' => $withdrawal->id,
                'reference_number' => $withdrawal->withdrawal_number,
                'description' => 'Penarikan saldo nasabah '
                    .$withdrawal->withdrawal_number,
                'created_by' => $userId,
            ]);
        }, attempts: 3);
    }

    /**
     * Membalik mutasi Kas/Bank akibat pembatalan penarikan.
     */
    public function reverseWithdrawal(
        Withdrawal $withdrawal,
        int $userId
    ): CashMutation {
        if ($withdrawal->cash_account_id === null) {
            throw new RuntimeException(
                'Penarikan tidak memiliki sumber Kas/Bank.'
            );
        }

        if (! User::query()->whereKey($userId)->exists()) {
            throw new RuntimeException(
                'Pengguna pembatalan penarikan tidak ditemukan.'
            );
        }

        return DB::transaction(function () use (
            $withdrawal,
            $userId
        ): CashMutation {
            $cashAccount = CashAccount::query()
                ->whereKey($withdrawal->cash_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $original = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('reference_type', 'withdrawal')
                ->where('reference_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();

            if (! $original) {
                throw new RuntimeException(
                    'Mutasi Kas/Bank penarikan asal tidak ditemukan.'
                );
            }

            if (
                $original->mutation_type
                    !== CashMutation::TYPE_OUT
                || ! BigDecimal::of($original->amount)
                    ->isEqualTo($withdrawal->amount)
            ) {
                throw new RuntimeException(
                    'Mutasi Kas/Bank penarikan asal tidak konsisten.'
                );
            }

            $existingReversal = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where(
                    'reference_type',
                    'withdrawal_cancellation'
                )
                ->where('reference_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();

            if ($existingReversal) {
                if (
                    $existingReversal->mutation_type
                        === CashMutation::TYPE_IN
                    && BigDecimal::of($existingReversal->amount)
                        ->isEqualTo($withdrawal->amount)
                ) {
                    return $existingReversal;
                }

                throw new RuntimeException(
                    'Reversal Kas/Bank penarikan sudah ada tetapi datanya tidak konsisten.'
                );
            }

            return CashMutation::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_date' => now()->toDateString(),
                'mutation_type' => CashMutation::TYPE_IN,
                'amount' => $withdrawal->amount,
                'reference_type' => 'withdrawal_cancellation',
                'reference_id' => $withdrawal->id,
                'reference_number' => 'REV-'.$withdrawal->withdrawal_number,
                'description' => 'Pembatalan penarikan saldo '
                    .$withdrawal->withdrawal_number,
                'created_by' => $userId,
            ]);
        }, attempts: 3);
    }

    /**
     * Menghitung saldo akun berdasarkan seluruh ledger.
     */
    public function balance(
        CashAccount $cashAccount
    ): string {
        $incoming = CashMutation::query()
            ->where('cash_account_id', $cashAccount->id)
            ->where(
                'mutation_type',
                CashMutation::TYPE_IN
            )
            ->sum('amount');

        $outgoing = CashMutation::query()
            ->where('cash_account_id', $cashAccount->id)
            ->where(
                'mutation_type',
                CashMutation::TYPE_OUT
            )
            ->sum('amount');

        return (string) BigDecimal::of((string) $incoming)
            ->minus((string) $outgoing)
            ->toScale(2);
    }
}
