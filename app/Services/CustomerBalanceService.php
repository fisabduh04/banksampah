<?php

namespace App\Services;

use App\Models\BalanceMutation;
use App\Models\Customer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class CustomerBalanceService
{
    /** Satu baris nasabah menjadi titik koordinasi seluruh perubahan saldonya. */
    public function getLockedBalance(int $customerId): string
    {
        if (DB::transactionLevel() === 0) {
            throw new UnexpectedValueException('Penguncian saldo wajib berada dalam transaksi database.');
        }
        Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
        $mutations = BalanceMutation::query()->where('customer_id', $customerId)->lockForUpdate()->get();
        $balance = BigDecimal::of(0);
        foreach ($mutations as $mutation) {
            if (! in_array($mutation->type, ['credit', 'debit'], true) || BigDecimal::of($mutation->amount)->isLessThan(0)) {
                throw new UnexpectedValueException('Riwayat saldo tidak valid dan harus direkonsiliasi.');
            }
            $balance = $mutation->type === 'credit' ? $balance->plus($mutation->amount) : $balance->minus($mutation->amount);
        }

        return (string) $balance->toScale(2);
    }

    public function ensureAvailable(int $customerId, string $amount): string
    {
        $balance = $this->getLockedBalance($customerId);
        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0) || BigDecimal::of($amount)->isGreaterThan($balance)) {
            throw new UnexpectedValueException('Saldo nasabah tidak mencukupi. Sisa saldo Rp '.$balance.'.');
        }

        return $balance;
    }
}
