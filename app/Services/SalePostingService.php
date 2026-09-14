<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\WasteType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class SalePostingService
{
    /**
     * InventoryService menjadi satu pintu
     * untuk membaca, mengunci, dan mengurangi persediaan.
     */
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly FinancialControlService $controls,
    ) {}

    /**
     * Membukukan transaksi penjualan ke pengepul.
     *
     * Seluruh proses dijalankan dalam satu transaksi database.
     * Jika satu proses gagal, seluruh perubahan di-rollback.
     */
    public function post(
        Sale $sale,
        int $userId
    ): void {
        $this->controls->authorize('record', $userId);
        DB::transaction(function () use ($sale, $userId): void {
            $this->controls->ensureOpen(now()->toDateString());

            /*
             * =========================================================
             * 1. KUNCI HEADER PENJUALAN
             * =========================================================
             *
             * Mencegah transaksi yang sama diposting
             * oleh dua proses secara bersamaan.
             */
            $lockedSale = Sale::query()
                ->whereKey($sale->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->controls->ensureOpen($lockedSale->transaction_date->toDateString());

            /*
             * =========================================================
             * 2. VALIDASI STATUS
             * =========================================================
             */
            if ($lockedSale->status !== Sale::STATUS_DRAFT) {
                throw new UnexpectedValueException(
                    'Hanya transaksi Draft yang dapat diposting.'
                );
            }

            /*
             * =========================================================
             * 3. VALIDASI PENGEPUL
             * =========================================================
             */
            $lockedSale->setRelation('collector', $lockedSale->collector()->lockForUpdate()->first());

            if (! $lockedSale->collector) {
                throw new UnexpectedValueException(
                    'Pengepul pada transaksi tidak ditemukan.'
                );
            }

            if (! $lockedSale->collector->is_active) {
                throw new UnexpectedValueException(
                    'Pengepul pada transaksi sudah tidak aktif.'
                );
            }

            /*
             * =========================================================
             * 4. KUNCI DETAIL PENJUALAN
             * =========================================================
             *
             * Detail dikunci agar berat/harga tidak berubah
             * ketika proses posting sedang berlangsung.
             *
             * Urutan berdasarkan waste_type_id juga membantu
             * mengurangi risiko deadlock.
             */
            $items = SaleItem::query()
                ->with('wasteType')
                ->where('sale_id', $lockedSale->id)
                ->orderBy('waste_type_id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new UnexpectedValueException(
                    'Transaksi belum memiliki rincian sampah.'
                );
            }

            /*
             * =========================================================
             * 5. CEGAH POSTING GANDA
             * =========================================================
             *
             * Jika mutasi dengan reference sale sudah ada,
             * transaksi tidak boleh diproses kembali.
             */
            $existingMovement = InventoryMovement::query()
                ->where('reference_type', 'sale')
                ->where('reference_id', $lockedSale->id)
                ->exists();

            if ($existingMovement) {
                throw new UnexpectedValueException(
                    'Mutasi persediaan transaksi ini sudah pernah dibuat.'
                );
            }

            /*
             * =========================================================
             * 6. VALIDASI SETIAP ITEM
             * =========================================================
             */
            foreach ($items as $item) {
                $item->setRelation('wasteType', WasteType::query()->whereKey($item->waste_type_id)->lockForUpdate()->first());
                if (! $item->wasteType) {
                    throw new UnexpectedValueException(
                        'Terdapat jenis sampah yang tidak ditemukan.'
                    );
                }

                if (! $item->wasteType->is_active) {
                    throw new UnexpectedValueException(
                        'Jenis sampah "'
                        .$item->wasteType->name
                        .'" sudah tidak aktif.'
                    );
                }

                $weight = BigDecimal::of($item->weight);
                $price = BigDecimal::of($item->price);
                $subtotal = BigDecimal::of($item->subtotal);

                if ($weight->isLessThanOrEqualTo(0)) {
                    throw new UnexpectedValueException(
                        'Berat '
                        .$item->wasteType->name
                        .' harus lebih dari nol.'
                    );
                }

                if ($price->isLessThanOrEqualTo(0)) {
                    throw new UnexpectedValueException(
                        'Harga jual '
                        .$item->wasteType->name
                        .' harus lebih dari nol.'
                    );
                }

                /*
                 * Hitung ulang subtotal sebagai pemeriksaan.
                 */
                $expectedSubtotal = $weight->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
                if (! $subtotal->isEqualTo($expectedSubtotal)) {
                    throw new UnexpectedValueException(
                        'Subtotal '
                        .$item->wasteType->name
                        .' tidak sesuai dengan berat dan harga jual.'
                    );
                }
            }

            /*
             * =========================================================
             * 7. HITUNG ULANG TOTAL TRANSAKSI
             * =========================================================
             */
            $totalWeight = BigDecimal::of(0);
            $totalAmount = BigDecimal::of(0);
            $totalCost = BigDecimal::of(0);
            foreach ($items as $item) {
                $totalWeight = $totalWeight->plus($item->weight);
                $totalAmount = $totalAmount->plus($item->subtotal);
            }

            foreach ($items as $item) {
                /*
                 * InventoryService akan:
                 *
                 * - mengunci waste_type;
                 * - mengecek stok;
                 * - menghitung biaya rata-rata;
                 * - membuat InventoryMovement tipe OUT.
                 */
                $movement = $this->inventoryService->issue(
                    wasteTypeId: (int) $item->waste_type_id,
                    quantity: $item->weight,
                    referenceType: 'sale',
                    referenceId: (int) $lockedSale->id,
                    transactionDate: $lockedSale
                        ->transaction_date
                        ->toDateString(),
                    description: 'Penjualan ke pengepul '
                        .$lockedSale->sale_number
                );

                /*
                 * HPP resmi diambil dari nilai persediaan
                 * yang dikeluarkan oleh InventoryService.
                 */
                $costPrice = $movement->unit_cost;
                $costTotal = $movement->total_cost;

                /*
                 * Laba kotor per item.
                 *
                 * Penjualan - HPP
                 */
                $grossProfit = (string) BigDecimal::of($item->subtotal)->minus($costTotal)->toScale(2);

                /*
                 * Simpan snapshot HPP pada SaleItem.
                 *
                 * Nilai ini tidak ikut berubah apabila
                 * harga persediaan berubah pada masa depan.
                 */
                $item->update([
                    'cost_price' => $costPrice,
                    'cost_total' => $costTotal,
                    'gross_profit' => $grossProfit,
                ]);

                $totalCost = $totalCost->plus($costTotal);
            }

            /*
             * =========================================================
             * 9. HITUNG LABA KOTOR TRANSAKSI
             * =========================================================
             */
            $grossProfit = (string) $totalAmount->minus($totalCost)->toScale(2);

            /*
             * =========================================================
             * 10. FINALISASI TRANSAKSI
             * =========================================================
             */
            $lockedSale->update([
                'total_weight' => (string) $totalWeight->toScale(3),
                'total_amount' => (string) $totalAmount->toScale(2),

                'total_cost' => (string) $totalCost,
                'gross_profit' => $grossProfit,

                'status' => Sale::STATUS_POSTED,

                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            /*
             * Jika terjadi deadlock database,
             * Laravel diberi kesempatan mencoba ulang.
             */
        }, attempts: 3);

        /*
         * Segarkan object Sale yang dikirim ke service
         * agar status dan total terbarunya tersedia.
         */
        $sale->refresh();
    }

    /**
     * Membatalkan transaksi penjualan yang sudah diposting.
     *
     * Pembatalan tidak menghapus mutasi persediaan lama.
     * Sistem membuat mutasi IN sebagai reversal agar
     * histori transaksi tetap dapat diaudit.
     */
    public function cancel(
        Sale $sale,
        int $userId,
        string $reason
    ): void {
        $this->controls->authorize('approve', $userId);
        DB::transaction(function () use (
            $sale,
            $userId,
            $reason
        ): void {
            $this->controls->ensureOpen(now()->toDateString());

            /*
             * =========================================================
             * 1. VALIDASI ALASAN PEMBATALAN
             * =========================================================
             */
            $reason = trim($reason);

            if ($reason === '') {
                throw new UnexpectedValueException(
                    'Alasan pembatalan wajib diisi.'
                );
            }

            /*
             * =========================================================
             * 2. KUNCI TRANSAKSI PENJUALAN
             * =========================================================
             */
            $lockedSale = Sale::query()
                ->whereKey($sale->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->controls->ensureOpen($lockedSale->transaction_date->toDateString());

            /*
             * Hanya transaksi Posted yang boleh dibatalkan.
             */
            if ($lockedSale->status !== Sale::STATUS_POSTED) {
                throw new UnexpectedValueException(
                    'Hanya transaksi yang sudah diposting yang dapat dibatalkan.'
                );
            }

            /*
 * Penjualan tidak boleh dibatalkan jika masih mempunyai
 * pembayaran pengepul yang aktif.
 *
 * Pembayaran harus dibatalkan terlebih dahulu.
 */
            $activePayments = SalePayment::query()
                ->where('sale_id', $lockedSale->id)
                ->where(
                    'status',
                    SalePayment::STATUS_POSTED
                )
                ->lockForUpdate()->get(['amount']);
            $activePaymentAmount = BigDecimal::of(0);
            foreach ($activePayments as $payment) {
                $activePaymentAmount = $activePaymentAmount->plus($payment->amount);
            }

            if ($activePayments->isNotEmpty()) {
                throw new UnexpectedValueException(
                    'Penjualan masih memiliki pembayaran aktif sebesar Rp '
                    .number_format(
                        (float) (string) $activePaymentAmount,
                        0,
                        ',',
                        '.'
                    )
                    .'. Batalkan pembayaran terlebih dahulu sebelum '
                    .'membatalkan transaksi penjualan.'
                );
            }

            /*
             * =========================================================
             * 3. CEGAH PEMBATALAN GANDA
             * =========================================================
             */
            $existingReversal = InventoryMovement::query()
                ->where(
                    'reference_type',
                    'sale_cancellation'
                )
                ->where(
                    'reference_id',
                    $lockedSale->id
                )
                ->exists();

            if ($existingReversal) {
                throw new UnexpectedValueException(
                    'Pembatalan penjualan ini sudah pernah diproses.'
                );
            }

            /*
             * =========================================================
             * 4. AMBIL DAN KUNCI DETAIL PENJUALAN
             * =========================================================
             */
            $items = SaleItem::query()
                ->with('wasteType')
                ->where(
                    'sale_id',
                    $lockedSale->id
                )
                ->orderBy('waste_type_id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new UnexpectedValueException(
                    'Rincian transaksi penjualan tidak ditemukan.'
                );
            }

            /*
             * =========================================================
             * 5. KEMBALIKAN PERSEDIAAN
             * =========================================================
             *
             * Gunakan HPP dari transaksi asal.
             *
             * Jangan menghitung dengan harga pasar sekarang
             * atau biaya rata-rata sekarang.
             */
            foreach ($items as $item) {
                $quantity = $item->weight;
                $unitCost = $item->cost_price;
                $totalCost = $item->cost_total;

                if (BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                    throw new UnexpectedValueException(
                        'Berat rincian penjualan tidak valid.'
                    );
                }

                if (BigDecimal::of($totalCost)->isLessThan(0)) {
                    throw new UnexpectedValueException(
                        'Nilai HPP transaksi tidak valid.'
                    );
                }

                /*
                 * Kunci WasteType yang bersangkutan agar reversal
                 * tidak bertabrakan dengan proses stok lain.
                 */
                WasteType::query()
                    ->whereKey($item->waste_type_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Buat mutasi pembalik.
                 *
                 * Sale asli:
                 * movement_type = out
                 *
                 * Pembatalan:
                 * movement_type = in
                 */
                InventoryMovement::create([
                    'waste_type_id' => $item->waste_type_id,

                    'movement_type' => 'in',

                    'quantity' => $quantity,

                    /*
                     * Kembalikan nilai persediaan berdasarkan
                     * HPP transaksi penjualan asal.
                     */
                    'unit_cost' => $unitCost,
                    'total_cost' => (string) $totalCost,

                    'reference_type' => 'sale_cancellation',

                    'reference_id' => $lockedSale->id,

                    /*
                     * Pembatalan dicatat pada tanggal pembatalan,
                     * bukan mengubah tanggal transaksi asli.
                     */
                    'transaction_date' => now()->toDateString(),

                    'description' => 'Pembatalan penjualan '
                        .$lockedSale->sale_number,
                ]);
            }

            /*
             * =========================================================
             * 6. UBAH STATUS TRANSAKSI
             * =========================================================
             *
             * Nilai penjualan dan HPP asli TIDAK dihapus.
             * Semuanya tetap tersedia sebagai histori.
             */
            $lockedSale->update([
                'status' => Sale::STATUS_CANCELLED,

                'cancelled_at' => now(),
                'cancelled_by' => $userId,

                'cancellation_reason' => $reason,
            ]);

        }, attempts: 3);

        /*
         * Segarkan object Sale setelah transaksi selesai.
         */
        $sale->refresh();
    }
}
