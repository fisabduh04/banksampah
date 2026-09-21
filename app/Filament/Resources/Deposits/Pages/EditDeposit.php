<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Customer;
use App\Models\Deposit;
use App\Services\CustomerDraftService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class EditDeposit extends EditRecord
{
    protected static string $resource = DepositResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeValidate(): void
    {
        app(CustomerDraftService::class)->lock($this->getRecord());
        if (($this->data['status'] ?? 'draft') !== 'draft') {
            throw ValidationException::withMessages(['data.status' => 'Gunakan tombol Posting untuk membukukan transaksi.']);
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status'], $data['deposit_number']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Deposit $record): bool => app(CustomerDraftService::class)->delete($record))
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
