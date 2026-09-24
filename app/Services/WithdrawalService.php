<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BalanceMutation;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Withdrawal;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Support\Facades\DB;

class WithdrawalService
{
    /**
     * Membukukan penarikan saldo nasabah.
     *
     * Posting baru wajib memiliki cash_account_id:
     * - saldo nasabah berkurang;
     * - saldo Kas/Bank berkurang;
     * - jurnal akuntansi otomatis dibuat.
     *
     * Transaksi historis tanpa cash_account_id tetap
     * didukung pada pembacaan dan pembatalan.
     */
    public function post(
        Withdrawal $withdrawal,
        ?int $userId = null
    ): void {
        DB::transaction(function () use ($withdrawal, $userId): void {
            $withdrawal = Withdrawal::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== 'draft') {
                throw new Exception(
                    'Hanya transaksi yang belum dibukukan yang dapat diposting.'
                );
            }
            /**
             * Semua posting baru wajib memiliki sumber Kas/Bank,
             * terlepas dari ada atau tidaknya userId.
             */
            if ($withdrawal->cash_account_id === null) {
                throw new Exception(
                    'Pilih Kas/Bank sumber pembayaran sebelum penarikan diposting.'
                );
            }

            if (
                BigDecimal::of($withdrawal->amount)
                    ->isLessThanOrEqualTo(0)
            ) {
                throw new Exception(
                    'Jumlah penarikan harus lebih besar dari nol.'
                );
            }

            Customer::query()
                ->whereKey($withdrawal->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $mutations = BalanceMutation::query()
                ->where('customer_id', $withdrawal->customer_id)
                ->lockForUpdate()
                ->get();

            if (
                BalanceMutation::query()
                    ->where('reference_type', 'withdrawal')
                    ->where('reference_id', $withdrawal->id)
                    ->exists()
            ) {
                throw new Exception(
                    'Mutasi penarikan ini sudah pernah dibuat.'
                );
            }

            $transactionDate =
                $withdrawal->transaction_date?->toDateString();

            if (
                $transactionDate === null
                || $transactionDate > now()->toDateString()
            ) {
                throw new Exception(
                    'Tanggal penarikan wajib diisi dan tidak boleh melewati hari ini. '
                    .'Gunakan tanggal uang benar-benar diserahkan kepada nasabah.'
                );
            }

            foreach ($mutations as $mutation) {
                if (
                    $mutation->transaction_date->toDateString()
                    > $transactionDate
                ) {
                    throw new Exception(
                        'Tanggal penarikan tidak boleh mendahului mutasi saldo terakhir nasabah. '
                        .'Jika salah input, perbaiki tanggal draft sesuai bukti.'
                    );
                }
            }

            $availableBalance = BigDecimal::of(0);

            foreach ($mutations as $mutation) {
                if (
                    ! in_array(
                        $mutation->type,
                        ['credit', 'debit'],
                        true
                    )
                    || BigDecimal::of($mutation->amount)->isLessThan(0)
                ) {
                    throw new Exception(
                        'Riwayat saldo tidak valid. Periksa transaksi sebelum melakukan penarikan.'
                    );
                }

                $availableBalance =
                    $mutation->type === 'credit'
                        ? $availableBalance->plus($mutation->amount)
                        : $availableBalance->minus($mutation->amount);
            }

            if (
                BigDecimal::of($withdrawal->amount)
                    ->isGreaterThan($availableBalance)
            ) {
                throw new Exception(
                    'Saldo nasabah tidak mencukupi untuk penarikan ini.'
                );
            }

            /**
             * Mutasi saldo nasabah.
             */
            BalanceMutation::create([
                'customer_id' => $withdrawal->customer_id,
                'type' => 'debit',
                'amount' => $withdrawal->amount,
                'reference_type' => 'withdrawal',
                'reference_id' => $withdrawal->id,
                'transaction_date' => $withdrawal->transaction_date,
                'description' => 'Penarikan saldo '.$withdrawal->withdrawal_number,
            ]);

            /**
             * Catat pengeluaran Kas/Bank dan jurnal untuk posting baru.
             */
            if ($withdrawal->cash_account_id !== null) {
                /**
                 * Mengurangi ledger Kas/Bank.
                 *
                 * CashMutationService juga mengecek bahwa
                 * saldo Kas/Bank mencukupi.
                 */
                $cashMutation = app(CashMutationService::class)
                    ->recordWithdrawal(
                        withdrawal: $withdrawal,
                        userId: $userId
                    );

                $cashAccount = $cashMutation
                    ->cashAccount()
                    ->firstOrFail();

                /**
                 * Tentukan akun akuntansi berdasarkan
                 * jenis sumber uang.
                 */
                $cashSystemKey = match ($cashAccount->account_type) {
                    CashAccount::TYPE_CASH => 'cash',
                    CashAccount::TYPE_BANK => 'bank',

                    default => throw new Exception(
                        'Jenis akun Kas/Bank penarikan tidak valid.'
                    ),
                };

                $customerSavingsAccount = Account::query()
                    ->where('system_key', 'customer_savings')
                    ->firstOrFail();

                $cashLedgerAccount = Account::query()
                    ->where('system_key', $cashSystemKey)
                    ->firstOrFail();

                /**
                 * Jurnal penarikan:
                 *
                 * Debit  Tabungan Nasabah
                 * Kredit Kas / Bank
                 */
                app(JournalService::class)->post(
                    transactionDate: $transactionDate,
                    referenceType: 'withdrawal',
                    referenceId: (int) $withdrawal->id,
                    referenceNumber: $withdrawal->withdrawal_number,
                    description: 'Penarikan saldo nasabah '
                        .$withdrawal->withdrawal_number,
                    userId: $userId,
                    lines: [
                        [
                            'account_id' => $customerSavingsAccount->id,
                            'debit' => (string) $withdrawal->amount,
                            'credit' => '0.00',
                            'description' => 'Pengurangan tabungan nasabah',
                        ],
                        [
                            'account_id' => $cashLedgerAccount->id,
                            'debit' => '0.00',
                            'credit' => (string) $withdrawal->amount,
                            'description' => 'Pengeluaran '
                                .$cashAccount->name,
                        ],
                    ]
                );
            }

            /**
             * Finalisasi transaksi.
             *
             * Diletakkan terakhir agar semua ledger
             * sudah berhasil sebelum status menjadi posted.
             */
            $withdrawal->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);
        }, attempts: 3);

        $withdrawal->refresh();
    }

    /**
     * Membatalkan penarikan.
     *
     * Histori transaksi lama tidak dihapus.
     * Sistem membuat reversal pada ledger yang terkait.
     */
    public function cancel(
        Withdrawal $withdrawal,
        string $reason,
        int $userId,
        bool $confirmedCorrection = false
    ): void {
        $reason = app(CancellationReason::class)
            ->describe(
                $reason,
                $userId,
                $confirmedCorrection
            );

        DB::transaction(function () use (
            $withdrawal,
            $reason,
            $userId
        ): void {
            $withdrawal = Withdrawal::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== 'posted') {
                throw new Exception(
                    'Hanya transaksi yang telah dibukukan yang dapat dibatalkan.'
                );
            }

            if ($withdrawal->getAttribute('verified_at') !== null) {
                throw new Exception(
                    'Penarikan sudah diverifikasi. Periksa pengembalian uang melalui proses terpisah.'
                );
            }

            Customer::query()
                ->whereKey($withdrawal->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $sources = BalanceMutation::query()
                ->where('reference_type', 'withdrawal')
                ->where('reference_id', $withdrawal->id)
                ->lockForUpdate()
                ->get();

            if (
                $sources->count() !== 1
                || $sources->first()->type !== 'debit'
                || $sources->first()->customer_id
                    !== $withdrawal->customer_id
                || ! BigDecimal::of($sources->first()->amount)
                    ->isEqualTo($withdrawal->amount)
                || BigDecimal::of($withdrawal->amount)
                    ->isLessThanOrEqualTo(0)
            ) {
                throw new Exception(
                    'Mutasi penarikan asal tidak sesuai. Periksa transaksi sebelum membatalkan.'
                );
            }

            if (
                $sources->first()
                    ->transaction_date
                    ->toDateString()
                > now()->toDateString()
            ) {
                throw new Exception(
                    'Tanggal pembatalan tidak boleh mendahului mutasi penarikan asal.'
                );
            }

            $existingReversal = BalanceMutation::query()
                ->where(
                    'reference_type',
                    'withdrawal_cancellation'
                )
                ->where('reference_id', $withdrawal->id)
                ->exists();

            if ($existingReversal) {
                throw new Exception(
                    'Pembatalan transaksi ini sudah pernah diproses.'
                );
            }

            /**
             * Balik saldo nasabah.
             */
            BalanceMutation::create([
                'customer_id' => $withdrawal->customer_id,
                'type' => 'credit',
                'amount' => $withdrawal->amount,
                'reference_type' => 'withdrawal_cancellation',
                'reference_id' => $withdrawal->id,
                'transaction_date' => now()->toDateString(),
                'description' => 'Pembatalan penarikan saldo '
                    .$withdrawal->withdrawal_number
                    .' | '.$reason,
            ]);

            /**
             * Untuk transaksi baru, balik juga Kas/Bank.
             */
            if ($withdrawal->cash_account_id !== null) {
                app(CashMutationService::class)
                    ->reverseWithdrawal(
                        withdrawal: $withdrawal,
                        userId: $userId
                    );
            }

            /**
             * Balik jurnal jika jurnal asal tersedia.
             *
             * Transaksi historis sebelum Fase 8 mungkin
             * belum memiliki jurnal.
             */
            $originalJournal = JournalEntry::query()
                ->where('reference_type', 'withdrawal')
                ->where('reference_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();

            if ($originalJournal !== null) {
                app(JournalService::class)->reverse(
                    journalEntry: $originalJournal,
                    transactionDate: now()->toDateString(),
                    referenceType: 'withdrawal_cancellation',
                    referenceId: (int) $withdrawal->id,
                    referenceNumber: 'REV-'.$withdrawal->withdrawal_number,
                    description: 'Pembatalan penarikan saldo '
                        .$withdrawal->withdrawal_number,
                    userId: $userId
                );
            }

            /**
             * Finalisasi pembatalan.
             */
            $withdrawal->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);
        }, attempts: 3);

        $withdrawal->refresh();
    }
}
