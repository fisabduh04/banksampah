<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Exports\CustomerExporter;
use App\Filament\Imports\CustomerImporter;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    /**
     * Resource teknis untuk fitur Nasabah.
     */
    protected static string $resource = CustomerResource::class;

    /**
     * Tombol aksi yang tampil di bagian atas halaman Nasabah.
     *
     * Urutannya:
     * 1. Tambah Nasabah
     * 2. Impor Data
     * 3. Ekspor Data
     */
    protected function getHeaderActions(): array
    {
        return [
            /**
             * Menambah Nasabah secara manual melalui form.
             */
            CreateAction::make()
                ->label('Tambah Nasabah')
                ->icon('heroicon-o-plus'),

            /**
             * Mengimpor data Nasabah dari file CSV.
             *
             * Importer menggunakan Kode Nasabah sebagai kunci,
             * sehingga:
             * - kode baru -> membuat Nasabah baru;
             * - kode lama -> memperbarui Nasabah yang sudah ada.
             */
            ImportAction::make()
                ->label('Impor Data')
                ->icon('heroicon-o-arrow-up-tray')
                ->importer(CustomerImporter::class),

            /**
             * Mengekspor data Nasabah.
             *
             * Exporter dapat digunakan untuk menghasilkan
             * file data Nasabah yang dapat dibuka di Excel.
             */
            ExportAction::make()
                ->label('Ekspor Data')
                ->icon('heroicon-o-arrow-down-tray')
                ->exporter(CustomerExporter::class),
        ];
    }
}
