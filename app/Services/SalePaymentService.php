<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class SalePaymentService
{
    public function recordPayment(
        Sale $sale,
        string $amount,
        string $paymentDate,
        string $paymentMethod,
        ?string $referenceNumber,
        ?string $notes,
        int $userId,
        string $idempotencyKey
    ): SalePayment {
        if (! preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/D', $amount) || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('Jumlah pembayaran harus positif, maksimal 13 digit sebelum koma dan 2 angka desimal.');
        }
        $amount = (string) BigDecimal::of($amount)->toScale(2);
        if (! Str::isUuid($idempotencyKey)) {
            throw new RuntimeException('Pengenal permintaan pembayaran tidak valid. Buka kembali form pembayaran.');
        }
        $idempotencyKey = strtolower($idempotencyKey);
        if (Validator::make(['date' => $paymentDate], ['date' => ['required', 'date_format:Y-m-d']])->fails()
            || $paymentDate > now()->toDateString()) {
            throw new RuntimeException('Tanggal pembayaran tidak valid atau melewati hari ini. Gunakan tanggal uang benar-benar diterima sesuai bukti pembayaran.');
        }
        $referenceNumber = trim($referenceNumber ?? '');
        $referenceNumber = $referenceNumber === '' ? null : $referenceNumber;
        $notes = trim($notes ?? '');
        $notes = $notes === '' ? null : $notes;
        if (! in_array($paymentMethod, ['cash', 'transfer', 'other'], true)
            || mb_strlen($referenceNumber ?? '') > 100 || mb_strlen($notes ?? '') > 2000) {
            throw new RuntimeException('Metode pembayaran, nomor referensi, atau catatan tidak valid.');
        }
        if (! User::query()->whereKey($userId)->exists()) {
            throw new RuntimeException('Petugas penerima pembayaran tidak ditemukan.');
        }

        return DB::transaction(function () use ($sale, $amount, $paymentDate, $paymentMethod, $referenceNumber, $notes, $userId, $idempotencyKey): SalePayment {
            $lockedSale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($lockedSale->status !== Sale::STATUS_POSTED) {
                throw new RuntimeException('Pembayaran hanya dapat dicatat untuk penjualan yang sudah diposting.');
            }
            $existing = SalePayment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->sale_id !== $lockedSale->id || ! BigDecimal::of($existing->amount)->isEqualTo($amount)
                    || $existing->payment_date->toDateString() !== $paymentDate || $existing->payment_method !== $paymentMethod
                    || $existing->reference_number !== $referenceNumber || $existing->notes !== $notes || $existing->received_by !== $userId) {
                    throw new RuntimeException('Pengenal pembayaran sudah dipakai untuk data yang berbeda. Periksa pembayaran yang sudah tercatat.');
                }
                if ($existing->status !== SalePayment::STATUS_POSTED) {
                    throw new RuntimeException('Pembayaran dengan pengenal ini sudah dibatalkan. Permintaan lama tidak dapat dipakai kembali.');
                }

                return $existing;
            }
            if ($paymentDate < $lockedSale->transaction_date->toDateString()) {
                throw new RuntimeException('Tanggal pembayaran tidak boleh mendahului tanggal penjualan. Uang muka harus dicatat melalui proses terpisah.');
            }
            $payments = SalePayment::query()->where('sale_id', $lockedSale->id)->lockForUpdate()->get();
            foreach ($payments as $previous) {
                if ($paymentDate < $previous->payment_date->toDateString()
                    || ($previous->cancelled_at !== null && $paymentDate < $previous->cancelled_at->toDateString())) {
                    throw new RuntimeException('Tanggal pembayaran tidak boleh mendahului riwayat pembayaran atau pembatalan terakhir penjualan ini. Periksa tanggal pada bukti pembayaran. Jika terlambat dicatat, hubungi administrator untuk pemeriksaan; jangan mengganti tanggal hanya agar lolos.');
                }
            }
            $paidAmount = $this->paidAmount($payments);
            $outstanding = BigDecimal::of($lockedSale->total_amount)->minus($paidAmount);
            if ($outstanding->isLessThanOrEqualTo(0)) {
                throw new RuntimeException('Transaksi sudah lunas atau riwayat pembayaran melebihi nilai penjualan.');
            }
            if (BigDecimal::of($amount)->isGreaterThan($outstanding)) {
                throw new RuntimeException('Pembayaran melebihi sisa piutang. Sisa piutang saat ini Rp '.$outstanding->toScale(2).'. Periksa kolom Sisa Piutang dan riwayat pembayaran. Jika nominal pada bukti lebih besar, minta administrator memeriksa selisih sebelum mencatat.');
            }
            $payment = SalePayment::create([
                'payment_number' => 'TMP-'.$idempotencyKey,
                'idempotency_key' => $idempotencyKey,
                'sale_id' => $lockedSale->id, 'payment_date' => $paymentDate, 'amount' => $amount,
                'payment_method' => $paymentMethod, 'reference_number' => $referenceNumber,
                'status' => SalePayment::STATUS_POSTED, 'received_by' => $userId, 'notes' => $notes,
            ]);
            $payment->update(['payment_number' => 'BYR-'.$payment->payment_date->format('Ymd').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);
            $lockedSale->update(['payment_status' => $paidAmount->plus($amount)->isEqualTo($lockedSale->total_amount) ? 'paid' : 'partial']);

            return $payment->fresh();
        }, attempts: 3);
    }

    public function cancelPayment(SalePayment $payment, string $reason, int $userId, bool $confirmedCorrection = false): void
    {
        $reason = app(CancellationReason::class)->describe($reason, $userId, $confirmedCorrection);
        DB::transaction(function () use ($payment, $reason, $userId): void {
            /** Semua perubahan pembayaran mengunci penjualan terlebih dahulu agar urutan kunci konsisten. */
            $sale = Sale::query()->whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();
            $lockedPayment = SalePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($lockedPayment->sale_id !== $sale->id || $sale->status !== Sale::STATUS_POSTED) {
                throw new RuntimeException('Penjualan asal pembayaran tidak sesuai atau sudah dibatalkan.');
            }
            if ($lockedPayment->status !== SalePayment::STATUS_POSTED) {
                throw new RuntimeException('Hanya pembayaran yang masih aktif yang dapat dibatalkan.');
            }
            if ($lockedPayment->getAttribute('verified_at') !== null) {
                throw new RuntimeException('Pembayaran sudah diverifikasi. Periksa pengembalian uang melalui proses terpisah.');
            }
            if ($lockedPayment->payment_date->toDateString() > now()->toDateString()) {
                throw new RuntimeException('Tanggal pembatalan tidak boleh mendahului pembayaran asal.');
            }
            $lockedPayment->update([
                'status' => SalePayment::STATUS_CANCELLED, 'cancelled_at' => now(),
                'cancelled_by' => $userId, 'cancellation_reason' => $reason,
            ]);
            $paidAmount = $this->paidAmount(SalePayment::query()->where('sale_id', $sale->id)->lockForUpdate()->get());
            $sale->update(['payment_status' => match (true) {
                $paidAmount->isZero() => 'unpaid',
                $paidAmount->isLessThan($sale->total_amount) => 'partial',
                default => 'paid',
            }]);
        }, attempts: 3);
        $payment->refresh();
    }

    /** @param Collection<int, SalePayment> $payments */
    private function paidAmount(Collection $payments): BigDecimal
    {
        $total = BigDecimal::of(0);
        foreach ($payments as $payment) {
            if (! in_array($payment->status, [SalePayment::STATUS_POSTED, SalePayment::STATUS_CANCELLED], true)) {
                throw new RuntimeException('Status riwayat pembayaran tidak valid. Periksa pembayaran asal.');
            }
            if ($payment->status === SalePayment::STATUS_POSTED) {
                if (BigDecimal::of($payment->amount)->isLessThanOrEqualTo(0)) {
                    throw new RuntimeException('Riwayat nominal pembayaran tidak valid. Periksa pembayaran asal.');
                }
                $total = $total->plus($payment->amount);
            }
        }

        return $total;
    }
}
