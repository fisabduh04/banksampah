<?php

namespace App\Services;

use App\Models\SalePayment;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class FinancialControlService
{
    /** Pemeriksaan bersama mencegah penutupan periode menyela transaksi yang sedang diposting. */
    public function ensureOpen(string $date): void
    {
        if (DB::transactionLevel() === 0) {
            throw new UnexpectedValueException('Kontrol periode wajib berada dalam transaksi database.');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new UnexpectedValueException('Tanggal pembukuan tidak valid.');
        }
        $control = DB::table('financial_controls')->where('id', 1)->sharedLock()->first();
        if (! $control || ($control->closed_through !== null && $date <= $control->closed_through)) {
            throw new UnexpectedValueException('Tanggal transaksi berada pada periode yang sudah ditutup atau kontrol periode belum tersedia.');
        }
        if ($date > now()->toDateString()) {
            throw new UnexpectedValueException('Transaksi belum boleh diposting dengan tanggal masa depan.');
        }
    }

    public function authorize(string $operation, ?int $userId): User
    {
        $user = $userId ? User::query()->find($userId) : null;
        if (! $user || ! $user->canPerformFinancialOperation($operation)) {
            throw new UnexpectedValueException('Pengguna tidak berwenang melakukan tindakan keuangan ini.');
        }

        return $user;
    }

    public function closeThrough(string $date, int $userId, string $reason): void
    {
        $this->authorize('approve', $userId);
        if (trim($reason) === '') {
            throw new UnexpectedValueException('Alasan penutupan periode wajib diisi.');
        }
        DB::transaction(function () use ($date, $userId, $reason): void {
            $control = DB::table('financial_controls')->where('id', 1)->lockForUpdate()->first();
            $this->ensureOpen($date);
            if (SalePayment::query()->where('status', 'posted')->whereDate('payment_date', '<=', $date)->whereNull('verified_at')->exists()) {
                throw new UnexpectedValueException('Masih ada pembayaran yang belum diverifikasi. Periksa bukti sebelum menutup periode.');
            }
            if (Withdrawal::query()->where('status', 'posted')->whereDate('transaction_date', '<=', $date)->whereNull('verified_at')->exists()) {
                throw new UnexpectedValueException('Masih ada penarikan yang belum diverifikasi. Periksa bukti penyerahan dana sebelum menutup periode.');
            }
            $after = ['closed_through' => $date, 'closed_by' => $userId, 'closing_reason' => trim($reason)];
            DB::table('financial_controls')->where('id', 1)->update([...$after, 'updated_at' => now()]);
            DB::table('financial_control_events')->insert([
                'event_type' => 'period_closed', 'performed_by' => $userId,
                'before_state' => json_encode((array) $control, JSON_THROW_ON_ERROR),
                'after_state' => json_encode($after, JSON_THROW_ON_ERROR),
                'reason' => trim($reason), 'created_at' => now(),
            ]);
        }, attempts: 3);
    }
}
