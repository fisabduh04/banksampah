<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalePaymentService
{
    /**
     * Mencatat pembayaran dari pengepul.
     *
     * Pembayaran hanya boleh untuk transaksi penjualan
     * yang sudah diposting dan belum dibatalkan.
     */
    public function recordPayment(
        Sale $sale,
        float $amount,
        string $paymentDate,
        string $paymentMethod,
        ?string $referenceNumber,
        ?string $notes,
        int $userId
    ): SalePayment {
        return DB::transaction(function () use (
            $sale,
            $amount,
            $paymentDate,
            $paymentMethod,
            $referenceNumber,
            $notes,
            $userId
        ): SalePayment {

            /**
             * Kunci transaksi agar tidak terjadi dua pembayaran
             * bersamaan yang menyebabkan kelebihan bayar.
             */
            $lockedSale = Sale::query()
                ->whereKey($sale->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSale->status !== Sale::STATUS_POSTED) {
                throw new RuntimeException(
                    'Pembayaran hanya dapat dicatat untuk penjualan yang sudah diposting.'
                );
            }

            if ($amount <= 0) {
                throw new RuntimeException(
                    'Jumlah pembayaran harus lebih dari nol.'
                );
            }

            /**
             * Hitung total pembayaran sah yang sudah diterima.
             */
            $paidAmount = (float) SalePayment::query()
                ->where('sale_id', $lockedSale->id)
                ->where('status', SalePayment::STATUS_POSTED)
                ->sum('amount');

            $outstandingAmount = round(
                (float) $lockedSale->total_amount - $paidAmount,
                2
            );

            if ($outstandingAmount <= 0) {
                throw new RuntimeException(
                    'Transaksi ini sudah lunas.'
                );
            }

            if ($amount > $outstandingAmount + 0.01) {
                throw new RuntimeException(
                    sprintf(
                        'Pembayaran melebihi sisa piutang. Sisa piutang saat ini Rp %s.',
                        number_format(
                            $outstandingAmount,
                            0,
                            ',',
                            '.'
                        )
                    )
                );
            }

            /**
             * Buat nomor pembayaran sementara.
             * ID final belum tersedia sebelum record dibuat.
             */
            $payment = SalePayment::create([
                'payment_number' => 'TMP-'.uniqid(),

                'sale_id' => $lockedSale->id,
                'payment_date' => $paymentDate,
                'amount' => round($amount, 2),

                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,

                'status' => SalePayment::STATUS_POSTED,

                'received_by' => $userId,

                'notes' => $notes,
            ]);

            /**
             * Nomor pembayaran final.
             *
             * Contoh:
             * BYR-20260910-000001
             */
            $paymentNumber =
                'BYR-'
                .$payment->payment_date->format('Ymd')
                .'-'
                .str_pad(
                    (string) $payment->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                );

            $payment->update([
                'payment_number' => $paymentNumber,
            ]);

            /**
             * Hitung kembali total pembayaran setelah record baru.
             */
            $newPaidAmount = (float) SalePayment::query()
                ->where('sale_id', $lockedSale->id)
                ->where('status', SalePayment::STATUS_POSTED)
                ->sum('amount');

            /**
             * Tentukan status pembayaran penjualan.
             */
            $paymentStatus = match (true) {
                $newPaidAmount <= 0 => 'unpaid',

                $newPaidAmount + 0.01
                    < (float) $lockedSale->total_amount => 'partial',

                default => 'paid',
            };

            $lockedSale->update([
                'payment_status' => $paymentStatus,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Membatalkan pembayaran penjualan.
     *
     * Record pembayaran tidak dihapus agar histori tetap tersedia.
     */
    public function cancelPayment(
        SalePayment $payment,
        string $reason,
        int $userId
    ): void {
        DB::transaction(function () use (
            $payment,
            $reason,
            $userId
        ): void {

            /*
             * =========================================================
             * 1. VALIDASI ALASAN PEMBATALAN
             * =========================================================
             */
            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException(
                    'Alasan pembatalan pembayaran wajib diisi.'
                );
            }

            /*
             * =========================================================
             * 2. KUNCI PEMBAYARAN
             * =========================================================
             */
            $lockedPayment = SalePayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedPayment->status
                !== SalePayment::STATUS_POSTED
            ) {
                throw new RuntimeException(
                    'Hanya pembayaran yang masih aktif yang dapat dibatalkan.'
                );
            }

            /*
             * =========================================================
             * 3. KUNCI TRANSAKSI PENJUALAN
             * =========================================================
             */
            $sale = Sale::query()
                ->whereKey($lockedPayment->sale_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * =========================================================
             * 4. BATALKAN PEMBAYARAN
             * =========================================================
             */
            $lockedPayment->update([
                'status' => SalePayment::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            /*
             * =========================================================
             * 5. HITUNG ULANG TOTAL PEMBAYARAN AKTIF
             * =========================================================
             */
            $paidAmount = (float) SalePayment::query()
                ->where('sale_id', $sale->id)
                ->where(
                    'status',
                    SalePayment::STATUS_POSTED
                )
                ->sum('amount');

            /*
             * =========================================================
             * 6. HITUNG ULANG STATUS PEMBAYARAN
             * =========================================================
             */
            $paymentStatus = match (true) {
                $paidAmount <= 0 => 'unpaid',

                $paidAmount + 0.01
                    < (float) $sale->total_amount => 'partial',

                default => 'paid',
            };

            $sale->update([
                'payment_status' => $paymentStatus,
            ]);
        });
    }
}
