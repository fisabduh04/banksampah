<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Customer;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;

class EditDeposit extends EditRecord
{
    protected static string $resource = DepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Delete')
                ->visible(fn (): bool => $this->record->status === 'draft')
                ->requiresConfirmation(),
        ];
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
