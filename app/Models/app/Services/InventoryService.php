<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\WasteType;
use RuntimeException;

class InventoryService
{
    /**
     * Mengambil ringkasan persediaan satu jenis sampah.
     *
     * Hasil:
     * - quantity     = stok tersedia
     * - value        = nilai persediaan
     * - average_cost = biaya rata-rata per kg
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
         * Jika stok sudah nol, nilai persediaan juga
         * diperlakukan nol untuk menghindari pembagian nol.
         */
        if (abs($quantity) < 0.0005) {
            $quantity = 0.000;

            /*
             * Nilai yang sangat kecil akibat pembulatan
             * dapat dianggap nol.
             */
            if (abs($value) < 0.01) {
                $value = 0.00;
            }
        }

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
     * Mengunci jenis sampah sebelum membaca saldo persediaannya.
     *
     * Method ini harus dipanggil di dalam DB::transaction().
     *
     * Kita mengunci record waste_types karena tabel ledger
     * tidak mempunyai satu baris saldo khusus yang dapat dikunci.
     */
    public function getLockedBalance(int $wasteTypeId): array
    {
        WasteType::query()
            ->whereKey($wasteTypeId)
            ->lockForUpdate()
            ->firstOrFail();

        return $this->getBalance($wasteTypeId);
    }

    /**
     * Memastikan stok suatu jenis sampah mencukupi.
     *
     * Method ini juga mengembalikan saldo agar caller
     * tidak perlu melakukan query yang sama lagi.
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
         * Toleransi 0,0005 digunakan karena quantity
         * disimpan dengan tiga angka desimal.
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
     * Mencatat persediaan keluar menggunakan metode
     * biaya rata-rata bergerak.
     *
     * Method ini harus dijalankan dalam transaction
     * milik proses bisnis, misalnya SalePostingService.
     */
    public function issue(
        int $wasteTypeId,
        float $quantity,
        string $referenceType,
        int $referenceId,
        string $transactionDate,
        string $description
    ): InventoryMovement {
        $quantity = round($quantity, 3);

        /*
         * Kunci stok dan periksa ketersediaan.
         */
        $balance = $this->ensureAvailable(
            $wasteTypeId,
            $quantity
        );

        $stockQuantity = (float) $balance['quantity'];
        $stockValue = (float) $balance['value'];
        $averageCost = (float) $balance['average_cost'];

        /*
         * Jika seluruh stok dikeluarkan, gunakan seluruh
         * nilai persediaan yang tersisa.
         *
         * Ini mencegah tersisanya beberapa rupiah akibat
         * pembulatan biaya rata-rata.
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
             * Proteksi tambahan agar biaya keluar tidak
             * melampaui nilai persediaan yang tersedia.
             */
            $totalCost = min(
                $totalCost,
                $stockValue
            );
        }

        /*
         * Biaya per unit dicatat sebagai snapshot.
         *
         * Nilai total_cost tetap menjadi nilai HPP resmi
         * karena sudah mempertimbangkan pembulatan.
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
