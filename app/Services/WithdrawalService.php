<?php

namespace App\Services;

use App\Models\BalanceMutation;
use App\Models\Withdrawal;
use Exception;
use Illuminate\Support\Facades\DB;

class WithdrawalService
{
    public function post(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal) {

            if ($withdrawal->status !== 'draft') {
                throw new Exception(
                    'Hanya transaksi yang belum dibukukan yang dapat diposting.'
                );
            }

            if ((float) $withdrawal->amount <= 0) {
                throw new Exception(
                    'Jumlah penarikan harus lebih besar dari nol.'
                );
            }

            $customer = $withdrawal->customer;

            if (! $customer) {
                throw new Exception(
                    'Nasabah tidak ditemukan.'
                );
            }

            $availableBalance = (float) $customer->balance;

            if ((float) $withdrawal->amount > $availableBalance) {
                throw new Exception(
                    'Saldo nasabah tidak mencukupi untuk penarikan ini.'
                );
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
        });
    }

    public function cancel(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal) {
            if ($withdrawal->status !== 'posted') {
                throw new Exception(
                    'Hanya transaksi yang telah dibukukan yang dapat dibatalkan.'
                );
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
                'description' => 'Pembatalan penarikan saldo '.$withdrawal->withdrawal_number,
            ]);

            $withdrawal->update([
                'status' => 'cancelled',
            ]);
        });
    }
}
