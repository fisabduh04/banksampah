<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'sale_number',
        'idempotency_key',
        'collector_id',
        'transaction_date',
        'status',
        'total_weight',
        'total_amount',
        'total_cost',
        'gross_profit',
        'notes',
        'posted_at',
        'posted_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'payment_status',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',

            // Decimal dijaga sebagai decimal, bukan float.
            'total_weight' => 'decimal:3',
            'total_amount' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'gross_profit' => 'decimal:2',
        ];
    }

    /**
     * Pengepul pembeli sampah.
     */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(Collector::class);
    }

    /**
     * Rincian jenis sampah yang dijual.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Pengguna yang melakukan posting transaksi.
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Pengguna yang melakukan pembatalan.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Mengecek apakah transaksi masih dapat diedit.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Mengecek apakah transaksi sudah diposting.
     */
    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * Mengecek apakah transaksi sudah dibatalkan.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Riwayat pembayaran dari pengepul.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * Total pembayaran yang masih sah.
     */
    public function getPaidAmountAttribute(): string
    {
        return (string) BigDecimal::of($this->payments()
            ->where('status', SalePayment::STATUS_POSTED)
            ->sum('amount'))->toScale(2);
    }

    /**
     * Sisa piutang pengepul.
     */
    public function getOutstandingAmountAttribute(): string
    {
        $outstanding = BigDecimal::of($this->total_amount)->minus($this->paid_amount);

        return $outstanding->isLessThan(0) ? '0.00' : (string) $outstanding->toScale(2);
    }
}
