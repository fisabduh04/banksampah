<?php

namespace App\Services;

use App\Models\BalanceMutation;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class WithdrawalService
{
    public function __construct(
        private readonly CustomerBalanceService $balances,
        private readonly FinancialControlService $controls,
    ) {}

    public function post(Withdrawal $withdrawal, ?int $userId = null): void
    {
        $userId = $this->controls->authorize('record', $userId ?? auth()->id())->id;
        DB::transaction(function () use ($withdrawal, $userId): void {
            $this->controls->ensureOpen(now()->toDateString());
            $record = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->controls->ensureOpen($record->transaction_date->toDateString());
            if ($record->status !== 'draft') {
                throw new UnexpectedValueException('Hanya transaksi yang belum dibukukan yang dapat diposting.');
            }
            $this->balances->ensureAvailable($record->customer_id, $record->amount);
            if (BalanceMutation::query()->where('reference_type', 'withdrawal')->where('reference_id', $record->id)->exists()) {
                throw new UnexpectedValueException('Mutasi penarikan sudah pernah dibuat.');
            }
            BalanceMutation::create([
                'customer_id' => $record->customer_id, 'type' => 'debit', 'amount' => $record->amount,
                'reference_type' => 'withdrawal', 'reference_id' => $record->id,
                'transaction_date' => $record->transaction_date,
                'description' => 'Penarikan saldo '.$record->withdrawal_number,
            ]);
            $record->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $userId])->save();
        }, attempts: 3);
        $withdrawal->refresh();
    }

    public function cancel(Withdrawal $withdrawal, string $reason, ?int $userId = null, string $cancellationType = 'entry_error', ?string $refundReference = null): void
    {
        $userId = $this->controls->authorize('approve', $userId ?? auth()->id())->id;
        if (trim($reason) === '') {
            throw new UnexpectedValueException('Alasan pembatalan wajib diisi.');
        }
        if (! in_array($cancellationType, ['entry_error', 'refund'], true) || ($cancellationType === 'refund' && (trim($refundReference ?? '') === '' || mb_strlen($refundReference) > 100))) {
            throw new UnexpectedValueException('Jenis pembatalan dan referensi bukti uang kembali wajib diisi dengan benar.');
        }
        DB::transaction(function () use ($withdrawal, $reason, $userId, $cancellationType, $refundReference): void {
            $this->controls->ensureOpen(now()->toDateString());
            $record = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->controls->ensureOpen($record->transaction_date->toDateString());
            if ($record->status !== 'posted') {
                throw new UnexpectedValueException('Hanya transaksi yang telah dibukukan yang dapat dibatalkan.');
            }
            if ($record->verified_at !== null && $cancellationType === 'entry_error') {
                throw new UnexpectedValueException('Penyerahan dana yang sudah diverifikasi harus dikoreksi dengan bukti uang kembali.');
            }
            $this->balances->getLockedBalance($record->customer_id);
            if (BalanceMutation::query()->where('reference_type', 'withdrawal_cancellation')->where('reference_id', $record->id)->exists()) {
                throw new UnexpectedValueException('Pembatalan transaksi ini sudah pernah diproses.');
            }
            BalanceMutation::create([
                'customer_id' => $record->customer_id, 'type' => 'credit', 'amount' => $record->amount,
                'reference_type' => 'withdrawal_cancellation', 'reference_id' => $record->id,
                'transaction_date' => now()->toDateString(),
                'description' => 'Pembatalan penarikan saldo '.$record->withdrawal_number,
            ]);
            $record->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId, 'cancellation_reason' => trim($reason),
                'cancellation_type' => $cancellationType, 'refund_reference' => $cancellationType === 'refund' ? trim($refundReference) : null])->save();
        }, attempts: 3);
        $withdrawal->refresh();
    }

    public function verifyWithdrawal(Withdrawal $withdrawal, string $reference, int $userId): void
    {
        $this->controls->authorize('approve', $userId);
        if (trim($reference) === '' || mb_strlen($reference) > 100) {
            throw new UnexpectedValueException('Referensi bukti penyerahan dana wajib diisi, paling banyak 100 karakter.');
        }
        DB::transaction(function () use ($withdrawal, $reference, $userId): void {
            $this->controls->ensureOpen(now()->toDateString());
            $record = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== 'posted' || $record->verified_at !== null) {
                throw new UnexpectedValueException('Penarikan sudah diverifikasi atau tidak aktif.');
            }
            if ($record->posted_by === $userId) {
                throw new UnexpectedValueException('Verifikasi harus dilakukan oleh petugas berbeda dari pembuku penarikan.');
            }
            $record->forceFill(['verified_at' => now(), 'verified_by' => $userId, 'verification_reference' => trim($reference)])->save();
        }, attempts: 3);
    }
}
