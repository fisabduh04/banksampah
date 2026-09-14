<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Services\CustomerTransactionDraftService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateDeposit extends CreateRecord
{
    protected static string $resource = DepositResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['deposit_number'] = 'TMP-'.Str::ulid();
        $data['status'] = 'draft';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->update(['deposit_number' => 'ST-'.now()->year.'-'.str_pad((string) $this->record->id, 6, '0', STR_PAD_LEFT)]);
        app(CustomerTransactionDraftService::class)->recalculate($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
