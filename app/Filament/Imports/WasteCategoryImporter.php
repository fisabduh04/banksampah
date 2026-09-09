<?php

namespace App\Filament\Imports;

use App\Models\WasteCategory;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class WasteCategoryImporter extends Importer
{
    /**
     * Model teknis yang dikelola importer ini.
     *
     * Dalam aplikasi, WasteCategory kita tampilkan
     * sebagai "Kategori Bahan".
     */
    protected static ?string $model = WasteCategory::class;

    /**
     * Menentukan kolom yang boleh diimpor.
     *
     * Kategori Bahan adalah master data,
     * sehingga aman untuk diimpor melalui file CSV.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Kode Kategori menjadi identitas unik.
             *
             * Contoh:
             * PLS = Plastik
             * KRT = Kertas
             * LOG = Logam
             */
            ImportColumn::make('code')
                ->label('Kode Kategori')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Nama Kategori Bahan.
             */
            ImportColumn::make('name')
                ->label('Nama Kategori')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Status aktif kategori.
             *
             * Nilai:
             * 1 = aktif
             * 0 = tidak aktif
             */
            ImportColumn::make('is_active')
                ->label('Aktif')
                ->requiredMapping()
                ->boolean()
                ->rules([
                    'required',
                    'boolean',
                ]),
        ];
    }

    /**
     * Menentukan apakah data dibuat baru
     * atau memperbarui data lama.
     *
     * Pencocokan menggunakan kolom "code".
     *
     * Jika kode belum ada:
     * -> buat Kategori Bahan baru.
     *
     * Jika kode sudah ada:
     * -> perbarui Kategori Bahan yang sama.
     */
    public function resolveRecord(): WasteCategory
    {
        return WasteCategory::firstOrNew([
            'code' => $this->data['code'],
        ]);
    }

    /**
     * Pesan notifikasi setelah proses impor selesai.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Impor data Kategori Bahan selesai. '
            .Number::format($import->successful_rows)
            .' baris berhasil diimpor.';

        /**
         * Jika ada data gagal, tampilkan jumlah kegagalannya.
         */
        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '
                .Number::format($failedRowsCount)
                .' baris gagal diimpor.';
        }

        return $body;
    }
}
