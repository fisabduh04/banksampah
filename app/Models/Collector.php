<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collector extends Model
{
    protected $fillable = [
        'code',
        'name',
        'contact_person',
        'phone',
        'address',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Daftar transaksi penjualan kepada pengepul ini.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
