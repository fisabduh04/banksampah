<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'entry_number',
        'transaction_date',
        'reference_type',
        'reference_id',
        'reference_number',
        'description',
        'status',
        'reversal_of_id',
        'posted_at',
        'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * Detail debit-kredit jurnal.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)
            ->orderBy('line_number');
    }

    /**
     * Pengguna yang memposting jurnal.
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'posted_by'
        );
    }

    /**
     * Jurnal asal apabila jurnal ini merupakan reversal.
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'reversal_of_id'
        );
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
