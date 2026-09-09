<?php

namespace App\Filament\Resources\WasteTypes\Pages;

use App\Filament\Exports\WasteTypeExporter;
use App\Filament\Imports\WasteTypeImporter;
use App\Filament\Resources\WasteTypes\WasteTypeResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListWasteTypes extends ListRecords
{
    protected static string $resource = WasteTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /**
             * Menambah Jenis Bahan secara manual melalui form.
             */
            CreateAction::make()
                ->label('Tambah Jenis Bahan')
                ->icon('heroicon-o-plus'),

            /**
             * Mengimpor Jenis Bahan dari file CSV.
             *
             * Catatan:
             * - Data disiapkan di Excel.
             * - Simpan sebagai CSV UTF-8 sebelum diunggah.
             * - Kode Bahan menjadi kunci upsert.
             * - Kategori menggunakan Kode Kategori,
             *   bukan ID database.
             */
            ImportAction::make()
                ->label('Impor Data')
                ->icon('heroicon-o-arrow-up-tray')
                ->importer(WasteTypeImporter::class),

            /**
             * Mengekspor Jenis Bahan.
             *
             * File ekspor menggunakan Kode Kategori agar
             * lebih mudah dibaca dan dapat digunakan kembali
             * sebagai dasar file impor.
             */
            ExportAction::make()
                ->label('Ekspor Data')
                ->icon('heroicon-o-arrow-down-tray')
                ->exporter(WasteTypeExporter::class),
        ];
    }
}
