<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\WasteType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalePostingService
{
    /**
     * InventoryService menjadi satu pintu
     * untuk membaca, mengunci, dan mengurangi persediaan.
     */
    public function __construct(
        private readonly InventoryService $inventoryService
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
        DB::transaction(function () use ($sale, $userId): void {

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

            /*
             * =========================================================
             * 2. VALIDASI STATUS
             * =========================================================
             */
            if ($lockedSale->status !== Sale::STATUS_DRAFT) {
                throw new RuntimeException(
                    'Hanya transaksi Draft yang dapat diposting.'
                );
            }

            /*
             * =========================================================
             * 3. VALIDASI PENGEPUL
             * =========================================================
             */
            $lockedSale->load('collector');

            if (! $lockedSale->collector) {
                throw new RuntimeException(
                    'Pengepul pada transaksi tidak ditemukan.'
                );
            }

            if (! $lockedSale->collector->is_active) {
                throw new RuntimeException(
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
                throw new RuntimeException(
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
                throw new RuntimeException(
                    'Mutasi persediaan transaksi ini sudah pernah dibuat.'
                );
            }

            /*
             * =========================================================
             * 6. VALIDASI SETIAP ITEM
             * =========================================================
             */
            foreach ($items as $item) {
                if (! $item->wasteType) {
                    throw new RuntimeException(
                        'Terdapat jenis sampah yang tidak ditemukan.'
                    );
                }

                if (! $item->wasteType->is_active) {
                    throw new RuntimeException(
                        'Jenis sampah "'
                        .$item->wasteType->name
                        .'" sudah tidak aktif.'
                    );
                }

                $weight = (float) $item->weight;
                $price = (float) $item->price;
                $subtotal = (float) $item->subtotal;

                if ($weight <= 0) {
                    throw new RuntimeException(
                        'Berat '
                        .$item->wasteType->name
                        .' harus lebih dari nol.'
                    );
                }

                if ($price <= 0) {
                    throw new RuntimeException(
                        'Harga jual '
                        .$item->wasteType->name
                        .' harus lebih dari nol.'
                    );
                }

                /*
                 * Hitung ulang subtotal sebagai pemeriksaan.
                 */
                $expectedSubtotal = round(
                    $weight * $price,
                    2
                );

                if (
                    abs(
                        $subtotal - $expectedSubtotal
                    ) > 0.01
                ) {
                    throw new RuntimeException(
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
            $totalWeight = round(
                $items->sum(
                    fn (SaleItem $item): float => (float) $item->weight
                ),
                3
            );

            $totalAmount = round(
                $items->sum(
                    fn (SaleItem $item): float => (float) $item->subtotal
                ),
                2
            );

            /*
             * Angka header harus sama dengan rincian.
             */
            if (
                abs(
                    (float) $lockedSale->total_weight
                    - $totalWeight
                ) > 0.001
            ) {
                throw new RuntimeException(
                    'Total berat penjualan tidak sesuai dengan rincian.'
                );
            }

            if (
                abs(
                    (float) $lockedSale->total_amount
                    - $totalAmount
                ) > 0.01
            ) {
                throw new RuntimeException(
                    'Total nilai penjualan tidak sesuai dengan rincian.'
                );
            }

            /*
             * =========================================================
             * 8. KELUARKAN PERSEDIAAN DAN HITUNG HPP
             * =========================================================
             */
            $totalCost = 0.00;

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
                    quantity: (float) $item->weight,
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
                $costPrice = (float) $movement->unit_cost;
                $costTotal = (float) $movement->total_cost;

                /*
                 * Laba kotor per item.
                 *
                 * Penjualan - HPP
                 */
                $grossProfit = round(
                    (float) $item->subtotal
                    - $costTotal,
                    2
                );

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

                $totalCost += $costTotal;
            }

            $totalCost = round(
                $totalCost,
                2
            );

            /*
             * =========================================================
             * 9. HITUNG LABA KOTOR TRANSAKSI
             * =========================================================
             */
            $grossProfit = round(
                $totalAmount - $totalCost,
                2
            );

            /*
             * =========================================================
             * 10. FINALISASI TRANSAKSI
             * =========================================================
             */
            $lockedSale->update([
                'total_weight' => $totalWeight,
                'total_amount' => $totalAmount,

                'total_cost' => $totalCost,
                'gross_profit' => $grossProfit,

                'status' => Sale::STATUS_POSTED,

                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            /**
             * =========================================================
             * JURNAL OTOMATIS PENJUALAN
             * =========================================================
             *
             * 1. Mengakui penjualan:
             *    Debit  Piutang Pengepul
             *    Kredit Pendapatan Penjualan
             *
             * 2. Mengakui HPP:
             *    Debit  Harga Pokok Penjualan
             *    Kredit Persediaan Sampah
             */
            $receivableAccount = Account::query()
                ->where('system_key', 'collector_receivable')
                ->firstOrFail();

            $salesRevenueAccount = Account::query()
                ->where('system_key', 'sales_revenue')
                ->firstOrFail();

            $cogsAccount = Account::query()
                ->where('system_key', 'cogs')
                ->firstOrFail();

            $inventoryAccount = Account::query()
                ->where('system_key', 'inventory')
                ->firstOrFail();

            app(JournalService::class)->post(
                transactionDate: $lockedSale->transaction_date->toDateString(),
                referenceType: 'sale',
                referenceId: (int) $lockedSale->id,
                referenceNumber: $lockedSale->sale_number,
                description: 'Penjualan ke pengepul '.$lockedSale->sale_number,
                userId: $userId,
                lines: [
                    [
                        'account_id' => $receivableAccount->id,
                        'debit' => (string) $totalAmount,
                        'credit' => '0.00',
                        'description' => 'Piutang penjualan ke pengepul',
                    ],
                    [
                        'account_id' => $salesRevenueAccount->id,
                        'debit' => '0.00',
                        'credit' => (string) $totalAmount,
                        'description' => 'Pendapatan penjualan sampah',
                    ],
                    [
                        'account_id' => $cogsAccount->id,
                        'debit' => (string) $totalCost,
                        'credit' => '0.00',
                        'description' => 'Harga pokok penjualan',
                    ],
                    [
                        'account_id' => $inventoryAccount->id,
                        'debit' => '0.00',
                        'credit' => (string) $totalCost,
                        'description' => 'Pengurangan persediaan sampah',
                    ],
                ]
            );

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
        string $reason,
        bool $confirmedCorrection = false
    ): void {
        $reason = app(CancellationReason::class)->describe($reason, $userId, $confirmedCorrection);
        DB::transaction(function () use (
            $sale,
            $userId,
            $reason
        ): void {

            /*
             * =========================================================
             * 1. VALIDASI ALASAN PEMBATALAN
             * =========================================================
             */
            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException(
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

            /*
             * Hanya transaksi Posted yang boleh dibatalkan.
             */
            if ($lockedSale->status !== Sale::STATUS_POSTED) {
                throw new RuntimeException(
                    'Hanya transaksi yang sudah diposting yang dapat dibatalkan.'
                );
            }

            if ($lockedSale->transaction_date->toDateString() > now()->toDateString()) {
                throw new RuntimeException('Tanggal pembatalan tidak boleh mendahului penjualan asal.');
            }

            /*
 * Penjualan tidak boleh dibatalkan jika masih mempunyai
 * pembayaran pengepul yang aktif.
 *
 * Pembayaran harus dibatalkan terlebih dahulu.
 */
            $activePaymentAmount = (float) SalePayment::query()
                ->where('sale_id', $lockedSale->id)
                ->where(
                    'status',
                    SalePayment::STATUS_POSTED
                )
                ->sum('amount');

            if ($activePaymentAmount > 0) {
                throw new RuntimeException(
                    'Penjualan masih memiliki pembayaran aktif sebesar Rp '
                    .number_format(
                        $activePaymentAmount,
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
                throw new RuntimeException(
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
                throw new RuntimeException(
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
                $quantity = (float) $item->weight;
                $unitCost = (float) $item->cost_price;
                $totalCost = (float) $item->cost_total;

                if ($quantity <= 0) {
                    throw new RuntimeException(
                        'Berat rincian penjualan tidak valid.'
                    );
                }

                if ($totalCost < 0) {
                    throw new RuntimeException(
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

                $movements = InventoryMovement::query()->where('waste_type_id', $item->waste_type_id)
                    ->lockForUpdate()->get();
                if ($movements->contains(fn (InventoryMovement $movement): bool => $movement->transaction_date->toDateString() > now()->toDateString())) {
                    throw new RuntimeException('Tanggal pembatalan mendahului riwayat persediaan. Periksa tanggal transaksi sumber sebelum koreksi.');
                }

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
                    'total_cost' => $totalCost,

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

            /**
             * =========================================================
             * REVERSAL JURNAL PENJUALAN
             * =========================================================
             */
            $originalJournal = JournalEntry::query()
                ->where('reference_type', 'sale')
                ->where('reference_id', $lockedSale->id)
                ->lockForUpdate()
                ->first();

            /**
             * Penjualan historis sebelum Fase 8 mungkin belum
             * mempunyai jurnal akuntansi.
             */
            if ($originalJournal !== null) {
                app(JournalService::class)->reverse(
                    journalEntry: $originalJournal,
                    transactionDate: now()->toDateString(),
                    referenceType: 'sale_cancellation',
                    referenceId: (int) $lockedSale->id,
                    referenceNumber: 'REV-'.$lockedSale->sale_number,
                    description: 'Pembatalan penjualan '
                        .$lockedSale->sale_number,
                    userId: $userId
                );
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
