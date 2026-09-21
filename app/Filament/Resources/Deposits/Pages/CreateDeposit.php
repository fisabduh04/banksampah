<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Customer;
use App\Models\Deposit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\View\View;

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

    public function getHeader(): ?View
    {
        $customerId = $this->data['customer_id'] ?? null;
        $customer = filled($customerId) ? Customer::find($customerId) : null;

        return view('deposit-form-header', [
            'customerName' => $customer?->name,
            'customerBalance' => $customer?->balance,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
