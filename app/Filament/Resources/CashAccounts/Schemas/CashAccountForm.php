<?php

namespace App\Filament\Resources\CashAccounts\Schemas;

use App\Models\CashAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CashAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Akun')
                    ->description('Data utama akun kas atau rekening bank.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Kode Akun')
                            ->required()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->placeholder('Contoh: KAS-001'),

                        TextInput::make('name')
                            ->label('Nama Akun')
                            ->required()
                            ->maxLength(150)
                            ->placeholder('Contoh: Kas Utama'),

                        Select::make('account_type')
                            ->label('Jenis Akun')
                            ->options(CashAccount::accountTypes())
                            ->required()
                            ->native(false)
                            ->live(),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->required(),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),

                Section::make('Informasi Rekening Bank')
                    ->description('Diisi apabila jenis akun adalah Rekening Bank.')
                    ->columns(2)
                    ->visible(
                        fn (Get $get): bool => $get('account_type') === CashAccount::TYPE_BANK
                    )
                    ->schema([
                        TextInput::make('bank_name')
                            ->label('Nama Bank')
                            ->required(
                                fn (Get $get): bool => $get('account_type') === CashAccount::TYPE_BANK
                            )
                            ->maxLength(100)
                            ->placeholder('Contoh: Bank BRI'),

                        TextInput::make('account_number')
                            ->label('Nomor Rekening')
                            ->required(
                                fn (Get $get): bool => $get('account_type') === CashAccount::TYPE_BANK
                            )
                            ->maxLength(100),

                        TextInput::make('account_holder')
                            ->label('Atas Nama')
                            ->required(
                                fn (Get $get): bool => $get('account_type') === CashAccount::TYPE_BANK
                            )
                            ->maxLength(150)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
