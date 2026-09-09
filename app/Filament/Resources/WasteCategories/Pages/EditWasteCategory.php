<?php

namespace App\Filament\Resources\WasteCategories\Pages;

use App\Filament\Resources\WasteCategories\WasteCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWasteCategory extends EditRecord
{
    protected static string $resource = WasteCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
