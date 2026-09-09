<?php

namespace App\Filament\Resources\WasteCategories\Pages;

use App\Filament\Exports\WasteCategoryExporter;
use App\Filament\Imports\WasteCategoryImporter;
use App\Filament\Resources\WasteCategories\WasteCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListWasteCategories extends ListRecords
{
    /**
     * Resource teknis untuk fitur Kategori Bahan.
     */
    protected static string $resource = WasteCategoryResource::class;

    /**
     * Tombol aksi yang tampil di bagian atas halaman Kategori Bahan.
     *
     * Terdiri dari:
     * - Tambah Kategori
     * - Impor Data
     * - Ekspor Data
     */
    protected function getHeaderActions(): array
    {
        return [
            /**
             * Menambah Kategori Bahan secara manual melalui form.
             */
            CreateAction::make()
                ->label('Tambah Kategori')
                ->icon('heroicon-o-plus'),

            /**
             * Mengimpor data Kategori Bahan dari file CSV.
             *
             * Kunci pencocokan menggunakan kolom "code",
             * sehingga data dengan kode yang sama akan diperbarui,
             * bukan dibuat menjadi duplikat.
             */
            ImportAction::make()
                ->label('Impor Data')
                ->icon('heroicon-o-arrow-up-tray')
                ->importer(WasteCategoryImporter::class),

            /**
             * Mengekspor data Kategori Bahan.
             *
             * Hasil ekspor dapat digunakan untuk arsip
             * atau pengolahan lebih lanjut di Excel.
             */
            ExportAction::make()
                ->label('Ekspor Data')
                ->icon('heroicon-o-arrow-down-tray')
                ->exporter(WasteCategoryExporter::class),
        ];
    }
}
