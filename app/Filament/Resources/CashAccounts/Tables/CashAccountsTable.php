<?php

namespace App\Filament\Resources\CashAccounts\Tables;

use App\Models\CashAccount;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CashAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nama Akun')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account_type')
                    ->label('Jenis')
                    ->formatStateUsing(
                        fn (?string $state): string => CashAccount::accountTypes()[$state] ?? '-'
                    )
                    ->badge()
                    ->sortable(),

                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('account_number')
                    ->label('Nomor Rekening')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('account_holder')
                    ->label('Atas Nama')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('account_type')
                    ->label('Jenis Akun')
                    ->options(CashAccount::accountTypes()),

                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Tidak Aktif'),
            ])
            ->defaultSort('code')
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat'),

                EditAction::make()
                    ->label('Ubah'),
            ])

            /**
             * Sengaja tidak menyediakan bulk delete.
             *
             * Master Kas/Bank merupakan bagian dari histori finansial.
             * Jika akun tidak digunakan lagi, akun dinonaktifkan.
             */
            ->toolbarActions([]);
    }
}
