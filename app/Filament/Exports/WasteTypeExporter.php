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
     * Model teknis untuk fitur Jenis Bahan.
     */
    protected static ?string $model = WasteType::class;

    /**
     * Kolom ekspor sengaja dibuat sama dengan format impor.
     *
     * Tujuannya:
     * Ekspor -> Edit di Excel -> Save As CSV UTF-8 -> Impor kembali.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Nama Kategori Bahan.
             *
             * User tidak perlu mengetahui ID ataupun kode internal kategori.
             */
            ExportColumn::make('category.name')
                ->label('Kategori'),

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
             */
            ExportColumn::make('unit')
                ->label('Satuan'),

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
        $body = 'Ekspor data Jenis Bahan selesai. '
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
