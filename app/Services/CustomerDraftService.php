<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CustomerDraftService
{
    public function lock(Deposit|Withdrawal $record): Deposit|Withdrawal
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Pemeriksaan draft harus berada dalam transaksi database.');
        }
        $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'draft') {
            throw ValidationException::withMessages(['data.status' => 'Transaksi sudah dibukukan atau dibatalkan. Perubahan tidak disimpan.']);
        }

        return $locked;
    }

    public function delete(Deposit|Withdrawal $record): bool
    {
        return DB::transaction(fn (): bool => (bool) $this->lock($record)->delete(), attempts: 3);
    }
}
