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
        'reference_type',
        'reference_id',
        'transaction_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'transaction_date' => 'date',
        ];
    }

    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }
}
