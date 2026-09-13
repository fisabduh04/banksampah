<?php

namespace App\Filament\Resources\WasteTypes\Pages;

use App\Filament\Resources\WasteTypes\WasteTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWasteType extends EditRecord
{
    protected static string $resource = WasteTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Setelah perubahan data berhasil disimpan,
     * kembali ke halaman daftar Jenis Bahan.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
