<?php

namespace App\Filament\Resources\InventoryMovements\Pages;

use App\Filament\Exports\InventoryMovementExporter;
use App\Filament\Resources\InventoryMovements\InventoryMovementResource;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListInventoryMovements extends ListRecords
{
    /**
     * Resource teknis untuk fitur Mutasi Persediaan.
     */
    protected static string $resource = InventoryMovementResource::class;

    /**
     * Tombol aksi yang tampil di bagian atas halaman.
     *
     * Mutasi Persediaan hanya boleh diekspor.
     * Tidak ada fitur:
     * - Tambah
     * - Impor
     * - Edit
     * - Hapus
     *
     * Karena seluruh mutasi harus berasal otomatis
     * dari transaksi yang sah.
     */
    protected function getHeaderActions(): array
    {
        return [
            /**
             * Mengekspor riwayat Mutasi Persediaan.
             *
             * Hasil ekspor menampilkan:
             * - Tanggal
             * - Jenis Bahan
             * - Barang Masuk
             * - Barang Keluar
             * - Stok
             * - Keterangan
             */
            ExportAction::make()
                ->label('Ekspor Data')
                ->icon('heroicon-o-arrow-down-tray')
                ->exporter(InventoryMovementExporter::class),
        ];
    }
}
