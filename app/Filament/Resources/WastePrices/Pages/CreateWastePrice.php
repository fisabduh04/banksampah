<?php

namespace App\Filament\Resources\WastePrices\Pages;

use App\Filament\Resources\WastePrices\WastePriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWastePrice extends CreateRecord
{
    protected static string $resource = WastePriceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
