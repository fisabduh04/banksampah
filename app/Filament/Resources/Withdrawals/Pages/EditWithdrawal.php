<?php

namespace App\Filament\Resources\Withdrawals\Pages;

use App\Filament\Resources\Withdrawals\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\CustomerTransactionDraftService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWithdrawal extends EditRecord
{
    protected static string $resource = WithdrawalResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeValidate(): void
    {
        app(CustomerTransactionDraftService::class)->lockDraft($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status'], $data['withdrawal_number'], $data['total_weight'], $data['total_amount']);

        return $data;
    }

    protected function afterSave(): void {}

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Hapus Draft')->using(function (Withdrawal $record): bool {
            app(CustomerTransactionDraftService::class)->delete($record);

            return true;
        })];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
