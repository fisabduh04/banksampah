<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'payment_number',
        'idempotency_key',
        'sale_id',
        'payment_date',
        'amount',
        'payment_method',
        'reference_number',
        'status',
        'received_by',
        'notes',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Transaksi penjualan yang dibayar.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Pengguna yang menerima/mencatat pembayaran.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by'
        );
    }

    /**
     * Pengguna yang membatalkan pembayaran.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by'
        );
    }

    /**
     * Cek apakah pembayaran masih aktif/sah.
     */
    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * Cek apakah pembayaran sudah dibatalkan.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
