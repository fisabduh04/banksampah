<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositItem extends Model
{
    protected $fillable = [
        'deposit_id',
        'waste_type_id',
        'weight',
        'price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function wasteType(): BelongsTo
    {
        return $this->belongsTo(WasteType::class);
    }
}
