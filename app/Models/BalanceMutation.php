<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceMutation extends Model
{
    protected $fillable = [
        'customer_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'transaction_date',
        'description',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            throw new \UnexpectedValueException('Riwayat ledger tidak dapat diubah. Gunakan mutasi koreksi.');
        });
        static::deleting(function (self $record): void {
            throw new \UnexpectedValueException('Riwayat ledger tidak dapat dihapus. Gunakan mutasi pembalik.');
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
