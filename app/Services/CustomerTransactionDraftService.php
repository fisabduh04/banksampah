<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Withdrawal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerTransactionDraftService
{
    public function lockDraft(Deposit|Withdrawal $record): Deposit|Withdrawal
    {
        if (DB::transactionLevel() === 0) {
            throw ValidationException::withMessages(['data.status' => 'Perubahan draft wajib berada dalam transaksi database.']);
        }
        $locked = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'draft') {
            throw ValidationException::withMessages(['data.status' => 'Transaksi final tidak dapat diubah atau dihapus.']);
        }

        return $locked;
    }

    public function recalculate(Deposit $deposit): void
    {
        $record = $this->lockDraft($deposit);
        $weight = BigDecimal::of(0);
        $amount = BigDecimal::of(0);
        foreach ($record->items()->lockForUpdate()->get() as $item) {
            if (BigDecimal::of($item->weight)->isLessThanOrEqualTo(0) || BigDecimal::of($item->price)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['data.items' => 'Berat dan harga harus lebih dari nol.']);
            }
            $subtotal = BigDecimal::of($item->weight)->multipliedBy($item->price)->toScale(2, RoundingMode::HalfUp);
            $item->update(['subtotal' => (string) $subtotal]);
            $weight = $weight->plus($item->weight);
            $amount = $amount->plus($subtotal);
        }
        $record->update(['total_weight' => (string) $weight->toScale(3), 'total_amount' => (string) $amount->toScale(2)]);
    }

    public function delete(Deposit|Withdrawal $record): void
    {
        DB::transaction(fn () => $this->lockDraft($record)->delete(), attempts: 3);
    }
}
