<?php

namespace App\Filament\Imports;

use App\Models\WasteCategory;
use App\Models\WasteType;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class WasteTypeImporter extends Importer
{
    /**
     * Model teknis untuk fitur Jenis Bahan.
     */
    protected static ?string $model = WasteType::class;

    /**
     * Format impor dibuat sama dengan format ekspor:
     *
     * Kategori | Kode Bahan | Nama Bahan | Satuan | Aktif
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Nama Kategori Bahan.
             *
             * User cukup mengisi:
             * Kertas
             * Logam
             * Organik
             * Plastik
             *
             * Nilai ini kemudian diterjemahkan menjadi
             * waste_category_id secara otomatis.
             */
            ImportColumn::make('category_name')
                ->label('Kategori')
                ->exampleHeader('Kategori')
                ->example('Kertas')
                ->requiredMapping()
                ->guess([
                    'Kategori',
                    'category_name',
                ])
                ->rules([
                    'required',
                    'max:255',
                ])
                ->fillRecordUsing(
                    function (WasteType $record, string $state): void {
                        /**
                         * Cari kategori berdasarkan nama yang
                         * diisi pada file CSV.
                         */
                        $category = WasteCategory::query()
                            ->where('name', trim($state))
                            ->first();

                        /**
                         * Jika kategori tidak ditemukan,
                         * hanya baris tersebut yang gagal diimpor.
                         */
                        if (! $category) {
                            throw new RowImportFailedException(
                                "Kategori Bahan [{$state}] tidak ditemukan."
                            );
                        }

                        /**
                         * Simpan ID kategori ke foreign key
                         * Jenis Bahan.
                         */
                        $record->waste_category_id = $category->id;
                    }
                ),

            /**
             * Kode unik Jenis Bahan.
             *
             * Digunakan sebagai kunci upsert.
             */
            ImportColumn::make('code')
                ->label('Kode Bahan')
                ->exampleHeader('Kode Bahan')
                ->example('KRT-KRD')
                ->requiredMapping()
                ->guess([
                    'Kode Bahan',
                    'code',
                ])
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Nama Jenis Bahan.
             */
            ImportColumn::make('name')
                ->label('Nama Bahan')
                ->exampleHeader('Nama Bahan')
                ->example('KARDUS')
                ->requiredMapping()
                ->guess([
                    'Nama Bahan',
                    'name',
                ])
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Satuan bahan.
             */
            ImportColumn::make('unit')
                ->label('Satuan')
                ->exampleHeader('Satuan')
                ->example('kg')
                ->requiredMapping()
                ->guess([
                    'Satuan',
                    'unit',
                ])
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Status aktif:
             * 1 = aktif
             * 0 = tidak aktif
             */
            ImportColumn::make('is_active')
                ->label('Aktif')
                ->exampleHeader('Aktif')
                ->example('1')
                ->requiredMapping()
                ->guess([
                    'Aktif',
                    'is_active',
                ])
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
     * Kode Bahan menjadi kunci pencocokan.
     */
    public function resolveRecord(): WasteType
    {
        return WasteType::firstOrNew([
            'code' => $this->data['code'],
        ]);
    }

    /**
     * Notifikasi setelah proses impor selesai.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Impor data Jenis Bahan selesai. '
            .Number::format($import->successful_rows)
            .' baris berhasil diimpor.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '
                .Number::format($failedRowsCount)
                .' baris gagal diimpor.';
        }

        return $body;
    }
}
