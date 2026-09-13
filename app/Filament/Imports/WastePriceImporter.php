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
     * Format CSV:
     *
     * Kode Bahan | Nama Bahan | Harga |
     * Berlaku Mulai | Berlaku Sampai | Aktif
     *
     * Kode Bahan digunakan untuk mencocokkan Jenis Bahan.
     * Nama Bahan hanya informasi bagi pengguna.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Kode Bahan langsung dipetakan ke relasi wasteType().
             *
             * Contoh:
             * LGM-BSI -> BESI
             *
             * Sistem mencari berdasarkan kolom "code"
             * pada tabel waste_types, bukan berdasarkan ID.
             */
            ImportColumn::make('wasteType')
                ->label('Kode Bahan')
                ->exampleHeader('Kode Bahan')
                ->example('LGM-BSI')
                ->requiredMapping()
                ->guess([
                    'Kode Bahan',
                    'kode bahan',
                    'wasteType',
                ])
                ->relationship(
                    resolveUsing: function (string $state): ?WasteType {
                        return WasteType::query()
                            ->where('code', trim($state))
                            ->first();
                    }
                ),

            /**
             * Nama Bahan hanya membantu pengguna mengetahui
             * bahan yang dimaksud.
             *
             * Kolom ini tidak disimpan ke tabel waste_prices.
             */
            ImportColumn::make('waste_type_name')
                ->label('Nama Bahan')
                ->exampleHeader('Nama Bahan')
                ->example('BESI')
                ->guess([
                    'Nama Bahan',
                    'nama bahan',
                    'waste_type_name',
                ])
                ->rules([
                    'nullable',
                    'max:255',
                ])
                ->fillRecordUsing(
                    function (WastePrice $record, $state): void {
                        /**
                         * Sengaja dikosongkan.
                         *
                         * Nama Bahan hanya informasi pada CSV.
                         * Sumber kebenaran nama tetap berasal
                         * dari master Jenis Bahan.
                         */
                    }
                ),

            /**
             * Harga beli bahan.
             */
            ImportColumn::make('price')
                ->label('Harga')
                ->exampleHeader('Harga')
                ->example('3000')
                ->requiredMapping()
                ->guess([
                    'Harga',
                    'harga',
                    'price',
                ])
                ->numeric(decimalPlaces: 2)
                ->rules([
                    'required',
                    'numeric',
                    'min:0',
                ]),

            /**
             * Tanggal mulai berlakunya harga.
             *
             * Format:
             * YYYY-MM-DD
             *
             * Contoh:
             * 2026-09-11
             */
            ImportColumn::make('effective_from')
                ->label('Berlaku Mulai')
                ->exampleHeader('Berlaku Mulai')
                ->example('2026-09-11')
                ->requiredMapping()
                ->guess([
                    'Berlaku Mulai',
                    'berlaku mulai',
                    'effective_from',
                ])
                ->castStateUsing(
                    function ($state): ?string {
                        if (blank($state)) {
                            return null;
                        }

                        return Carbon::parse($state)->toDateString();
                    }
                )
                ->rules([
                    'required',
                    'date',
                ]),

            /**
             * Tanggal akhir berlakunya harga.
             *
             * Boleh kosong apabila harga masih berlaku.
             */
            ImportColumn::make('effective_until')
                ->label('Berlaku Sampai')
                ->exampleHeader('Berlaku Sampai')
                ->example('2026-12-31')
                ->guess([
                    'Berlaku Sampai',
                    'berlaku sampai',
                    'effective_until',
                ])
                ->castStateUsing(
                    function ($state): ?string {
                        if (blank($state)) {
                            return null;
                        }

                        return Carbon::parse($state)->toDateString();
                    }
                )
                ->rules([
                    'nullable',
                    'date',
                ]),

            /**
             * Status aktif.
             *
             * 1 = Aktif
             * 0 = Tidak Aktif
             */
            ImportColumn::make('is_active')
                ->label('Aktif')
                ->exampleHeader('Aktif')
                ->example('1')
                ->requiredMapping()
                ->guess([
                    'Aktif',
                    'aktif',
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
     * Menentukan apakah Harga Bahan dibuat baru
     * atau memperbarui data yang sudah ada.
     *
     * Identitas Harga Bahan:
     * - Kode Bahan
     * - Tanggal Berlaku Mulai
     */
    public function resolveRecord(): WastePrice
    {
        /**
         * Nilai wasteType pada tahap ini masih berupa
         * Kode Bahan dari CSV.
         */
        $kodeBahan = trim(
            (string) $this->data['wasteType']
        );

        /**
         * Cari Jenis Bahan berdasarkan Kode Bahan.
         */
        $jenisBahan = WasteType::query()
            ->where('code', $kodeBahan)
            ->first();

        /**
         * Jangan pernah membuat harga untuk
         * Kode Bahan yang tidak ada pada master.
         */
        if (! $jenisBahan) {
            throw new RowImportFailedException(
                "Kode Bahan [{$kodeBahan}] tidak ditemukan pada master Jenis Bahan."
            );
        }

        /**
         * Normalisasi tanggal mulai berlaku.
         */
        $tanggalMulai = Carbon::parse(
            $this->data['effective_from']
        )->toDateString();

        /**
         * Jika kombinasi bahan + tanggal sudah ada,
         * record lama diperbarui.
         *
         * Jika belum ada, record baru dibuat.
         */
        return WastePrice::firstOrNew([
            'waste_type_id' => $jenisBahan->id,
            'effective_from' => $tanggalMulai,
        ]);
    }

    /**
     * Pesan notifikasi setelah impor selesai.
     */
    public static function getCompletedNotificationBody(
        Import $import
    ): string {
        $body = 'Impor data Harga Bahan selesai. '
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
