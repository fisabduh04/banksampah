<?php

namespace App\Filament\Resources\Collectors\Pages;

use App\Filament\Concerns\UsesIndonesianLocale;
use App\Filament\Resources\Collectors\CollectorResource;
use Filament\Resources\Pages\EditRecord;

class EditCollector extends EditRecord
{
    use UsesIndonesianLocale;

    protected static string $resource = CollectorResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
