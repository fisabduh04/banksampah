<?php

namespace App\Filament\Resources\Collectors\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CollectorsTable
{
    /**
     * Konfigurasi tabel master pengepul.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode Pengepul')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nama Pengepul')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('contact_person')
                    ->label('Nama Kontak')
                    ->searchable()
                    ->placeholder('-')
                    ->wrap(),

                TextColumn::make('phone')
                    ->label('Nomor Telepon')
                    ->searchable()
                    ->placeholder('-'),

                /*
                 * Status ditampilkan sebagai badge agar lebih mudah
                 * dibaca dibandingkan hanya ikon centang/silang.
                 */
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn (bool $state): string => $state ? 'Aktif' : 'Tidak Aktif'
                    )
                    ->color(
                        fn (bool $state): string => $state ? 'success' : 'gray'
                    )
                    ->sortable(),

                TextColumn::make('address')
                    ->label('Alamat')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Terakhir Diubah')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            /*
             * Filter status:
             * - Semua status
             * - Aktif
             * - Tidak Aktif
             */
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status Pengepul')
                    ->placeholder('Semua Status')
                    ->trueLabel('Aktif')
                    ->falseLabel('Tidak Aktif'),
            ])

            /*
             * Aksi pada masing-masing baris.
             */
            ->recordActions([
                EditAction::make()
                    ->label('Ubah'),
            ])

            /*
             * Tidak menyediakan DeleteBulkAction.
             * Data pengepul yang sudah tidak digunakan
             * cukup dinonaktifkan.
             */
            ->toolbarActions([])

            /*
             * Secara default data diurutkan berdasarkan nama pengepul.
             */
            ->defaultSort('name', 'asc');
    }
}
