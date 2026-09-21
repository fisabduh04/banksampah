<?php

namespace App\Services;

use App\Models\BalanceMutation;
use App\Models\Customer;
use App\Models\Withdrawal;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Support\Facades\DB;

class WithdrawalService
{
    public function post(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal): void {
            $withdrawal = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($withdrawal->status !== 'draft') {
                throw new Exception(
                    'Hanya transaksi yang belum dibukukan yang dapat diposting.'
                );
            }

            if (BigDecimal::of($withdrawal->amount)->isLessThanOrEqualTo(0)) {
                throw new Exception(
                    'Jumlah penarikan harus lebih besar dari nol.'
                );
            }

            Customer::query()->whereKey($withdrawal->customer_id)->lockForUpdate()->firstOrFail();
            $mutations = BalanceMutation::query()->where('customer_id', $withdrawal->customer_id)->lockForUpdate()->get();
            if (BalanceMutation::query()->where('reference_type', 'withdrawal')->where('reference_id', $withdrawal->id)->exists()) {
                throw new Exception('Mutasi penarikan ini sudah pernah dibuat.');
            }
            $transactionDate = $withdrawal->transaction_date?->toDateString();
            if ($transactionDate === null || $transactionDate > now()->toDateString()) {
                throw new Exception('Tanggal penarikan wajib diisi dan tidak boleh melewati hari ini. Gunakan tanggal uang benar-benar diserahkan kepada nasabah.');
            }
            foreach ($mutations as $mutation) {
                if ($mutation->transaction_date->toDateString() > $transactionDate) {
                    throw new Exception('Tanggal penarikan tidak boleh mendahului mutasi saldo terakhir nasabah. Jika salah input, perbaiki tanggal draft sesuai bukti. Jika pencatatan terlambat, hubungi administrator untuk memeriksa riwayat saldo.');
                }
            }
            $availableBalance = BigDecimal::of(0);
            foreach ($mutations as $mutation) {
                if (! in_array($mutation->type, ['credit', 'debit'], true) || BigDecimal::of($mutation->amount)->isLessThan(0)) {
                    throw new Exception('Riwayat saldo tidak valid. Periksa transaksi sebelum melakukan penarikan.');
                }
                $availableBalance = $mutation->type === 'credit'
                    ? $availableBalance->plus($mutation->amount)
                    : $availableBalance->minus($mutation->amount);
            }
            if (BigDecimal::of($withdrawal->amount)->isGreaterThan($availableBalance)) {
                throw new Exception('Saldo nasabah tidak mencukupi untuk penarikan ini. Periksa nominal dan Mutasi Saldo nasabah. Jangan membuat setoran fiktif atau mengubah tanggal untuk menambah saldo.');
            }

            $withdrawal->update([
                'status' => 'posted',
            ]);

            BalanceMutation::create([
                'customer_id' => $withdrawal->customer_id,
                'type' => 'debit',
                'amount' => $withdrawal->amount,
                'reference_type' => 'withdrawal',
                'reference_id' => $withdrawal->id,
                'transaction_date' => $withdrawal->transaction_date,
                'description' => 'Penarikan saldo '.$withdrawal->withdrawal_number,
            ]);
        }, attempts: 3);
        $withdrawal->refresh();
    }

    public function cancel(Withdrawal $withdrawal, string $reason, int $userId, bool $confirmedCorrection = false): void
    {
        $reason = app(CancellationReason::class)->describe($reason, $userId, $confirmedCorrection);
        DB::transaction(function () use ($withdrawal, $reason): void {
            $withdrawal = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status !== 'posted') {
                throw new Exception(
                    'Hanya transaksi yang telah dibukukan yang dapat dibatalkan.'
                );
            }

            if ($withdrawal->getAttribute('verified_at') !== null) {
                throw new Exception('Penarikan sudah diverifikasi. Periksa pengembalian uang melalui proses terpisah.');
            }

            Customer::query()->whereKey($withdrawal->customer_id)->lockForUpdate()->firstOrFail();
            $sources = BalanceMutation::query()->where('reference_type', 'withdrawal')->where('reference_id', $withdrawal->id)
                ->lockForUpdate()->get();
            if ($sources->count() !== 1 || $sources->first()->type !== 'debit'
                || $sources->first()->customer_id !== $withdrawal->customer_id
                || ! BigDecimal::of($sources->first()->amount)->isEqualTo($withdrawal->amount)
                || BigDecimal::of($withdrawal->amount)->isLessThanOrEqualTo(0)) {
                throw new Exception('Mutasi penarikan asal tidak sesuai. Periksa transaksi sebelum membatalkan.');
            }
            if ($sources->first()->transaction_date->toDateString() > now()->toDateString()) {
                throw new Exception('Tanggal pembatalan tidak boleh mendahului mutasi penarikan asal.');
            }
            $existingReversal = BalanceMutation::query()
                ->where('reference_type', 'withdrawal_cancellation')
                ->where('reference_id', $withdrawal->id)
                ->exists();

            if ($existingReversal) {
                throw new Exception(
                    'Pembatalan transaksi ini sudah pernah diproses.'
                );
            }

            BalanceMutation::create([
                'customer_id' => $withdrawal->customer_id,
                'type' => 'credit',
                'amount' => $withdrawal->amount,
                'reference_type' => 'withdrawal_cancellation',
                'reference_id' => $withdrawal->id,
                'transaction_date' => now()->toDateString(),
                'description' => 'Pembatalan penarikan saldo '.$withdrawal->withdrawal_number.' | '.$reason,
            ]);

            $withdrawal->update([
                'status' => 'cancelled',
            ]);
        }, attempts: 3);
        $withdrawal->refresh();
    }
}
