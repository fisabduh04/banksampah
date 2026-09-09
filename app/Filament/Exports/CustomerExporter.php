<?php

namespace App\Filament\Exports;

use App\Models\Customer;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class CustomerExporter extends Exporter
{
    /**
     * Model teknis yang diekspor.
     *
     * Dalam aplikasi, Customer kita tampilkan sebagai "Nasabah".
     */
    protected static ?string $model = Customer::class;

    /**
     * Menentukan kolom yang masuk ke file hasil ekspor.
     *
     * ID database sengaja tidak diekspor karena bukan
     * identitas bisnis Nasabah.
     *
     * Kode Nasabah digunakan sebagai identitas utama
     * dan juga sebagai kunci ketika data diimpor kembali.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Kode unik Nasabah.
             *
             * Contoh:
             * NSB-0001
             * NSB-0002
             */
            ExportColumn::make('customer_code')
                ->label('Kode Nasabah'),

            /**
             * Nama lengkap Nasabah.
             */
            ExportColumn::make('name')
                ->label('Nama Nasabah'),

            /**
             * Nomor telepon/HP Nasabah.
             */
            ExportColumn::make('phone_number')
                ->label('Nomor HP'),

            /**
             * Alamat Nasabah.
             */
            ExportColumn::make('address')
                ->label('Alamat'),

            /**
             * Status aktif Nasabah.
             *
             * Database menyimpan nilai boolean:
             * 1 = aktif
             * 0 = tidak aktif
             *
             * Pada file ekspor kita tetap mempertahankan
             * nilai tersebut agar mudah diimpor kembali.
             */
            ExportColumn::make('is_active')
                ->label('Aktif'),

            /**
             * Informasi tanggal pembuatan data.
             *
             * Kolom ini hanya untuk informasi/audit.
             */
            ExportColumn::make('created_at')
                ->label('Tanggal Dibuat'),

            /**
             * Informasi waktu terakhir data diperbarui.
             */
            ExportColumn::make('updated_at')
                ->label('Terakhir Diperbarui'),
        ];
    }

    /**
     * Notifikasi yang ditampilkan setelah proses ekspor selesai.
     */
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Ekspor data Nasabah selesai. '
            .Number::format($export->successful_rows)
            .' baris berhasil diekspor.';

        /**
         * Jika ada data yang gagal diekspor,
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
