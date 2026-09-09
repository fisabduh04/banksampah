<?php

namespace App\Filament\Resources\WastePrices\Pages;

use App\Filament\Resources\WastePrices\WastePriceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWastePrice extends EditRecord
{
    protected static string $resource = WastePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
