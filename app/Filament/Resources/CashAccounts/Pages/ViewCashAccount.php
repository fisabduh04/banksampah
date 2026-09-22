<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Resources\CashAccounts\CashAccountResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCashAccount extends ViewRecord
{
    protected static string $resource = CashAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
