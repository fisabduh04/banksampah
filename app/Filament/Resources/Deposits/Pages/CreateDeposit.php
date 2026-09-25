<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\CreatesFinancialDocument;
use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\View\View;

class CreateDeposit extends CreateRecord
{
    use CreatesFinancialDocument;

    protected static string $resource = DepositResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'draft';

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
