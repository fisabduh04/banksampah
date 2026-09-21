<?php

namespace App\Filament\Resources\Withdrawals\Pages;

use App\Filament\Resources\Withdrawals\WithdrawalResource;
use App\Models\Customer;
use App\Models\Withdrawal;
use App\Services\CustomerDraftService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditWithdrawal extends EditRecord
{
    protected static string $resource = WithdrawalResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeValidate(): void
    {
        app(CustomerDraftService::class)->lock($this->getRecord());
        if (($this->data['status'] ?? 'draft') !== 'draft') {
            throw ValidationException::withMessages(['data.status' => 'Gunakan tombol Posting untuk membukukan transaksi.']);
        }
        $this->data['available_balance'] = Customer::find($this->data['customer_id'] ?? null)?->balance ?? 0;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status'], $data['withdrawal_number']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Withdrawal $record): bool => app(CustomerDraftService::class)->delete($record)),
        ];
    }
}
