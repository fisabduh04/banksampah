<?php

namespace App\Filament\Resources\WastePrices\Pages;

use App\Filament\Exports\WastePriceExporter;
use App\Filament\Imports\WastePriceImporter;
use App\Filament\Resources\WastePrices\WastePriceResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListWastePrices extends ListRecords
{
    /**
     * Resource teknis untuk fitur Harga Bahan.
     */
    protected static string $resource = WastePriceResource::class;

    /**
     * Tombol aksi pada halaman Harga Bahan.
     *
     * Master Harga Bahan boleh:
     * - ditambah manual;
     * - diimpor dari CSV;
     * - diekspor ke file.
     */
    protected function getHeaderActions(): array
    {
        return [
            /**
             * Menambah Harga Bahan secara manual.
             */
            CreateAction::make()
                ->label('Tambah Harga')
                ->icon('heroicon-o-plus'),

            /**
             * Mengimpor Harga Bahan dari CSV.
             *
             * Catatan:
             * Data dapat disiapkan di Microsoft Excel,
             * kemudian Save As menjadi CSV UTF-8.
             *
             * Kode Bahan + Tanggal Berlaku Mulai
             * menjadi dasar pencocokan histori harga.
             */
            ImportAction::make()
                ->label('Impor Data')
                ->icon('heroicon-o-arrow-up-tray')
                ->importer(WastePriceImporter::class),

            /**
             * Mengekspor histori Harga Bahan.
             */
            ExportAction::make()
                ->label('Ekspor Data')
                ->icon('heroicon-o-arrow-down-tray')
                ->exporter(WastePriceExporter::class),
        ];
    }
}
