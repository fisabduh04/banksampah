<?php

namespace App\Filament\Resources\WastePrices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WastePriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('waste_type_id')
                    ->label('Jenis Bahan')
                    ->relationship('wasteType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('price')
                    ->label('Harga per Kg')
                    ->numeric()
                    ->prefix('Rp')
                    ->required(),

                DatePicker::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->required()
                    ->default(now()),

                DatePicker::make('effective_until')
                    ->label('Berlaku Sampai')
                    ->nullable(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ])
            ->columns(2);
    }
}
