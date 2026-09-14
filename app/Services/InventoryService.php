<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\WasteType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class InventoryService
{
    /** @return array{quantity: float, value: float, average_cost: float} Angka tampilan, bukan dasar pembukuan. */
    public function getBalance(int $wasteTypeId, ?string $through = null): array
    {
        return array_map(fn (string $value): float => (float) $value, $this->getExactBalance($wasteTypeId, $through));
    }

    /** @return array{quantity: string, value: string, average_cost: string} */
    public function getExactBalance(int $wasteTypeId, ?string $through = null): array
    {
        return $this->summarize(InventoryMovement::query()->where('waste_type_id', $wasteTypeId)
            ->when($through !== null, fn ($query) => $query->effectiveThrough($through))->get());
    }

    /** @return array{quantity: float, value: float, average_cost: float} Untuk kompatibilitas tampilan. */
    public function getLockedBalance(int $wasteTypeId): array
    {
        return array_map(fn (string $value): float => (float) $value, $this->getExactLockedBalance($wasteTypeId));
    }

    /** @return array{quantity: string, value: string, average_cost: string} */
    public function getExactLockedBalance(int $wasteTypeId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new UnexpectedValueException('Penguncian persediaan harus berada dalam transaksi database.');
        }
        WasteType::query()->whereKey($wasteTypeId)->lockForUpdate()->firstOrFail();
        $movements = InventoryMovement::query()->where('waste_type_id', $wasteTypeId)->lockForUpdate()->get();
        $missingCosts = $movements->filter(fn (InventoryMovement $movement): bool => $movement->reference_type === 'deposit'
            && $movement->movement_type === 'in' && BigDecimal::of($movement->quantity)->isGreaterThan(0)
            && BigDecimal::of($movement->total_cost)->isLessThanOrEqualTo(0));
        if ($missingCosts->isNotEmpty()) {
            $reconciledIds = DB::table('inventory_cost_reconciliations')
                ->whereIn('inventory_movement_id', $missingCosts->modelKeys())
                ->whereNotNull('reversal_movement_id')->whereNotNull('replacement_movement_id')
                ->lockForUpdate()->pluck('inventory_movement_id')->all();
            if ($missingCosts->except($reconciledIds)->isNotEmpty()) {
                throw new UnexpectedValueException('Biaya perolehan setoran lama belum lengkap. Rekonsiliasi persediaan terlebih dahulu sebelum memproses transaksi stok.');
            }
        }

        return $this->summarize($movements);
    }

    /** @param Collection<int, InventoryMovement> $movements
     * @return array{quantity: string, value: string, average_cost: string}
     */
    private function summarize(Collection $movements): array
    {
        $quantity = BigDecimal::of(0);
        $value = BigDecimal::of(0);
        foreach ($movements as $movement) {
            if (! in_array($movement->movement_type, ['in', 'out'], true)) {
                throw new UnexpectedValueException('Arah mutasi persediaan tidak valid.');
            }
            $sign = $movement->movement_type === 'in' ? 1 : -1;
            if (! $movement->isCostCorrection()) {
                $quantity = $quantity->plus(BigDecimal::of($movement->quantity)->multipliedBy($sign));
            }
            $value = $value->plus(BigDecimal::of($movement->total_cost)->multipliedBy($sign));
        }

        return ['quantity' => (string) $quantity->toScale(3), 'value' => (string) $value->toScale(2),
            'average_cost' => $quantity->isGreaterThan(0) ? (string) $value->dividedBy($quantity, 2, RoundingMode::HalfUp) : '0.00'];
    }

    /** @return array{quantity: string, value: string, average_cost: string} */
    public function ensureAvailable(int $wasteTypeId, string|int|float $quantity): array
    {
        $quantity = BigDecimal::of((string) $quantity)->toScale(3, RoundingMode::HalfUp);
        if ($quantity->isLessThanOrEqualTo(0)) {
            throw new UnexpectedValueException('Jumlah persediaan yang akan dikeluarkan harus lebih dari nol.');
        }
        $balance = $this->getExactLockedBalance($wasteTypeId);
        if ($quantity->isGreaterThan($balance['quantity'])) {
            throw new UnexpectedValueException('Stok tidak mencukupi. Stok tersedia '.$balance['quantity'].' kg, sedangkan kebutuhan '.$quantity.' kg.');
        }

        return $balance;
    }

    /** Pemostingan mundur sesudah pergerakan fisik terakhir akan memakai rata-rata biaya yang salah. */
    public function ensureChronological(int $wasteTypeId, string $date): void
    {
        if (DB::transactionLevel() === 0) {
            throw new UnexpectedValueException('Pemeriksaan urutan stok wajib berada dalam transaksi database.');
        }
        WasteType::query()->whereKey($wasteTypeId)->lockForUpdate()->firstOrFail();
        $latest = InventoryMovement::query()->where('waste_type_id', $wasteTypeId)->physical()
            ->orderByDesc('transaction_date')->lockForUpdate()->first();
        if ($latest && $date < $latest->transaction_date->toDateString()) {
            throw new UnexpectedValueException('Tanggal transaksi mendahului mutasi stok terakhir. Gunakan tanggal pembukuan yang berurutan agar HPP konsisten.');
        }
    }

    public function issue(int $wasteTypeId, string|int|float $quantity, string $referenceType, int $referenceId, string $transactionDate, string $description): InventoryMovement
    {
        $balance = $this->ensureAvailable($wasteTypeId, $quantity);
        $this->ensureChronological($wasteTypeId, $transactionDate);
        $quantity = BigDecimal::of((string) $quantity)->toScale(3, RoundingMode::HalfUp);
        $stockValue = BigDecimal::of($balance['value']);
        if ($stockValue->isLessThan(0)) {
            throw new UnexpectedValueException('Nilai persediaan negatif. Periksa riwayat persediaan sebelum posting.');
        }
        $totalCost = $stockValue->multipliedBy($quantity)->dividedBy($balance['quantity'], 2, RoundingMode::HalfUp);

        return InventoryMovement::create([
            'waste_type_id' => $wasteTypeId, 'movement_type' => 'out', 'quantity' => (string) $quantity,
            'unit_cost' => (string) $totalCost->dividedBy($quantity, 2, RoundingMode::HalfUp), 'total_cost' => (string) $totalCost,
            'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'transaction_date' => $transactionDate, 'description' => $description,
        ]);
    }
}
