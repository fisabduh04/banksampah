<?php

namespace App\Filament\Resources\Collectors\Pages;

use App\Filament\Resources\Collectors\CollectorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCollector extends CreateRecord
{
    protected static string $resource = CollectorResource::class;

    /**
     * Setelah data berhasil dibuat,
     * arahkan pengguna kembali ke tabel pengepul.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    // /**
    //  * Nonaktifkan pilihan "Create & create another"
    //  * agar alur input lebih sederhana.
    //  */
    // protected static bool $canCreateAnother = false;

    /**
     * Pesan setelah data berhasil disimpan.
     */
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Data pengepul berhasil ditambahkan.';
    }
}
