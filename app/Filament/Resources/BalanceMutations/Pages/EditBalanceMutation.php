<?php

namespace App\Filament\Resources\BalanceMutations\Pages;

use App\Filament\Resources\BalanceMutations\BalanceMutationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBalanceMutation extends EditRecord
{
    protected static string $resource = BalanceMutationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
