<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'waste_type_id',
        'weight',
        'price',
        'subtotal',
        'cost_price',
        'cost_total',
        'gross_profit',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'gross_profit' => 'decimal:2',
        ];
    }

    /**
     * Header transaksi penjualan.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Jenis sampah yang dijual.
     */
    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }
}
