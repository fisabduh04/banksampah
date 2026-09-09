<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('customer_code')
                    ->label('Kode Nasabah')
                    ->required()
                    ->unique()
                    ->maxLength(50),

                TextInput::make('name')
                    ->label('Nama Nasabah')
                    ->required()
                    ->maxLength(255),

                TextInput::make('phone_number')
                    ->label('Nomor HP/Telepon')
                    ->tel()
                    ->maxLength(30),

                Textarea::make('address')
                    ->label('Alamat')
                    ->rows(3)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->required(),
            ])->columns(2);
    }
}
