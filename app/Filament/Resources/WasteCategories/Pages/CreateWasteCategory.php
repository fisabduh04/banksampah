<?php

namespace App\Filament\Resources\WasteCategories\Pages;

use App\Filament\Resources\WasteCategories\WasteCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWasteCategory extends CreateRecord
{
    protected static string $resource = WasteCategoryResource::class;
}
