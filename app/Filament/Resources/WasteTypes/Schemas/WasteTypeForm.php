<?php

namespace App\Filament\Resources\WasteTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WasteTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('waste_category_id')
                    ->label('Kategori Bahan')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('code')
                    ->label('Kode Bahan')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),

                TextInput::make('name')
                    ->label('Nama Bahan')
                    ->required()
                    ->maxLength(255),

                TextInput::make('unit')
                    ->label('Satuan')
                    ->required()
                    ->default('kg')
                    ->maxLength(20),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ])
            ->columns(2);
    }
}
