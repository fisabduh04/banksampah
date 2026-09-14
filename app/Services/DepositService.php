<?php

namespace App\Services;

use App\Models\BalanceMutation;
use App\Models\Deposit;
use App\Models\InventoryMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class DepositService
{
    public function __construct(private readonly InventoryService $inventoryService, private readonly CustomerBalanceService $balances, private readonly FinancialControlService $controls) {}

    public function post(Deposit $deposit, ?int $userId = null): void
    {
        $userId = $this->controls->authorize('record', $userId ?? auth()->id())->id;
        DB::transaction(function () use ($deposit, $userId): void {
            $this->controls->ensureOpen(now()->toDateString());
            $deposit = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $this->controls->ensureOpen($deposit->transaction_date->toDateString());
            $deposit->setRelation('items', $deposit->items()->orderBy('waste_type_id')->lockForUpdate()->get());

            if ($deposit->status !== 'draft') {
                throw new UnexpectedValueException(
                    'Hanya transaksi yang belum dibukukan yang dapat diposting.'
                );
            }

            if ($deposit->items->isEmpty()) {
                throw new UnexpectedValueException(
                    'Transaksi belum memiliki detail bahan.'
                );
            }

            $totalWeight = BigDecimal::of(0);
            $totalAmount = BigDecimal::of(0);
            foreach ($deposit->items as $item) {
                $weight = BigDecimal::of($item->weight);
                $price = BigDecimal::of($item->price);
                if ($weight->isLessThanOrEqualTo(0) || $price->isLessThanOrEqualTo(0)) {
                    throw new UnexpectedValueException('Berat dan harga bahan harus lebih dari nol.');
                }
                $subtotal = $weight->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
                if (! $subtotal->isEqualTo($item->subtotal)) {
                    throw new UnexpectedValueException('Terdapat subtotal bahan yang tidak sesuai.');
                }
                $totalWeight = $totalWeight->plus($weight);
                $totalAmount = $totalAmount->plus($subtotal);
            }
            if (! $totalWeight->isEqualTo($deposit->total_weight) || ! $totalAmount->isEqualTo($deposit->total_amount)) {
                throw new UnexpectedValueException('Total setoran tidak sesuai dengan rincian transaksi.');
            }
            $this->balances->getLockedBalance($deposit->customer_id);
            if (BalanceMutation::query()->where('reference_type', 'deposit')->where('reference_id', $deposit->id)->exists()) {
                throw new UnexpectedValueException('Mutasi setoran sudah pernah dibuat.');
            }
            $deposit->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $userId])->save();

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
                $this->inventoryService->getLockedBalance((int) $item->waste_type_id);
                $this->inventoryService->ensureChronological((int) $item->waste_type_id, $deposit->transaction_date->toDateString());
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
        }, attempts: 3);

        $deposit->refresh();
    }

    public function cancel(Deposit $deposit, string $reason, ?int $userId = null): void
    {
        $userId = $this->controls->authorize('approve', $userId ?? auth()->id())->id;
        if (trim($reason) === '') {
            throw new UnexpectedValueException('Alasan pembatalan wajib diisi.');
        }
        DB::transaction(function () use ($deposit, $reason, $userId): void {
            $this->controls->ensureOpen(now()->toDateString());
            $deposit = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $this->controls->ensureOpen($deposit->transaction_date->toDateString());
            $deposit->setRelation('items', $deposit->items()->orderBy('waste_type_id')->lockForUpdate()->get());

            if ($deposit->status !== 'posted') {
                throw new UnexpectedValueException(
                    'Hanya transaksi yang telah dibukukan yang dapat dibatalkan.'
                );
            }

            $this->balances->ensureAvailable($deposit->customer_id, $deposit->total_amount);

            $existingReversal = BalanceMutation::query()
                ->where('reference_type', 'deposit_cancellation')
                ->where('reference_id', $deposit->id)
                ->exists();

            if ($existingReversal) {
                throw new UnexpectedValueException(
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
                $balance = $this->inventoryService->ensureAvailable((int) $item->waste_type_id, $item->weight);
                $remainingValue = BigDecimal::of($balance['value'])->minus($item->subtotal);
                $remainingQuantity = BigDecimal::of($balance['quantity'])->minus($item->weight);
                if ($remainingValue->isLessThan(0) || ($remainingQuantity->isEqualTo(0) && ! $remainingValue->isEqualTo(0))) {
                    throw new UnexpectedValueException('Pembatalan setoran membuat nilai persediaan tidak seimbang. Batalkan penjualan terkait terlebih dahulu.');
                }

                InventoryMovement::create([
                    'waste_type_id' => $item->waste_type_id,
                    'movement_type' => 'out',
                    'quantity' => $item->weight,
                    'unit_cost' => $item->price,
                    'total_cost' => $item->subtotal,
                    'reference_type' => 'deposit_cancellation',
                    'reference_id' => $deposit->id,
                    'transaction_date' => now()->toDateString(),
                    'description' => 'Pembatalan setoran nasabah '.$deposit->deposit_number,
                ]);
            }

            $deposit->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId, 'cancellation_reason' => trim($reason)])->save();
        }, attempts: 3);

        $deposit->refresh();
    }
}
