<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

class SalePaymentService
{
    public function __construct(private readonly FinancialControlService $controls) {}

    public function recordPayment(Sale $sale, string|int|float $amount, string $paymentDate, string $paymentMethod, ?string $referenceNumber, ?string $notes, int $userId, ?string $idempotencyKey = null): SalePayment
    {
        $this->controls->authorize('record', $userId);
        try {
            $amount = BigDecimal::of((string) $amount)->toScale(2, RoundingMode::Unnecessary);
        } catch (MathException $exception) {
            throw new UnexpectedValueException('Jumlah pembayaran harus berupa angka dengan paling banyak dua desimal.', previous: $exception);
        }
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new UnexpectedValueException('Jumlah pembayaran harus lebih dari nol.');
        }
        if (! in_array($paymentMethod, ['cash', 'transfer', 'other'], true)) {
            throw new UnexpectedValueException('Metode pembayaran tidak valid.');
        }
        if ($paymentMethod !== 'cash' && trim($referenceNumber ?? '') === '') {
            throw new UnexpectedValueException('Nomor referensi pembayaran nontunai wajib diisi.');
        }
        if ($idempotencyKey === null || ! Str::isUuid($idempotencyKey)) {
            throw new UnexpectedValueException('Identitas permintaan pembayaran tidak valid.');
        }

        return DB::transaction(function () use ($sale, $amount, $paymentDate, $paymentMethod, $referenceNumber, $notes, $userId, $idempotencyKey): SalePayment {
            $this->controls->ensureOpen(now()->toDateString());
            $lockedSale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($idempotencyKey !== null) {
                $existing = SalePayment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->sale_id !== $lockedSale->id || ! $amount->isEqualTo($existing->amount)
                        || $existing->payment_date->toDateString() !== $paymentDate || $existing->payment_method !== $paymentMethod
                        || $existing->reference_number !== $referenceNumber || $existing->notes !== $notes || $existing->received_by !== $userId) {
                        throw new UnexpectedValueException('Identitas permintaan sudah dipakai dengan rincian pembayaran berbeda.');
                    }

                    return $existing;
                }
            }
            $this->controls->ensureOpen($paymentDate);
            if ($lockedSale->status !== Sale::STATUS_POSTED) {
                throw new UnexpectedValueException('Pembayaran hanya dapat dicatat untuk penjualan yang sudah diposting.');
            }
            if ($paymentDate < $lockedSale->transaction_date->toDateString()) {
                throw new UnexpectedValueException('Tanggal pembayaran tidak boleh mendahului tanggal penjualan.');
            }
            $paidAmount = $this->paidAmount($lockedSale);
            $outstanding = BigDecimal::of($lockedSale->total_amount)->minus($paidAmount);
            if ($outstanding->isLessThanOrEqualTo(0)) {
                throw new UnexpectedValueException('Transaksi ini sudah lunas.');
            }
            if ($amount->isGreaterThan($outstanding)) {
                throw new UnexpectedValueException('Pembayaran melebihi sisa piutang. Sisa piutang saat ini Rp '.number_format((float) (string) $outstanding, 2, ',', '.').'.');
            }
            $payment = SalePayment::create([
                'payment_number' => 'TMP-'.Str::ulid(), 'sale_id' => $lockedSale->id,
                'payment_date' => $paymentDate, 'amount' => (string) $amount,
                'payment_method' => $paymentMethod, 'reference_number' => $referenceNumber,
                'status' => SalePayment::STATUS_POSTED, 'received_by' => $userId, 'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
            ]);
            $payment->update(['payment_number' => 'BYR-'.$payment->payment_date->format('Ymd').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);
            $this->updatePaymentStatus($lockedSale);

            return $payment->fresh();
        }, attempts: 3);
    }

    /** Verifikasi dilakukan petugas berbeda dengan referensi kuitansi atau rekening koran. */
    public function verifyPayment(SalePayment $payment, string $reference, int $userId): void
    {
        $this->controls->authorize('approve', $userId);
        if (trim($reference) === '' || mb_strlen($reference) > 100) {
            throw new UnexpectedValueException('Referensi bukti verifikasi wajib diisi, paling banyak 100 karakter.');
        }
        DB::transaction(function () use ($payment, $reference, $userId): void {
            $this->controls->ensureOpen(now()->toDateString());
            Sale::query()->whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();
            $record = SalePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== SalePayment::STATUS_POSTED || $record->verified_at !== null) {
                throw new UnexpectedValueException('Pembayaran sudah diverifikasi atau tidak aktif.');
            }
            if ($record->received_by === $userId) {
                throw new UnexpectedValueException('Verifikasi harus dilakukan oleh petugas berbeda dari pencatat pembayaran.');
            }
            $record->forceFill(['verified_at' => now(), 'verified_by' => $userId, 'verification_reference' => trim($reference)])->save();
        }, attempts: 3);
    }

    public function cancelPayment(SalePayment $payment, string $reason, int $userId, string $cancellationType = 'entry_error', ?string $refundReference = null): void
    {
        $this->controls->authorize('approve', $userId);
        if (trim($reason) === '') {
            throw new UnexpectedValueException('Alasan pembatalan pembayaran wajib diisi.');
        }
        if (! in_array($cancellationType, ['entry_error', 'refund'], true)) {
            throw new UnexpectedValueException('Jenis pembatalan pembayaran tidak valid.');
        }
        if ($cancellationType === 'refund' && (trim($refundReference ?? '') === '' || mb_strlen($refundReference) > 100)) {
            throw new UnexpectedValueException('Referensi bukti pengembalian dana wajib diisi, paling banyak 100 karakter.');
        }
        DB::transaction(function () use ($payment, $reason, $userId, $cancellationType, $refundReference): void {
            $this->controls->ensureOpen(now()->toDateString());
            $saleId = SalePayment::query()->whereKey($payment->id)->value('sale_id');
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();
            $record = SalePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->controls->ensureOpen($record->payment_date->toDateString());
            if ($record->status !== SalePayment::STATUS_POSTED) {
                throw new UnexpectedValueException('Hanya pembayaran yang masih aktif yang dapat dibatalkan.');
            }
            if ($record->verified_at !== null && $cancellationType === 'entry_error') {
                throw new UnexpectedValueException('Dana yang sudah diverifikasi harus dikoreksi melalui pengembalian dana dengan bukti.');
            }
            $record->forceFill([
                'status' => SalePayment::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $userId,
                'cancellation_reason' => trim($reason), 'cancellation_type' => $cancellationType,
                'refund_reference' => $cancellationType === 'refund' ? trim($refundReference) : null,
            ])->save();
            $this->updatePaymentStatus($sale);
        }, attempts: 3);
        $payment->refresh();
    }

    private function paidAmount(Sale $sale): BigDecimal
    {
        $amount = BigDecimal::of(0);
        foreach ($sale->payments()->where('status', SalePayment::STATUS_POSTED)->lockForUpdate()->get() as $payment) {
            $amount = $amount->plus($payment->amount);
        }

        return $amount;
    }

    private function updatePaymentStatus(Sale $sale): void
    {
        $paid = $this->paidAmount($sale);
        $sale->update(['payment_status' => match (true) {
            $paid->isLessThanOrEqualTo(0) => 'unpaid',
            $paid->isLessThan($sale->total_amount) => 'partial',
            default => 'paid',
        }]);
    }
}
