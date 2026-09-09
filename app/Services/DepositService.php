<?php

namespace App\Services;

use App\Models\BalanceMutation;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use Exception;
use Illuminate\Support\Facades\DB;

class DepositService
{
    public function post(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $deposit->load('items');

            if ($deposit->status !== 'draft') {
                throw new Exception(
                    'Hanya transaksi yang belum dibukukan yang dapat diposting.'
                );
            }

            if ($deposit->items->isEmpty()) {
                throw new Exception(
                    'Transaksi belum memiliki detail bahan.'
                );
            }

            foreach ($deposit->items as $item) {
                $weight = (float) $item->weight;
                $price = (float) $item->price;
                $subtotal = (float) $item->subtotal;

                if ($weight <= 0) {
                    throw new Exception(
                        'Terdapat berat bahan yang nol atau negatif.'
                    );
                }

                if ($price <= 0) {
                    throw new Exception(
                        'Terdapat harga bahan yang nol atau negatif.'
                    );
                }

                $expectedSubtotal = $weight * $price;

                if (abs($subtotal - $expectedSubtotal) > 0.01) {
                    throw new Exception(
                        'Terdapat subtotal bahan yang tidak sesuai.'
                    );
                }
            }

            $totalWeight = $deposit->items->sum(
                fn ($item) => (float) $item->weight
            );

            $totalAmount = $deposit->items->sum(
                fn ($item) => (float) $item->subtotal
            );

            if (
                abs((float) $deposit->total_weight - $totalWeight) > 0.001
            ) {
                throw new Exception(
                    'Total berat tidak sesuai dengan rincian timbangan.'
                );
            }

            if (
                abs((float) $deposit->total_amount - $totalAmount) > 0.01
            ) {
                throw new Exception(
                    'Nilai setoran tidak sesuai dengan rincian transaksi.'
                );
            }

            $deposit->update([
                'status' => 'posted',
            ]);

            BalanceMutation::create([
                'customer_id' => $deposit->customer_id,
                'type' => 'credit',
                'amount' => $deposit->total_amount,
                'reference_type' => 'deposit',
                'reference_id' => $deposit->id,
                'transaction_date' => $deposit->transaction_date,
                'description' => 'Setoran nasabah '.$deposit->deposit_number,
            ]);

            foreach ($deposit->items as $item) {
                InventoryMovement::create([
                    'waste_type_id' => $item->waste_type_id,
                    'movement_type' => 'in',
                    'quantity' => $item->weight,
                    'reference_type' => 'deposit',
                    'reference_id' => $deposit->id,
                    'transaction_date' => $deposit->transaction_date,
                    'description' => 'Setoran nasabah '.$deposit->deposit_number,
                ]);
            }
        });
    }

    public function cancel(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $deposit->load('items');

            if ($deposit->status !== 'posted') {
                throw new Exception(
                    'Hanya transaksi yang telah dibukukan yang dapat dibatalkan.'
                );
            }

            $existingReversal = BalanceMutation::query()
                ->where('reference_type', 'deposit_cancellation')
                ->where('reference_id', $deposit->id)
                ->exists();

            if ($existingReversal) {
                throw new Exception(
                    'Pembatalan transaksi ini sudah pernah diproses.'
                );
            }

            BalanceMutation::create([
                'customer_id' => $deposit->customer_id,
                'type' => 'debit',
                'amount' => $deposit->total_amount,
                'reference_type' => 'deposit_cancellation',
                'reference_id' => $deposit->id,
                'transaction_date' => now()->toDateString(),
                'description' => 'Pembatalan setoran nasabah '.$deposit->deposit_number,
            ]);

            foreach ($deposit->items as $item) {
                InventoryMovement::create([
                    'waste_type_id' => $item->waste_type_id,
                    'movement_type' => 'out',
                    'quantity' => $item->weight,
                    'reference_type' => 'deposit_cancellation',
                    'reference_id' => $deposit->id,
                    'transaction_date' => now()->toDateString(),
                    'description' => 'Pembatalan setoran nasabah '.$deposit->deposit_number,
                ]);
            }

            $deposit->update([
                'status' => 'cancelled',
            ]);
        });
    }
}
