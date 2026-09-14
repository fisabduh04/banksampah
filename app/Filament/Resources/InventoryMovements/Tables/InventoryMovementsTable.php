<?php

namespace App\Filament\Resources\InventoryMovements\Tables;

use App\Models\InventoryMovement;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryMovementsTable
{
    /**
     * Mengatur tampilan tabel Mutasi Persediaan.
     *
     * Halaman ini bersifat READ-ONLY.
     * Data mutasi persediaan tidak dibuat atau diedit secara manual,
     * tetapi dihasilkan otomatis dari transaksi seperti:
     * - Setoran Nasabah
     * - Pembatalan Setoran
     * - Penjualan ke Pengepul (nanti)
     * - Pembatalan Penjualan (nanti)
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                /**
                 * Tanggal terjadinya transaksi yang menyebabkan
                 * perubahan persediaan.
                 */
                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                /**
                 * Nama bahan diambil melalui relasi wasteType().
                 *
                 * Contoh:
                 * Besi, Kardus, Botol PET, dan sebagainya.
                 */
                TextColumn::make('wasteType.name')
                    ->label('Jenis Bahan')
                    ->searchable()
                    ->sortable(),

                /**
                 * Menampilkan jumlah bahan masuk.
                 *
                 * Hanya record dengan movement_type = in
                 * yang ditampilkan pada kolom ini.
                 */
                TextColumn::make('barang_masuk')
                    ->label('Masuk')
                    ->state(
                        fn (InventoryMovement $record) => $record->movement_type === 'in'
                                ? $record->quantity
                                : null
                    )
                    ->numeric(decimalPlaces: 3)
                    ->suffix(' kg')
                    ->placeholder('-'),

                /**
                 * Menampilkan jumlah bahan keluar.
                 *
                 * Hanya record dengan movement_type = out
                 * yang ditampilkan pada kolom ini.
                 */
                TextColumn::make('barang_keluar')
                    ->label('Keluar')
                    ->state(
                        fn (InventoryMovement $record) => $record->movement_type === 'out'
                                ? $record->quantity
                                : null
                    )
                    ->numeric(decimalPlaces: 3)
                    ->suffix(' kg')
                    ->placeholder('-'),

                /**
                 * Menghitung stok berjalan sampai transaksi ini.
                 *
                 * Rumus:
                 * Total Barang Masuk - Total Barang Keluar
                 *
                 * Perhitungan dilakukan khusus untuk jenis bahan
                 * yang sama dengan record yang sedang ditampilkan.
                 */
                TextColumn::make('stok_berjalan')
                    ->label('Stok')
                    ->state(function (InventoryMovement $record): float {

                        // Hitung seluruh barang masuk sampai record ini.
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

                        // Hitung seluruh barang keluar sampai record ini.
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
                    })
                    ->numeric(decimalPlaces: 3)
                    ->suffix(' kg'),

                /**
                 * Keterangan sumber perubahan persediaan.
                 *
                 * Contoh:
                 * "Setoran nasabah ST-2026-000006"
                 */
                TextColumn::make('description')
                    ->label('Keterangan')
                    ->searchable()
                    ->wrap()
                    ->placeholder('-'),

                /**
                 * Waktu record dibuat oleh sistem.
                 *
                 * Disembunyikan secara default karena tidak terlalu
                 * diperlukan dalam pekerjaan sehari-hari.
                 */
                TextColumn::make('created_at')
                    ->label('Dicatat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([

                /**
                 * Filter berdasarkan jenis bahan.
                 *
                 * User dapat memilih misalnya hanya:
                 * Besi, Kardus, atau Botol PET.
                 */
                SelectFilter::make('waste_type_id')
                    ->label('Jenis Bahan')
                    ->relationship('wasteType', 'name')
                    ->searchable()
                    ->preload(),

                /**
                 * Filter periode transaksi.
                 *
                 * Bisa digunakan untuk melihat mutasi persediaan
                 * dalam rentang tanggal tertentu.
                 */
                Filter::make('transaction_date')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari Tanggal'),

                        DatePicker::make('until')
                            ->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate(
                                    'transaction_date',
                                    '>=',
                                    $date
                                )
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate(
                                    'transaction_date',
                                    '<=',
                                    $date
                                )
                            );
                    }),
            ])

            /**
             * Tidak ada Edit.
             *
             * Mutasi persediaan adalah ledger/riwayat transaksi
             * dan tidak boleh diubah manual oleh operator.
             */
            ->recordActions([
                //
            ])

            /**
             * Tidak ada Hapus ataupun aksi massal.
             *
             * Koreksi persediaan nantinya dilakukan melalui transaksi
             * pembalik, bukan dengan menghapus riwayat.
             */
            ->toolbarActions([
                //
            ])

            /**
             * Urutkan dari transaksi paling lama ke terbaru
             * agar stok berjalan mudah dibaca seperti buku stok.
             */
            ->defaultSort('transaction_date', 'desc');
    }
}
