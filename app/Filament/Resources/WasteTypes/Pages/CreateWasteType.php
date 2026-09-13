<?php

namespace App\Filament\Resources\WasteTypes\Pages;

use App\Filament\Resources\WasteTypes\WasteTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWasteType extends CreateRecord
{
    protected static string $resource = WasteTypeResource::class;

    /**
     * Setelah data Jenis Bahan berhasil dibuat,
     * arahkan user kembali ke halaman daftar/tabel.
     *
     * Tujuannya agar alur kerja lebih cepat:
     * Tambah -> Simpan -> kembali ke tabel.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
