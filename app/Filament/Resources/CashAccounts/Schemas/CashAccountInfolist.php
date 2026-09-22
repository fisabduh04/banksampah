<?php

namespace App\Filament\Resources\CashAccounts\Schemas;

use App\Models\CashAccount;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CashAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Akun')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Kode Akun'),

                        TextEntry::make('name')
                            ->label('Nama Akun'),

                        TextEntry::make('account_type')
                            ->label('Jenis Akun')
                            ->formatStateUsing(
                                fn (?string $state): string => CashAccount::accountTypes()[$state] ?? '-'
                            )
                            ->badge(),

                        IconEntry::make('is_active')
                            ->label('Status Aktif')
                            ->boolean(),

                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),

                Section::make('Informasi Rekening Bank')
                    ->columns(2)
                    ->visible(
                        fn (CashAccount $record): bool => $record->isBank()
                    )
                    ->schema([
                        TextEntry::make('bank_name')
                            ->label('Nama Bank')
                            ->placeholder('-'),

                        TextEntry::make('account_number')
                            ->label('Nomor Rekening')
                            ->placeholder('-'),

                        TextEntry::make('account_holder')
                            ->label('Atas Nama')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),

                Section::make('Informasi Sistem')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Dibuat Pada')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('-'),

                        TextEntry::make('updated_at')
                            ->label('Diperbarui Pada')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
