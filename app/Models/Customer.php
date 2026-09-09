<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'customer_code',
        'name',
        'phone_number',
        'address',
        'is_active',
    ];

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function balanceMutations(): HasMany
    {
        return $this->hasMany(BalanceMutation::class);
    }

    public function getBalanceAttribute(): float
    {
        $credit = $this->balanceMutations()
            ->where('type', 'credit')
            ->sum('amount');

        $debit = $this->balanceMutations()
            ->where('type', 'debit')
            ->sum('amount');

        return (float) $credit - (float) $debit;
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }
}
