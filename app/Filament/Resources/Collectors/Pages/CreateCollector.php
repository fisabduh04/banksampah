<?php

namespace App\Filament\Resources\Collectors\Pages;

use App\Filament\Concerns\UsesIndonesianLocale;
use App\Filament\Resources\Collectors\CollectorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCollector extends CreateRecord
{
    use UsesIndonesianLocale;

    protected static string $resource = CollectorResource::class;

    /**
     * Setelah data berhasil dibuat,
     * arahkan pengguna kembali ke tabel pengepul.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected static bool $canCreateAnother = false;

    /**
     * Pesan setelah data berhasil disimpan.
     */
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Data pengepul berhasil ditambahkan.';
    }
}
