<?php

namespace App\Filament\Resources\WastePrices;

use App\Filament\Resources\WastePrices\Pages\CreateWastePrice;
use App\Filament\Resources\WastePrices\Pages\EditWastePrice;
use App\Filament\Resources\WastePrices\Pages\ListWastePrices;
use App\Filament\Resources\WastePrices\Schemas\WastePriceForm;
use App\Filament\Resources\WastePrices\Tables\WastePricesTable;
use App\Models\WastePrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WastePriceResource extends Resource
{
    protected static ?string $model = WastePrice::class;

    protected static ?string $navigationLabel = 'Harga Bahan';

    protected static ?string $modelLabel = 'Harga Bahan';

    protected static ?string $pluralModelLabel = 'Harga Bahan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Banknotes;

    public static function form(Schema $schema): Schema
    {
        return WastePriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WastePricesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWastePrices::route('/'),
            'create' => CreateWastePrice::route('/create'),
            'edit' => EditWastePrice::route('/{record}/edit'),
        ];
    }
}
