<?php

namespace App\Filament\Imports;

use App\Models\Customer;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class CustomerImporter extends Importer
{
    /**
     * Model teknis yang dikelola importer ini.
     *
     * Dalam UI dan pembelajaran kita menyebutnya "Nasabah".
     */
    protected static ?string $model = Customer::class;

    /**
     * Menentukan kolom yang boleh diimpor.
     *
     * Catatan penting:
     * Saldo TIDAK boleh diimpor dari file.
     * Saldo harus terbentuk dari Setoran, Penarikan,
     * dan Mutasi Saldo agar integritas keuangan tetap terjaga.
     */
    public static function getColumns(): array
    {
        return [

            /**
             * Kode Nasabah menjadi identitas unik.
             *
             * Contoh:
             * NSB-0001
             * NSB-0002
             */
            ImportColumn::make('customer_code')
                ->label('Kode Nasabah')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Nama lengkap Nasabah.
             */
            ImportColumn::make('name')
                ->label('Nama Nasabah')
                ->requiredMapping()
                ->rules([
                    'required',
                    'max:255',
                ]),

            /**
             * Nomor HP bersifat opsional.
             *
             * Nama field harus sesuai database:
             * phone_number
             */
            ImportColumn::make('phone_number')
                ->label('Nomor HP')
                ->rules([
                    'nullable',
                    'max:255',
                ]),

            /**
             * Alamat Nasabah bersifat opsional.
             */
            ImportColumn::make('address')
                ->label('Alamat'),

            /**
             * Status aktif Nasabah.
             *
             * Nilai yang diterima berupa boolean,
             * misalnya:
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
     * Menentukan apakah data akan dibuat baru
     * atau memperbarui Nasabah yang sudah ada.
     *
     * Kunci pencocokan menggunakan customer_code.
     *
     * Jika kode belum ada:
     * -> buat Nasabah baru.
     *
     * Jika kode sudah ada:
     * -> perbarui Nasabah yang sama.
     *
     * Dengan pola ini, impor file yang sama tidak otomatis
     * menggandakan data Nasabah.
     */
    public function resolveRecord(): Customer
    {
        return Customer::firstOrNew([
            'customer_code' => $this->data['customer_code'],
        ]);
    }

    /**
     * Pesan yang ditampilkan setelah proses impor selesai.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Impor data Nasabah selesai. '
            .Number::format($import->successful_rows)
            .' baris berhasil diimpor.';

        /**
         * Jika ada baris gagal, tampilkan jumlah kegagalannya.
         */
        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '
                .Number::format($failedRowsCount)
                .' baris gagal diimpor.';
        }

        return $body;
    }
}
