<?php

namespace App\Filament\Exports;

use App\Models\WastePrice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class WastePriceExporter extends Exporter
{
    /**
     * Model teknis yang diekspor.
     *
     * Dalam aplikasi, WastePrice ditampilkan
     * kepada pengguna sebagai "Harga Bahan".
     */
    protected static ?string $model = WastePrice::class;

    /**
     * Menentukan kolom yang masuk ke file hasil ekspor.
     *
     * Catatan:
     * - ID database tidak diekspor.
     * - Kode Bahan digunakan sebagai identitas bisnis.
     * - Kode Bahan juga digunakan kembali pada proses impor.
     */
    /**
     * Kolom ekspor sengaja dibuat sama dengan format impor.
     *
     * Tujuannya:
     * file hasil ekspor dapat diedit di Excel,
     * disimpan kembali sebagai CSV UTF-8,
     * kemudian langsung digunakan untuk Impor Data.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Kode Bahan digunakan untuk mencari Jenis Bahan.
             */
            ExportColumn::make('wasteType.code')
                ->label('Kode Bahan'),

            /**
             * Nama Bahan.
             */
            ExportColumn::make('wasteType.name')
                ->label('Jenis Bahan'),

            /**
             * Harga bahan.
             */
            ExportColumn::make('price')
                ->label('Harga'),

            /**
             * Tanggal mulai berlakunya harga.
             */
            ExportColumn::make('effective_from')
                ->label('Berlaku Mulai'),

            /**
             * Tanggal akhir berlakunya harga.
             * Boleh kosong jika harga masih berlaku.
             */
            ExportColumn::make('effective_until')
                ->label('Berlaku Sampai'),

            /**
             * Status aktif.
             *
             * 1 = aktif
             * 0 = tidak aktif
             */
            ExportColumn::make('is_active')
                ->label('Aktif'),
        ];
    }

    /**
     * Menampilkan notifikasi setelah proses ekspor selesai.
     */
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Ekspor data Harga Bahan selesai. '
            .Number::format($export->successful_rows)
            .' baris berhasil diekspor.';

        /**
         * Jika ada baris gagal diekspor,
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
