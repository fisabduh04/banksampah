<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'unit',
        'is_active',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(WasteCategory::class, 'waste_category_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(WastePrice::class);
    }

    public function depositItems(): HasMany
    {
        return $this->hasMany(DepositItem::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function getStockAttribute(): float
    {
        $stockIn = $this->inventoryMovements()
            ->where('movement_type', 'in')
            ->sum('quantity');

        $stockOut = $this->inventoryMovements()
            ->where('movement_type', 'out')
            ->sum('quantity');

        return (float) $stockIn - (float) $stockOut;
    }
}
