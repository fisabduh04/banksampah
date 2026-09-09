<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Deposit;
use Filament\Resources\Pages\CreateRecord;

class CreateDeposit extends CreateRecord
{
    protected static string $resource = DepositResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $year = now()->year;

        $lastDeposit = Deposit::query()
            ->whereYear('created_at', $year)
            ->latest('id')
            ->first();

        $nextNumber = $lastDeposit
            ? ((int) substr($lastDeposit->deposit_number, -6)) + 1
            : 1;

        $data['deposit_number'] = sprintf(
            'ST-%s-%06d',
            $year,
            $nextNumber
        );

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
