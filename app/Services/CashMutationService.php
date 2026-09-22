<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMutation;
use App\Models\SalePayment;
use App\Models\User;
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
        /**
         * Validasi nominal.
         */
        if (
            ! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', $amount)
            || BigDecimal::of($amount)->isLessThanOrEqualTo(0)
        ) {
            throw new RuntimeException(
                'Nominal mutasi harus lebih dari nol dan maksimal 2 angka desimal.'
            );
        }

        $amount = (string) BigDecimal::of($amount)->toScale(2);

        /**
         * Validasi jenis mutasi.
         */
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

        /**
         * Validasi tanggal transaksi.
         */
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

        if ($referenceType === '' || mb_strlen($referenceType) > 50) {
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
            /**
             * Kunci akun untuk mencegah transaksi paralel
             * memproses akun yang sama tanpa koordinasi.
             */
            $cashAccount = CashAccount::query()
                ->whereKey($cashAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $cashAccount->isActive()) {
                throw new RuntimeException(
                    'Akun Kas/Bank sudah tidak aktif.'
                );
            }

            /**
             * Cegah transaksi sumber yang sama dicatat dua kali.
             */
            if ($referenceId !== null) {
                $existing = CashMutation::query()
                    ->where('cash_account_id', $cashAccount->id)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    /**
                     * Jika seluruh data sama, kembalikan record lama.
                     * Ini membuat proses idempotent.
                     */
                    if (
                        $existing->mutation_type === $mutationType
                        && BigDecimal::of($existing->amount)->isEqualTo($amount)
                        && $existing->transaction_date->toDateString() === $transactionDate
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
     *
     * Mutasi pembayaran asal tidak dihapus.
     * Sistem membuat mutasi OUT agar histori keuangan
     * tetap dapat ditelusuri dan diaudit.
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
            /**
             * Akun tetap boleh digunakan untuk reversal meskipun
             * sudah dinonaktifkan. Transaksi historis harus tetap
             * dapat dikoreksi.
             */
            $cashAccount = CashAccount::query()
                ->whereKey($payment->cash_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            /**
             * Pastikan mutasi IN pembayaran asal benar-benar ada.
             */
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
                $originalMutation->mutation_type !== CashMutation::TYPE_IN
                || ! BigDecimal::of($originalMutation->amount)
                    ->isEqualTo($payment->amount)
            ) {
                throw new RuntimeException(
                    'Mutasi Kas/Bank pembayaran asal tidak konsisten.'
                );
            }

            /**
             * Cegah reversal ganda.
             */
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
                    $existingReversal->mutation_type === CashMutation::TYPE_OUT
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
     * Mencatat penerimaan Kas/Bank manual.
     */
    /**
     * Mencatat penerimaan Kas/Bank manual.
     *
     * Pencatatan dilakukan atomik agar pengiriman ulang
     * permintaan yang sama tidak menggandakan mutasi.
     */
    public function recordManualReceipt(
        int $cashAccountId,
        string $transactionDate,
        string $amount,
        ?string $referenceNumber,
        ?string $description,
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
                    || $existing->mutation_type !== CashMutation::TYPE_IN
                    || $existing->reference_type !== 'manual_receipt'
                    || ! BigDecimal::of($existing->amount)->isEqualTo($amount)
                    || $existing->transaction_date->toDateString() !== $transactionDate
                    || $existing->reference_number !== $referenceNumber
                    || $existing->description !== $description
                    || $existing->created_by !== $userId
                ) {
                    throw new RuntimeException(
                        'Pengenal transaksi sudah digunakan untuk penerimaan yang berbeda.'
                    );
                }

                return $existing;
            }

            return CashMutation::create([
                'cash_account_id' => $cashAccount->id,
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
        }, attempts: 3);
    }

    /**
     * Mencatat pengeluaran Kas/Bank manual.
     *
     * Saldo diperiksa di dalam transaksi database
     * setelah akun dikunci.
     */
    public function recordManualExpense(
        int $cashAccountId,
        string $transactionDate,
        string $amount,
        ?string $referenceNumber,
        ?string $description,
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

        return DB::transaction(function () use (
            $cashAccountId,
            $transactionDate,
            $amount,
            $referenceNumber,
            $description,
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
                    || $existing->mutation_type !== CashMutation::TYPE_OUT
                    || $existing->reference_type !== 'manual_expense'
                    || ! BigDecimal::of($existing->amount)->isEqualTo($amount)
                    || $existing->transaction_date->toDateString() !== $transactionDate
                ) {
                    throw new RuntimeException(
                        'Pengenal transaksi sudah digunakan untuk data pengeluaran yang berbeda.'
                    );
                }

                return $existing;
            }

            $incoming = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('mutation_type', CashMutation::TYPE_IN)
                ->lockForUpdate()
                ->sum('amount');

            $outgoing = CashMutation::query()
                ->where('cash_account_id', $cashAccount->id)
                ->where('mutation_type', CashMutation::TYPE_OUT)
                ->lockForUpdate()
                ->sum('amount');

            $balance = BigDecimal::of((string) $incoming)
                ->minus((string) $outgoing);

            if (BigDecimal::of($amount)->isGreaterThan($balance)) {
                throw new RuntimeException(
                    'Saldo Kas/Bank tidak mencukupi. Saldo tersedia Rp '
                    .$balance->toScale(2).'.'
                );
            }

            return CashMutation::create([
                'cash_account_id' => $cashAccount->id,
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
        }, attempts: 3);
    }

    /**
     * Menghitung saldo akun berdasarkan seluruh ledger.
     */
    public function balance(CashAccount $cashAccount): string
    {
        $incoming = CashMutation::query()
            ->where('cash_account_id', $cashAccount->id)
            ->where('mutation_type', CashMutation::TYPE_IN)
            ->sum('amount');

        $outgoing = CashMutation::query()
            ->where('cash_account_id', $cashAccount->id)
            ->where('mutation_type', CashMutation::TYPE_OUT)
            ->sum('amount');

        return (string) BigDecimal::of((string) $incoming)
            ->minus((string) $outgoing)
            ->toScale(2);
    }
}
