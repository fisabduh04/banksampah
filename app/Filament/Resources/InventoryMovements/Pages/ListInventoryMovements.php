<?php

namespace App\Filament\Resources\InventoryMovements\Pages;

use App\Filament\Concerns\UsesIndonesianLocale;
use App\Filament\Exports\InventoryMovementExporter;
use App\Filament\Resources\InventoryMovements\InventoryMovementResource;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListInventoryMovements extends ListRecords
{
    use UsesIndonesianLocale;

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
    public function getSubheading(): ?string
    {
        return 'Biaya historis disajikan kembali pada tanggal transaksi sumber. Koreksi nilai tidak menambah barang masuk atau keluar. Tanggal pembukuan dan waktu pencatatan tetap ditampilkan untuk audit.';
    }

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
