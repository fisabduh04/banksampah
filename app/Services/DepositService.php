<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BalanceMutation;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\WasteType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Exception;
use Illuminate\Support\Facades\DB;

class DepositService
{
    public function post(Deposit $deposit, ?int $userId = null): void
    {
        DB::transaction(function () use ($deposit, $userId) {
            $deposit = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $deposit->load('items');

            if ($deposit->status !== 'draft') {
                throw new Exception(
                    'Hanya transaksi yang belum dibukukan yang dapat diposting.'
                );
            }

            if (BalanceMutation::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->exists()
                || InventoryMovement::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->exists()
                || JournalEntry::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->exists()) {
                throw new Exception('Draft setoran sudah memiliki pencatatan keuangan. Periksa histori tanpa memposting ulang.');
            }

            if ($deposit->items->isEmpty()) {
                throw new Exception(
                    'Transaksi belum memiliki detail bahan.'
                );
            }

            $totalWeight = BigDecimal::of('0.000');
            $totalAmount = BigDecimal::of('0.00');
            foreach ($deposit->items as $item) {
                $weight = BigDecimal::of($item->weight);
                $price = BigDecimal::of($item->price);
                $subtotal = BigDecimal::of($item->subtotal);

                if ($weight->isLessThanOrEqualTo(0)) {
                    throw new Exception(
                        'Terdapat berat bahan yang nol atau negatif.'
                    );
                }

                if ($price->isLessThanOrEqualTo(0)) {
                    throw new Exception(
                        'Terdapat harga bahan yang nol atau negatif.'
                    );
                }

                $expectedSubtotal = $weight->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);

                if (! $subtotal->isEqualTo($expectedSubtotal)) {
                    throw new Exception(
                        'Terdapat subtotal bahan yang tidak sesuai.'
                    );
                }
                $totalWeight = $totalWeight->plus($weight);
                $totalAmount = $totalAmount->plus($subtotal);
            }

            if (! BigDecimal::of($deposit->total_weight)->isEqualTo($totalWeight)) {
                throw new Exception(
                    'Total berat tidak sesuai dengan rincian timbangan.'
                );
            }

            if (! BigDecimal::of($deposit->total_amount)->isEqualTo($totalAmount)) {
                throw new Exception(
                    'Nilai setoran tidak sesuai dengan rincian transaksi.'
                );
            }

            $transactionDate = $deposit->transaction_date?->toDateString();
            if ($transactionDate === null || $transactionDate > now()->toDateString()) {
                throw new Exception('Tanggal setoran wajib diisi dan tidak boleh melewati hari ini. Gunakan tanggal barang benar-benar diterima sesuai bukti timbang.');
            }
            Customer::query()->whereKey($deposit->customer_id)->lockForUpdate()->firstOrFail();
            $balances = BalanceMutation::query()->where('customer_id', $deposit->customer_id)->lockForUpdate()->get();
            foreach ($balances as $mutation) {
                if ($mutation->transaction_date->toDateString() > $transactionDate) {
                    throw new Exception('Tanggal setoran tidak boleh mendahului mutasi saldo terakhir nasabah. Jika salah input, perbaiki tanggal pada draft sesuai bukti. Jika pencatatan terlambat, hubungi administrator untuk pemeriksaan saldo dan HPP; jangan mengganti tanggal hanya agar lolos.');
                }
            }
            foreach ($deposit->items->pluck('waste_type_id')->unique()->sort() as $wasteTypeId) {
                WasteType::query()->whereKey($wasteTypeId)->lockForUpdate()->firstOrFail();
                $movements = InventoryMovement::query()->where('waste_type_id', $wasteTypeId)->lockForUpdate()->get();
                foreach ($movements as $movement) {
                    if ($movement->transaction_date->toDateString() > $transactionDate) {
                        throw new Exception('Tanggal setoran tidak boleh mendahului mutasi persediaan terakhir bahan terkait. Jika salah input, perbaiki tanggal pada draft sesuai bukti. Jika pencatatan terlambat, hubungi administrator untuk pemeriksaan saldo dan HPP; jangan mengganti tanggal hanya agar lolos.');
                    }
                }
            }

            $deposit->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $userId,
            ])->save();

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
                    'unit_cost' => $item->price,
                    'total_cost' => $item->subtotal,
                    'reference_type' => 'deposit',
                    'reference_id' => $deposit->id,
                    'transaction_date' => $deposit->transaction_date,
                    'description' => 'Setoran nasabah '.$deposit->deposit_number,
                ]);
            }

            /**
             * =========================================================
             * JURNAL OTOMATIS SETORAN NASABAH
             * =========================================================
             *
             * Debit  Persediaan Sampah
             * Kredit Tabungan Nasabah
             */
            $inventoryAccount = Account::query()
                ->where('system_key', 'inventory')
                ->firstOrFail();

            $customerSavingsAccount = Account::query()
                ->where('system_key', 'customer_savings')
                ->firstOrFail();

            app(JournalService::class)->post(
                transactionDate: $transactionDate,
                referenceType: 'deposit',
                referenceId: (int) $deposit->id,
                referenceNumber: $deposit->deposit_number,
                description: 'Setoran nasabah '.$deposit->deposit_number,
                userId: $userId,
                lines: [
                    [
                        'account_id' => $inventoryAccount->id,
                        'debit' => (string) $deposit->total_amount,
                        'credit' => '0.00',
                        'description' => 'Persediaan dari setoran nasabah',
                    ],
                    [
                        'account_id' => $customerSavingsAccount->id,
                        'debit' => '0.00',
                        'credit' => (string) $deposit->total_amount,
                        'description' => 'Penambahan saldo tabungan nasabah',
                    ],
                ]
            );

        });
        $deposit->refresh();
    }

    public function cancel(Deposit $deposit, string $reason, int $userId, bool $confirmedCorrection = false): void
    {
        $reason = app(CancellationReason::class)->describe($reason, $userId, $confirmedCorrection);
        DB::transaction(function () use ($deposit, $reason, $userId): void {
            $record = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== 'posted') {
                throw new Exception('Hanya transaksi yang telah dibukukan yang dapat dibatalkan.');
            }
            Customer::query()->whereKey($record->customer_id)->lockForUpdate()->firstOrFail();
            $balances = BalanceMutation::query()->where('customer_id', $record->customer_id)->lockForUpdate()->get();
            $credits = $balances->where('reference_type', 'deposit')->where('reference_id', $record->id);
            if ($credits->count() !== 1 || $credits->first()->type !== 'credit'
                || ! BigDecimal::of($credits->first()->amount)->isEqualTo($record->total_amount)
                || BigDecimal::of($record->total_amount)->isLessThanOrEqualTo(0)) {
                throw new Exception('Mutasi saldo asal tidak sesuai dengan setoran. Periksa transaksi sebelum membatalkan.');
            }
            if ($balances->where('reference_type', 'deposit_cancellation')->where('reference_id', $record->id)->isNotEmpty()) {
                throw new Exception('Pembatalan transaksi ini sudah pernah diproses.');
            }
            $balance = BigDecimal::of(0);
            foreach ($balances as $mutation) {
                if (! in_array($mutation->type, ['credit', 'debit'], true) || BigDecimal::of($mutation->amount)->isLessThan(0)
                    || $mutation->transaction_date->toDateString() > now()->toDateString()) {
                    throw new Exception('Riwayat saldo tidak valid. Periksa transaksi sebelum membatalkan.');
                }
                $balance = $mutation->type === 'credit' ? $balance->plus($mutation->amount) : $balance->minus($mutation->amount);
            }
            if ($balance->isLessThan($record->total_amount)) {
                throw new Exception('Setoran tidak dapat dibatalkan karena saldo nasabah tidak mencukupi.');
            }

            $items = $record->items()->orderBy('waste_type_id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw new Exception('Rincian setoran tidak ditemukan.');
            }
            $plans = [];
            foreach ($items->groupBy('waste_type_id') as $wasteTypeId => $group) {
                WasteType::query()->whereKey($wasteTypeId)->lockForUpdate()->firstOrFail();
                $movements = InventoryMovement::query()->where('waste_type_id', $wasteTypeId)->lockForUpdate()->get();
                if ($movements->where('reference_type', 'deposit_cancellation')->where('reference_id', $record->id)->isNotEmpty()) {
                    throw new Exception('Mutasi pembatalan stok sudah ada. Periksa transaksi sebelum membatalkan.');
                }
                $sources = $movements->where('reference_type', 'deposit')->where('reference_id', $record->id);
                $quantity = BigDecimal::of(0);
                $cost = BigDecimal::of(0);
                foreach ($sources as $source) {
                    if ($source->movement_type !== 'in' || BigDecimal::of($source->quantity)->isLessThanOrEqualTo(0)) {
                        throw new Exception('Mutasi persediaan asal tidak valid.');
                    }
                    $quantity = $quantity->plus($source->quantity);
                    $sourceCost = BigDecimal::of($source->total_cost);
                    $corrections = $movements->whereIn('reference_type', ['cost_reconciliation', 'cost_reconciliation_reversal'])
                        ->where('reference_id', $source->id);
                    if ($corrections->isNotEmpty()) {
                        $reversals = $corrections->where('reference_type', 'cost_reconciliation_reversal');
                        $replacements = $corrections->where('reference_type', 'cost_reconciliation');
                        if ($reversals->count() !== 1 || $replacements->count() !== 1) {
                            throw new Exception('Koreksi biaya asal belum lengkap. Pembatalan tidak diterapkan.');
                        }
                        $reversal = $reversals->first();
                        $replacement = $replacements->first();
                        if ($reversal->movement_type !== 'out' || $replacement->movement_type !== 'in'
                            || ! BigDecimal::of($reversal->total_cost)->isEqualTo($sourceCost)
                            || ! BigDecimal::of($reversal->quantity)->isEqualTo($replacement->quantity)
                            || (! BigDecimal::of($reversal->quantity)->isZero() && ! BigDecimal::of($reversal->quantity)->isEqualTo($source->quantity))) {
                            throw new Exception('Koreksi biaya asal tidak cocok. Pembatalan tidak diterapkan.');
                        }
                        $sourceCost = BigDecimal::of($replacement->total_cost);
                    }
                    $cost = $cost->plus($sourceCost);
                }
                $itemQuantity = BigDecimal::of(0);
                foreach ($group as $item) {
                    $itemQuantity = $itemQuantity->plus($item->weight);
                }
                if ($sources->count() !== $group->count() || $quantity->isLessThanOrEqualTo(0) || ! $quantity->isEqualTo($itemQuantity)) {
                    throw new Exception('Jumlah stok asal tidak sesuai dengan rincian setoran.');
                }
                $stock = BigDecimal::of(0);
                $value = BigDecimal::of(0);
                foreach ($movements as $movement) {
                    if (! in_array($movement->movement_type, ['in', 'out'], true)
                        || BigDecimal::of($movement->quantity)->isLessThan(0) || BigDecimal::of($movement->total_cost)->isLessThan(0)
                        || $movement->transaction_date->toDateString() > now()->toDateString()) {
                        throw new Exception('Riwayat persediaan tidak valid untuk pembatalan hari ini.');
                    }
                    $stock = $movement->movement_type === 'in' ? $stock->plus($movement->quantity) : $stock->minus($movement->quantity);
                    $value = $movement->movement_type === 'in' ? $value->plus($movement->total_cost) : $value->minus($movement->total_cost);
                }
                if ($stock->isLessThan($quantity)) {
                    throw new Exception('Setoran tidak dapat dibatalkan karena stok tidak mencukupi.');
                }
                if ($value->isLessThan($cost) || ($stock->isEqualTo($quantity) && ! $value->isEqualTo($cost))) {
                    throw new Exception('Pembatalan membuat nilai persediaan tidak seimbang. Periksa transaksi terkait.');
                }
                $plans[] = ['waste_type_id' => $wasteTypeId, 'quantity' => (string) $quantity->toScale(3),
                    'unit_cost' => (string) $cost->dividedBy($quantity, 2, RoundingMode::HalfUp), 'total_cost' => (string) $cost->toScale(2)];
            }
            BalanceMutation::create([
                'customer_id' => $record->customer_id, 'type' => 'debit', 'amount' => $credits->first()->amount,
                'reference_type' => 'deposit_cancellation', 'reference_id' => $record->id,
                'transaction_date' => now()->toDateString(), 'description' => 'Pembatalan setoran nasabah '.$record->deposit_number.' | '.$reason,
            ]);
            foreach ($plans as $plan) {
                InventoryMovement::create([...$plan, 'movement_type' => 'out',
                    'reference_type' => 'deposit_cancellation', 'reference_id' => $record->id,
                    'transaction_date' => now()->toDateString(), 'description' => 'Pembatalan setoran nasabah '.$record->deposit_number.' | '.$reason]);
            }

            /**
             * =========================================================
             * REVERSAL JURNAL SETORAN
             * =========================================================
             *
             * Jurnal asal:
             * Debit  Persediaan Sampah
             * Kredit Tabungan Nasabah
             *
             * Reversal:
             * Debit  Tabungan Nasabah
             * Kredit Persediaan Sampah
             */
            $originalJournal = JournalEntry::query()
                ->where('reference_type', 'deposit')
                ->where('reference_id', $record->id)
                ->lockForUpdate()
                ->first();

            /**
             * Transaksi lama sebelum Fase 8 mungkin belum mempunyai jurnal.
             * Untuk kompatibilitas, pembatalan transaksi lama tetap
             * diperbolehkan. Jurnal historis akan kita tangani terpisah
             * melalui proses backfill/migrasi akuntansi.
             */
            if ($originalJournal !== null) {
                app(JournalService::class)->reverse(
                    journalEntry: $originalJournal,
                    transactionDate: now()->toDateString(),
                    referenceType: 'deposit_cancellation',
                    referenceId: (int) $record->id,
                    referenceNumber: 'REV-'.$record->deposit_number,
                    description: 'Pembatalan setoran nasabah '
                        .$record->deposit_number,
                    userId: $userId
                );
            }
            $record->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ])->save();
        }, attempts: 3);
        $deposit->refresh();
    }
}
