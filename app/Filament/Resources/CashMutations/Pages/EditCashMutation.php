<?php

namespace App\Filament\Resources\CashMutations\Pages;

use App\Filament\Resources\CashMutations\CashMutationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCashMutation extends EditRecord
{
    protected static string $resource = CashMutationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
