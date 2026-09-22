<?php

namespace App\Filament\Resources\CashMutations\Pages;

use App\Filament\Resources\CashMutations\CashMutationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCashMutation extends ViewRecord
{
    protected static string $resource = CashMutationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
