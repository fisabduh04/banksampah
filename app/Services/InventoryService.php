<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\WasteType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        $balance = $this->balanceForIssue($wasteTypeId, $quantity, $transactionDate);

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

    /** @return array{quantity: float, value: float, average_cost: float} */
    private function balanceForIssue(int $wasteTypeId, float $quantity, string $transactionDate): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Pengeluaran persediaan wajib dilakukan dalam transaksi database.');
        }
        if (Validator::make(['date' => $transactionDate], ['date' => ['required', 'date_format:Y-m-d']])->fails()
            || $transactionDate > now()->toDateString()) {
            throw new RuntimeException('Tanggal penjualan wajib valid dan tidak boleh melewati hari ini. Gunakan tanggal penyerahan barang sesuai bukti transaksi.');
        }
        if ($quantity <= 0) {
            throw new RuntimeException('Jumlah persediaan yang akan dikeluarkan harus lebih dari nol.');
        }
        WasteType::query()->whereKey($wasteTypeId)->lockForUpdate()->firstOrFail();
        $movements = InventoryMovement::query()->where('waste_type_id', $wasteTypeId)
            ->orderBy('transaction_date')->orderBy('id')->lockForUpdate()->get();
        $stock = BigDecimal::of(0);
        $value = BigDecimal::of(0);
        foreach ($movements as $movement) {
            if (! in_array($movement->movement_type, ['in', 'out'], true)
                || BigDecimal::of($movement->quantity)->isLessThan(0)
                || BigDecimal::of($movement->total_cost)->isLessThan(0)) {
                throw new RuntimeException('Riwayat persediaan tidak valid. Periksa transaksi sumber.');
            }
            if ($movement->transaction_date->toDateString() > $transactionDate) {
                if ($movement->movement_type !== 'in' || $movement->reference_type !== 'deposit') {
                    throw new RuntimeException('Tanggal penjualan mendahului pengeluaran atau koreksi persediaan yang sudah dibukukan. Periksa dampak HPP melalui koreksi terkontrol; jangan mengubah tanggal kejadian hanya agar lolos.');
                }

                continue;
            }
            $stock = $movement->movement_type === 'in' ? $stock->plus($movement->quantity) : $stock->minus($movement->quantity);
            $value = $movement->movement_type === 'in' ? $value->plus($movement->total_cost) : $value->minus($movement->total_cost);
        }
        if ($stock->isLessThan((string) $quantity)) {
            throw new RuntimeException('Stok tidak mencukupi pada tanggal penjualan. Stok yang masuk setelah tanggal tersebut tidak dapat digunakan. Periksa jenis sampah, berat, tanggal penjualan, dan setoran yang sudah dibukukan. Jika ada setoran yang terlambat dicatat, minta administrator memeriksa urutan transaksi.');
        }
        if ($value->isLessThan(0)) {
            throw new RuntimeException('Nilai persediaan pada tanggal penjualan negatif. Periksa biaya sumber.');
        }

        return ['quantity' => $stock->toFloat(), 'value' => $value->toFloat(),
            'average_cost' => $value->dividedBy($stock, 2, RoundingMode::HalfUp)->toFloat()];
    }
}
