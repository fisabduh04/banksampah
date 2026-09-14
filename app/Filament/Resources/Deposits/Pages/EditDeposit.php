<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Deposit;
use App\Services\CustomerTransactionDraftService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeposit extends EditRecord
{
    protected static string $resource = DepositResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeValidate(): void
    {
        app(CustomerTransactionDraftService::class)->lockDraft($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status'], $data['deposit_number'], $data['total_weight'], $data['total_amount']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(CustomerTransactionDraftService::class)->recalculate($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Hapus Draft')->using(function (Deposit $record): bool {
            app(CustomerTransactionDraftService::class)->delete($record);

            return true;
        })];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
