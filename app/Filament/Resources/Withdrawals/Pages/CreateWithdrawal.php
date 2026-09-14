<?php

namespace App\Filament\Resources\Withdrawals\Pages;

use App\Filament\Resources\Withdrawals\WithdrawalResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateWithdrawal extends CreateRecord
{
    protected static string $resource = WithdrawalResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['withdrawal_number'] = 'TMP-'.Str::ulid();
        $data['status'] = 'draft';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->update(['withdrawal_number' => 'WD-'.now()->year.'-'.str_pad((string) $this->record->id, 6, '0', STR_PAD_LEFT)]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
