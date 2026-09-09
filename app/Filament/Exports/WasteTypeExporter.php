<?php

namespace App\Filament\Exports;

use App\Models\WasteType;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class WasteTypeExporter extends Exporter
{
    /**
     * Model teknis yang diekspor.
     *
     * Dalam aplikasi, WasteType ditampilkan sebagai "Jenis Bahan".
     */
    protected static ?string $model = WasteType::class;

    /**
     * Menentukan kolom yang masuk ke file hasil ekspor.
     *
     * Catatan:
     * - ID database tidak diekspor.
     * - waste_category_id juga tidak diekspor karena terlalu teknis.
     * - Sebagai gantinya, kita tampilkan Kode Kategori dari relasi category.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Kode Kategori Bahan.
             *
             * Contoh:
             * LOG = Logam
             * KRT = Kertas
             * PLS = Plastik
             */
            ExportColumn::make('category.code')
                ->label('Kode Kategori'),

            /**
             * Kode unik Jenis Bahan.
             */
            ExportColumn::make('code')
                ->label('Kode Bahan'),

            /**
             * Nama Jenis Bahan.
             */
            ExportColumn::make('name')
                ->label('Nama Bahan'),

            /**
             * Satuan bahan.
             *
             * Contoh:
             * kg
             */
            ExportColumn::make('unit')
                ->label('Satuan'),

            /**
             * Status aktif Jenis Bahan.
             *
             * Nilai:
             * 1 = aktif
             * 0 = tidak aktif
             */
            ExportColumn::make('is_active')
                ->label('Aktif'),

            /**
             * Tanggal data dibuat.
             */
            ExportColumn::make('created_at')
                ->label('Tanggal Dibuat'),

            /**
             * Waktu terakhir data diperbarui.
             */
            ExportColumn::make('updated_at')
                ->label('Terakhir Diperbarui'),
        ];
    }

    /**
     * Pesan notifikasi setelah proses ekspor selesai.
     */
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Ekspor data Jenis Bahan selesai. '
            .Number::format($export->successful_rows)
            .' baris berhasil diekspor.';

        /**
         * Jika ada data gagal diekspor,
         * tampilkan jumlah kegagalannya.
         */
        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '
                .Number::format($failedRowsCount)
                .' baris gagal diekspor.';
        }

        return $body;
    }
}
