<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'waste_type_id',
        'movement_type',
        'quantity',

        // Nilai biaya persediaan.
        'unit_cost',
        'total_cost',

        'reference_type',
        'reference_id',
        'transaction_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',

            // Jangan gunakan float sebagai cast permanen
            // untuk angka keuangan.
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',

            'transaction_date' => 'date',
        ];
    }

    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }
}
