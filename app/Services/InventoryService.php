<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\WasteType;
use RuntimeException;

class InventoryService
{
    /**
     * Mengambil saldo persediaan satu jenis sampah.
     *
     * Hasil:
     * quantity     = stok tersedia
     * value        = nilai persediaan
     * average_cost = biaya rata-rata per kg
     */
    public function getBalance(int $wasteTypeId): array
    {
        $summary = InventoryMovement::query()
            ->where('waste_type_id', $wasteTypeId)
            ->selectRaw("
                COALESCE(
                    SUM(
                        CASE
                            WHEN movement_type = 'in'
                                THEN quantity
                            WHEN movement_type = 'out'
                                THEN -quantity
                            ELSE 0
                        END
                    ),
                    0
                ) AS quantity
            ")
            ->selectRaw("
                COALESCE(
                    SUM(
                        CASE
                            WHEN movement_type = 'in'
                                THEN total_cost
                            WHEN movement_type = 'out'
                                THEN -total_cost
                            ELSE 0
                        END
                    ),
                    0
                ) AS value
            ")
            ->first();

        $quantity = round(
            (float) ($summary?->quantity ?? 0),
            3
        );

        $value = round(
            (float) ($summary?->value ?? 0),
            2
        );

        /*
         * Jika stok sangat dekat dengan nol,
         * perlakukan sebagai nol.
         */
        if (abs($quantity) < 0.0005) {
            $quantity = 0.000;

            if (abs($value) < 0.01) {
                $value = 0.00;
            }
        }

        /*
         * Hitung biaya rata-rata persediaan.
         */
        $averageCost = $quantity > 0
            ? round($value / $quantity, 2)
            : 0.00;

        return [
            'quantity' => $quantity,
            'value' => $value,
            'average_cost' => $averageCost,
        ];
    }

    /**
     * Mengunci jenis sampah sebelum membaca stok.
     *
     * Method ini digunakan di dalam DB::transaction().
     */
    public function getLockedBalance(int $wasteTypeId): array
    {
        /*
         * WasteType menjadi titik penguncian transaksi stok.
         */
        WasteType::query()
            ->whereKey($wasteTypeId)
            ->lockForUpdate()
            ->firstOrFail();

        return $this->getBalance($wasteTypeId);
    }

    /**
     * Memastikan stok mencukupi sebelum dikeluarkan.
     */
    public function ensureAvailable(
        int $wasteTypeId,
        float $quantity
    ): array {
        if ($quantity <= 0) {
            throw new RuntimeException(
                'Jumlah persediaan yang akan dikeluarkan harus lebih dari nol.'
            );
        }

        $balance = $this->getLockedBalance(
            $wasteTypeId
        );

        /*
         * Quantity disimpan dengan tiga angka desimal.
         */
        if (
            ($balance['quantity'] + 0.0005)
            < $quantity
        ) {
            throw new RuntimeException(
                sprintf(
                    'Stok tidak mencukupi. Stok tersedia %.3f kg, sedangkan kebutuhan %.3f kg.',
                    $balance['quantity'],
                    $quantity
                )
            );
        }

        return $balance;
    }

    /**
     * Mencatat mutasi persediaan keluar.
     *
     * Menggunakan metode biaya rata-rata bergerak.
     */
    public function issue(
        int $wasteTypeId,
        float $quantity,
        string $referenceType,
        int $referenceId,
        string $transactionDate,
        string $description
    ): InventoryMovement {
        $quantity = round(
            $quantity,
            3
        );

        /*
         * Periksa sekaligus kunci stok.
         */
        $balance = $this->ensureAvailable(
            $wasteTypeId,
            $quantity
        );

        $stockQuantity = (float) $balance['quantity'];
        $stockValue = (float) $balance['value'];
        $averageCost = (float) $balance['average_cost'];

        /*
         * Jika seluruh stok dikeluarkan,
         * keluarkan juga seluruh nilai persediaannya.
         */
        $isFullIssue =
            abs($stockQuantity - $quantity) < 0.0005;

        if ($isFullIssue) {
            $totalCost = round(
                $stockValue,
                2
            );
        } else {
            $totalCost = round(
                $quantity * $averageCost,
                2
            );

            /*
             * Nilai keluar tidak boleh melebihi
             * nilai persediaan yang tersedia.
             */
            $totalCost = min(
                $totalCost,
                $stockValue
            );
        }

        /*
         * Snapshot biaya per unit.
         */
        $unitCost = $quantity > 0
            ? round($totalCost / $quantity, 2)
            : 0.00;

        return InventoryMovement::create([
            'waste_type_id' => $wasteTypeId,
            'movement_type' => 'out',

            'quantity' => $quantity,

            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,

            'reference_type' => $referenceType,
            'reference_id' => $referenceId,

            'transaction_date' => $transactionDate,

            'description' => $description,
        ]);
    }
}
