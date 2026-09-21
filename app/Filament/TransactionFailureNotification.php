<?php

namespace App\Filament;

use Exception;
use Filament\Notifications\Notification;
use RuntimeException;
use Throwable;

class TransactionFailureNotification
{
    public static function send(Throwable $exception, string $title): void
    {
        // Service transaksi saat ini memakai Exception atau RuntimeException langsung untuk penolakan bisnis.
        $isBusinessFailure = in_array($exception::class, [Exception::class, RuntimeException::class], true);
        if (! $isBusinessFailure) {
            report($exception);
        }

        $message = $isBusinessFailure
            ? $exception->getMessage()
            : 'Proses belum dapat diselesaikan karena gangguan sistem. Periksa status dan riwayat transaksi sebelum mencoba lagi agar tidak tercatat ganda. Jika masalah berulang, hubungi administrator dengan nomor transaksi dan waktu kejadian.';

        Notification::make()
            ->title($title)
            ->body(e($message))
            ->danger()
            ->persistent()
            ->send();
    }
}
