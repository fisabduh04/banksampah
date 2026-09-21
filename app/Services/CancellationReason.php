<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class CancellationReason
{
    public function describe(string $reason, int $userId, bool $confirmedCorrection): string
    {
        if (! $confirmedCorrection) {
            throw new RuntimeException('Konfirmasikan bahwa pembatalan adalah koreksi pencatatan. Pengembalian uang atau barang harus diproses terpisah.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new RuntimeException('Alasan koreksi wajib diisi, maksimal 2000 karakter. Cantumkan transaksi yang benar jika terjadi pencatatan ganda.');
        }
        $user = User::query()->find($userId);
        if (! $user) {
            throw new RuntimeException('Petugas koreksi tidak ditemukan.');
        }

        return 'Koreksi pencatatan | Petugas #'.$user->id.' ('.$user->name.') | Alasan: '.$reason;
    }
}
