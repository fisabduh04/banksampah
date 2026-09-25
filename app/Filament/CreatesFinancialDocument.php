<?php

namespace App\Filament;

use App\Models\Deposit;
use App\Models\Sale;
use App\Models\Withdrawal;
use App\Services\FinancialDocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

trait CreatesFinancialDocument
{
    #[Locked]
    public string $creationNumber = '';

    protected function afterFill(): void
    {
        $prefix = match (static::getModel()) {
            Deposit::class => 'ST',
            Withdrawal::class => 'WD',
            Sale::class => 'PJ',
        };

        $this->creationNumber = $prefix.'-'.now()->year.'-'.Str::ulid();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(FinancialDocumentService::class)->createDraft(static::getModel(), $data, $this->creationNumber);
    }
}
