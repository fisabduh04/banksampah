<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const COST_CORRECTIONS = ['cost_reconciliation', 'cost_reconciliation_reversal'];

    public const EFFECTIVE_DATE_SQL = "CASE WHEN inventory_movements.reference_type IN ('cost_reconciliation', 'cost_reconciliation_reversal') THEN (SELECT DATE(original.transaction_date) FROM inventory_movements AS original WHERE original.id = inventory_movements.reference_id) ELSE DATE(inventory_movements.transaction_date) END";

    public const EFFECTIVE_SEQUENCE_SQL = "CASE WHEN inventory_movements.reference_type IN ('cost_reconciliation', 'cost_reconciliation_reversal') THEN inventory_movements.reference_id ELSE inventory_movements.id END";

    public function isCostCorrection(): bool
    {
        return in_array($this->reference_type, self::COST_CORRECTIONS, true);
    }

    public function scopePhysical(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query->whereNull('reference_type')->orWhereNotIn('reference_type', self::COST_CORRECTIONS));
    }

    /** Laporan historis menyajikan kembali biaya pada tanggal sumber; waktu pencatatan tetap terpisah. */
    public function scopeEffectiveThrough(Builder $query, string $date): Builder
    {
        return $query->whereRaw('('.self::EFFECTIVE_DATE_SQL.') <= ?', [$date]);
    }

    public function scopeWithStockReport(Builder $query): Builder
    {
        return $query->addSelect('inventory_movements.*')
            ->selectRaw('('.self::EFFECTIVE_DATE_SQL.') AS effective_date')
            ->addSelect(['running_quantity' => self::query()->from('inventory_movements as ledger')
                ->selectRaw("COALESCE(SUM(CASE WHEN ledger.movement_type = 'in' THEN ledger.quantity ELSE -ledger.quantity END), 0)")
                ->where(fn (Builder $query): Builder => $query->whereNull('ledger.reference_type')->orWhereNotIn('ledger.reference_type', self::COST_CORRECTIONS))
                ->whereColumn('ledger.waste_type_id', 'inventory_movements.waste_type_id')
                ->whereRaw('(DATE(ledger.transaction_date) < ('.self::EFFECTIVE_DATE_SQL.') OR (DATE(ledger.transaction_date) = ('.self::EFFECTIVE_DATE_SQL.') AND ledger.id <= ('.self::EFFECTIVE_SEQUENCE_SQL.')))'),
            ]);
    }

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

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            throw new \UnexpectedValueException('Riwayat ledger tidak dapat diubah. Gunakan mutasi koreksi.');
        });
        static::deleting(function (self $record): void {
            throw new \UnexpectedValueException('Riwayat ledger tidak dapat dihapus. Gunakan mutasi pembalik.');
        });
    }

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
