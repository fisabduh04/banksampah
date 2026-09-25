<?php

namespace App\Filament\Resources\Withdrawals\Pages;

use App\Filament\CreatesFinancialDocument;
use App\Filament\Resources\Withdrawals\WithdrawalResource;
use App\Services\WithdrawalService;
use Exception;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateWithdrawal extends CreateRecord
{
    use CreatesFinancialDocument;

    protected static string $resource = WithdrawalResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected static bool $canCreateAnother = false;

    protected ?string $statusPilihan = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->statusPilihan = $data['status'] ?? 'draft';
        $data['status'] = 'draft';

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->statusPilihan !== 'posted') {
            return;
        }

        try {
            app(WithdrawalService::class)->post($this->record, auth()->id());
        } catch (Exception $exception) {
            /** Propagate the failure so Filament rolls back the document and every ledger. */
            throw ValidationException::withMessages(['data.amount' => $exception->getMessage()]);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->statusPilihan === 'posted'
            ? 'Penarikan berhasil dibukukan.' : 'Draft penarikan berhasil disimpan.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
