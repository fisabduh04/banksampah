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
     * Model teknis untuk fitur Harga Bahan.
     */
    protected static ?string $model = WastePrice::class;

    /**
     * Format ekspor dibuat sama dengan format impor.
     *
     * Tujuannya:
     * Ekspor -> Edit di Excel -> Save As CSV UTF-8 -> Impor kembali.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Kode Bahan menjadi kunci utama pencocokan.
             */
            ExportColumn::make('wasteType.code')
                ->label('Kode Bahan'),

            /**
             * Nama Bahan ditampilkan agar user mengetahui
             * bahan yang sedang dilihat.
             *
             * Nama tidak digunakan sebagai kunci update.
             */
            ExportColumn::make('wasteType.name')
                ->label('Nama Bahan'),

            /**
             * Harga bahan.
             */
            ExportColumn::make('price')
                ->label('Harga'),

            /**
             * Tanggal mulai berlaku.
             */
            ExportColumn::make('effective_from')
                ->label('Berlaku Mulai'),

            /**
             * Tanggal akhir berlaku.
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
     * Notifikasi setelah proses ekspor selesai.
     */
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Ekspor data Harga Bahan selesai. '
            .Number::format($export->successful_rows)
            .' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '
                .Number::format($failedRowsCount)
                .' baris gagal diekspor.';
        }

        return $body;
    }
}
