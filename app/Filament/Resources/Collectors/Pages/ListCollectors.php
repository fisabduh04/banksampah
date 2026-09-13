<?php

namespace App\Filament\Resources\Collectors\Pages;

use App\Filament\Resources\Collectors\CollectorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCollectors extends ListRecords
{
    protected static string $resource = CollectorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah Pengepul')
                ->icon('heroicon-o-plus'),
        ];
    }
}
