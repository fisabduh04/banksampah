<?php

namespace App\Filament;

use App\Models\Deposit;
use App\Models\Sale;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait CreatesFinancialDocument
{
    #[Locked]
    public string $creationNumber = '';

    protected function afterFill(): void
    {
        $prefix = match (static::getModel()) {
            Deposit::class => 'ST',
            Withdrawal::class => 'WD',
            Sale::class => 'PJ',
        };

        $this->creationNumber = $prefix.'-'.now()->year.'-'.Str::ulid();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $column = match (static::getModel()) {
            Deposit::class => 'deposit_number',
            Withdrawal::class => 'withdrawal_number',
            Sale::class => 'sale_number',
        };

        if ($this->creationNumber === '') {
            throw ValidationException::withMessages(['data.'.$column => 'Buka kembali formulir transaksi.']);
        }

        if (static::getModel()::query()->where($column, $this->creationNumber)->exists()) {
            throw ValidationException::withMessages(['data.'.$column => 'Permintaan ini sudah tersimpan. Periksa dokumen yang sudah dibuat.']);
        }

        $data[$column] = $this->creationNumber;

        try {
            return parent::handleRecordCreation($data);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['data.'.$column => 'Nomor transaksi sudah tersimpan. Periksa dokumen yang sudah dibuat.']);
        }
    }
}
