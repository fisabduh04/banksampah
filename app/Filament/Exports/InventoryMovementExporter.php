<?php

namespace App\Filament\Exports;

use App\Models\InventoryMovement;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class InventoryMovementExporter extends Exporter
{
    /**
     * Model teknis yang diekspor.
     *
     * Dalam aplikasi, InventoryMovement kita tampilkan
     * sebagai "Mutasi Persediaan".
     */
    protected static ?string $model = InventoryMovement::class;

    /**
     * Menentukan kolom yang masuk ke file ekspor.
     *
     * Kolom teknis seperti ID, reference_type, dan reference_id
     * sengaja tidak ditampilkan agar file lebih mudah dipahami user.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Tanggal terjadinya mutasi persediaan.
             */
            ExportColumn::make('transaction_date')
                ->label('Tanggal'),

            /**
             * Nama Jenis Bahan.
             *
             * Diambil melalui relasi wasteType().
             */
            ExportColumn::make('wasteType.name')
                ->label('Jenis Bahan'),

            /**
             * Barang masuk.
             *
             * Hanya menampilkan quantity jika movement_type = in.
             */
            ExportColumn::make('barang_masuk')
                ->label('Masuk')
                ->state(
                    fn (InventoryMovement $record) => $record->movement_type === 'in'
                            ? $record->quantity
                            : null
                ),

            /**
             * Barang keluar.
             *
             * Hanya menampilkan quantity jika movement_type = out.
             */
            ExportColumn::make('barang_keluar')
                ->label('Keluar')
                ->state(
                    fn (InventoryMovement $record) => $record->movement_type === 'out'
                            ? $record->quantity
                            : null
                ),

            /**
             * Menghitung stok berjalan sampai transaksi ini.
             *
             * Rumus:
             * Total Masuk - Total Keluar
             *
             * Perhitungan dilakukan per Jenis Bahan.
             */
            ExportColumn::make('stok')
                ->label('Stok')
                ->state(function (InventoryMovement $record): float {
                    $totalMasuk = InventoryMovement::query()
                        ->where('waste_type_id', $record->waste_type_id)
                        ->where(function (Builder $query) use ($record) {
                            $query
                                ->whereDate(
                                    'transaction_date',
                                    '<',
                                    $record->transaction_date
                                )
                                ->orWhere(function (Builder $query) use ($record) {
                                    $query
                                        ->whereDate(
                                            'transaction_date',
                                            $record->transaction_date
                                        )
                                        ->where('id', '<=', $record->id);
                                });
                        })
                        ->where('movement_type', 'in')
                        ->sum('quantity');

                    $totalKeluar = InventoryMovement::query()
                        ->where('waste_type_id', $record->waste_type_id)
                        ->where(function (Builder $query) use ($record) {
                            $query
                                ->whereDate(
                                    'transaction_date',
                                    '<',
                                    $record->transaction_date
                                )
                                ->orWhere(function (Builder $query) use ($record) {
                                    $query
                                        ->whereDate(
                                            'transaction_date',
                                            $record->transaction_date
                                        )
                                        ->where('id', '<=', $record->id);
                                });
                        })
                        ->where('movement_type', 'out')
                        ->sum('quantity');

                    return (float) $totalMasuk - (float) $totalKeluar;
                }),

            /**
             * Keterangan sumber perubahan stok.
             *
             * Contoh:
             * Setoran nasabah ST-2026-000006
             * Pembatalan setoran ST-2026-000006
             */
            ExportColumn::make('description')
                ->label('Keterangan'),
        ];
    }

    /**
     * Notifikasi setelah proses ekspor selesai.
     */
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Ekspor data Mutasi Persediaan selesai. '
            .Number::format($export->successful_rows)
            .' baris berhasil diekspor.';

        /**
         * Jika ada data gagal diekspor,
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
