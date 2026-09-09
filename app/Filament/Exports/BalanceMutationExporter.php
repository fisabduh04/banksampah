<?php

namespace App\Filament\Exports;

use App\Models\BalanceMutation;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class BalanceMutationExporter extends Exporter
{
    /**
     * Model teknis untuk fitur Mutasi Saldo.
     */
    protected static ?string $model = BalanceMutation::class;

    /**
     * Menentukan kolom yang diekspor.
     *
     * Kolom teknis seperti:
     * - id
     * - type
     * - reference_type
     * - reference_id
     *
     * tidak ditampilkan kepada pengguna.
     */
    public static function getColumns(): array
    {
        return [
            /**
             * Tanggal transaksi yang memengaruhi saldo.
             */
            ExportColumn::make('transaction_date')
                ->label('Tanggal'),

            /**
             * Nama Nasabah.
             */
            ExportColumn::make('customer.name')
                ->label('Nasabah'),

            /**
             * Keterangan transaksi.
             *
             * Contoh:
             * Setoran nasabah ST-2026-000001
             * Penarikan saldo WD-2026-000001
             */
            ExportColumn::make('description')
                ->label('Keterangan'),

            /**
             * Nilai yang menambah saldo.
             */
            ExportColumn::make('pemasukan')
                ->label('Pemasukan')
                ->state(
                    fn (BalanceMutation $record) => $record->type === 'credit'
                            ? $record->amount
                            : null
                ),

            /**
             * Nilai yang mengurangi saldo.
             */
            ExportColumn::make('pengeluaran')
                ->label('Pengeluaran')
                ->state(
                    fn (BalanceMutation $record) => $record->type === 'debit'
                            ? $record->amount
                            : null
                ),

            /**
             * Saldo berjalan Nasabah setelah transaksi ini.
             *
             * Rumus:
             * total pemasukan sampai transaksi ini
             * dikurangi
             * total pengeluaran sampai transaksi ini.
             */
            ExportColumn::make('saldo')
                ->label('Saldo')
                ->state(function (BalanceMutation $record): float {
                    /**
                     * Hitung pemasukan sampai record saat ini.
                     */
                    $totalPemasukan = BalanceMutation::query()
                        ->where('customer_id', $record->customer_id)
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
                        ->where('type', 'credit')
                        ->sum('amount');

                    /**
                     * Hitung pengeluaran sampai record saat ini.
                     */
                    $totalPengeluaran = BalanceMutation::query()
                        ->where('customer_id', $record->customer_id)
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
                        ->where('type', 'debit')
                        ->sum('amount');

                    return (float) $totalPemasukan
                        - (float) $totalPengeluaran;
                }),
        ];
    }

    /**
     * Notifikasi setelah ekspor selesai.
     */
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Ekspor data Mutasi Saldo selesai. '
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
