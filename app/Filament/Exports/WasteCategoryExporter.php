<?php

namespace App\Filament\Exports;

use App\Models\WasteCategory;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class WasteCategoryExporter extends Exporter
{
    /**
     * Model teknis yang diekspor.
     *
     * Dalam aplikasi, WasteCategory kita tampilkan
     * sebagai "Kategori Bahan".
     */
    protected static ?string $model = WasteCategory::class;

    /**
     * Menentukan kolom yang masuk ke file hasil ekspor.
     *
     * Kolom ID database sengaja tidak disertakan
     * karena bukan identitas bisnis Kategori Bahan.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Kode unik kategori.
             *
             * Contoh:
             * PLS = Plastik
             * KRT = Kertas
             * LOG = Logam
             */
            ExportColumn::make('code')
                ->label('Kode Kategori'),

            /**
             * Nama kategori bahan.
             */
            ExportColumn::make('name')
                ->label('Nama Kategori'),

            /**
             * Status aktif kategori.
             *
             * Nilai:
             * 1 = aktif
             * 0 = tidak aktif
             */
            ExportColumn::make('is_active')
                ->label('Aktif'),

            /**
             * Tanggal data dibuat.
             * Digunakan sebagai informasi/audit.
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
        $body = 'Ekspor data Kategori Bahan selesai. '
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
