<?php

namespace App\Filament\Resources\WasteTypes;

use App\Filament\Resources\WasteTypes\Pages\CreateWasteType;
use App\Filament\Resources\WasteTypes\Pages\EditWasteType;
use App\Filament\Resources\WasteTypes\Pages\ListWasteTypes;
use App\Filament\Resources\WasteTypes\Schemas\WasteTypeForm;
use App\Filament\Resources\WasteTypes\Tables\WasteTypesTable;
use App\Models\WasteType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WasteTypeResource extends Resource
{
    protected static ?string $model = WasteType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Cube;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Jenis Bahan';

    protected static ?string $modelLabel = 'Jenis Bahan';

    protected static ?string $pluralModelLabel = 'Jenis Bahan';

    public static function form(Schema $schema): Schema
    {
        return WasteTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WasteTypesTable::configure($table);
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
            'index' => ListWasteTypes::route('/'),
            'create' => CreateWasteType::route('/create'),
            'edit' => EditWasteType::route('/{record}/edit'),
        ];
    }
}
