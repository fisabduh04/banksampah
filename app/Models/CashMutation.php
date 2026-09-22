<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMutation extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    protected $fillable = [
        'cash_account_id',
        'counter_account_id',
        'transaction_date',
        'mutation_type',
        'amount',
        'reference_type',
        'reference_id',
        'reference_number',
        'description',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Akun Kas/Bank pemilik mutasi.
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /**
     * Pengguna yang mencatat atau memicu mutasi.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function isIncoming(): bool
    {
        return $this->mutation_type === self::TYPE_IN;
    }

    public function isOutgoing(): bool
    {
        return $this->mutation_type === self::TYPE_OUT;
    }

    /**
     * Akun akuntansi lawan untuk transaksi manual.
     */
    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(
            Account::class,
            'counter_account_id'
        );
    }
}
