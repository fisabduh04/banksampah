<?php

namespace App\Filament\Imports;

use App\Models\WasteCategory;
use App\Models\WasteType;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class WasteTypeImporter extends Importer
{
    /**
     * Model teknis yang dikelola importer ini.
     *
     * Dalam aplikasi, WasteType kita tampilkan
     * sebagai "Jenis Bahan".
     */
    protected static ?string $model = WasteType::class;

    /**
     * Menentukan kolom yang boleh diimpor.
     *
     * Catatan:
     * User tidak perlu mengetahui ID database kategori.
     * Cukup isi Kode Kategori seperti LOG, KRT, atau PLS.
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
             *
             * Nilai ini nanti akan dikonversi otomatis
             * menjadi waste_category_id.
             */
            ImportColumn::make('category_code')
                ->label('Kode Kategori')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Kode unik Jenis Bahan.
             *
             * Contoh:
             * BSI = Besi
             * KRD = Kardus
             * PET = Botol PET
             */
            ImportColumn::make('code')
                ->label('Kode Bahan')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Nama Jenis Bahan.
             */
            ImportColumn::make('name')
                ->label('Nama Bahan')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Satuan bahan.
             *
             * Umumnya menggunakan kg.
             */
            ImportColumn::make('unit')
                ->label('Satuan')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Status aktif Jenis Bahan.
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
     * atau memperbarui Jenis Bahan yang sudah ada.
     *
     * Kunci pencocokan menggunakan kolom "code".
     *
     * Jika kode belum ada:
     * -> buat Jenis Bahan baru.
     *
     * Jika kode sudah ada:
     * -> perbarui Jenis Bahan yang sama.
     */
    public function resolveRecord(): WasteType
    {
        return WasteType::firstOrNew([
            'code' => $this->data['code'],
        ]);
    }

    /**
     * Menyiapkan data sebelum disimpan.
     *
     * Kode kategori dari file CSV dicari ke tabel
     * Kategori Bahan, lalu dikonversi menjadi ID database.
     */
    protected function mutateBeforeSave(): void
    {
        /**
         * Cari Kategori Bahan berdasarkan kode.
         */
        $category = WasteCategory::query()
            ->where('code', $this->data['category_code'])
            ->first();

        /**
         * Jika kode kategori tidak ditemukan,
         * proses impor baris ini dihentikan.
         */
        if (! $category) {
            throw new \Exception(
                'Kode Kategori '
                .$this->data['category_code']
                .' tidak ditemukan.'
            );
        }

        /**
         * Simpan foreign key kategori ke record Jenis Bahan.
         */
        $this->record->waste_category_id = $category->id;
    }

    /**
     * Pesan notifikasi setelah proses impor selesai.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Impor data Jenis Bahan selesai. '
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
