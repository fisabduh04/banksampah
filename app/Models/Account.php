<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const NORMAL_DEBIT = 'debit';

    public const NORMAL_CREDIT = 'credit';

    protected $fillable = [
        'code',
        'name',
        'account_type',
        'normal_balance',
        'parent_id',
        'is_postable',
        'is_active',
        'system_key',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function accountTypes(): array
    {
        return [
            self::TYPE_ASSET => 'Aset',
            self::TYPE_LIABILITY => 'Liabilitas',
            self::TYPE_EQUITY => 'Ekuitas',
            self::TYPE_REVENUE => 'Pendapatan',
            self::TYPE_EXPENSE => 'Beban',
        ];
    }

    public static function normalBalances(): array
    {
        return [
            self::NORMAL_DEBIT => 'Debit',
            self::NORMAL_CREDIT => 'Kredit',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isPostable(): bool
    {
        return (bool) $this->is_postable;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
