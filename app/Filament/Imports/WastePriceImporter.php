<?php

namespace App\Filament\Imports;

use App\Models\WastePrice;
use App\Models\WasteType;
use Carbon\Carbon;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class WastePriceImporter extends Importer
{
    /**
     * Model teknis untuk fitur Harga Bahan.
     */
    protected static ?string $model = WastePrice::class;

    /**
     * Format file impor:
     *
     * Kode Bahan | Harga | Berlaku Mulai | Berlaku Sampai | Aktif
     *
     * File hasil ekspor dapat diedit di Excel,
     * disimpan kembali sebagai CSV UTF-8,
     * kemudian diimpor kembali.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * File CSV berisi Kode Bahan,
             * tetapi database menyimpan waste_type_id.
             *
             * Karena itu Kode Bahan dikonversi
             * menjadi ID Jenis Bahan di sini.
             */
            ImportColumn::make('waste_type_id')
                ->label('Kode Bahan')
                ->requiredMapping()
                ->guess([
                    'Kode Bahan',
                    'kode_bahan',
                ])
                ->castStateUsing(function ($state) {
                    $kodeBahan = trim((string) $state);

                    $jenisBahan = WasteType::query()
                        ->where('code', $kodeBahan)
                        ->first();

                    if (! $jenisBahan) {
                        throw new RowImportFailedException(
                            "Kode Bahan [{$kodeBahan}] tidak ditemukan."
                        );
                    }

                    return $jenisBahan->id;
                })
                ->rules([
                    'required',
                    'integer',
                ]),

            /**
             * Harga bahan.
             */
            ImportColumn::make('price')
                ->label('Harga')
                ->requiredMapping()
                ->numeric(decimalPlaces: 2)
                ->rules([
                    'required',
                    'numeric',
                    'min:0',
                ]),

            /**
             * Tanggal mulai berlakunya harga.
             *
             * Contoh dari Excel:
             * 9/3/2026 0:00
             *
             * Dinormalisasi menjadi:
             * 2026-09-03
             */
            ImportColumn::make('effective_from')
                ->label('Berlaku Mulai')
                ->requiredMapping()
                ->castStateUsing(
                    fn ($state) => Carbon::parse($state)->toDateString()
                )
                ->rules([
                    'required',
                    'date',
                ]),

            /**
             * Tanggal akhir berlaku.
             * Boleh kosong.
             */
            ImportColumn::make('effective_until')
                ->label('Berlaku Sampai')
                ->castStateUsing(
                    fn ($state) => blank($state)
                            ? null
                            : Carbon::parse($state)->toDateString()
                )
                ->rules([
                    'nullable',
                    'date',
                ]),

            /**
             * Status aktif:
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
     * Menentukan apakah data:
     * - memperbarui Harga Bahan lama; atau
     * - membuat histori Harga Bahan baru.
     *
     * Kunci pencocokan:
     * Jenis Bahan + Tanggal Berlaku Mulai.
     */
    public function resolveRecord(): WastePrice
    {
        $jenisBahanId = $this->data['waste_type_id'];

        $tanggalMulai = Carbon::parse(
            $this->data['effective_from']
        )->toDateString();

        /**
         * Jika kombinasi bahan + tanggal sudah ada,
         * Filament akan memperbarui record tersebut.
         *
         * Jika belum ada, dibuat record baru.
         */
        return WastePrice::firstOrNew([
            'waste_type_id' => $jenisBahanId,
            'effective_from' => $tanggalMulai,
        ]);
    }

    /**
     * Notifikasi setelah proses impor selesai.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Impor data Harga Bahan selesai. '
            .Number::format($import->successful_rows)
            .' baris berhasil diproses.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '
                .Number::format($failedRowsCount)
                .' baris gagal diproses.';
        }

        return $body;
    }
}
