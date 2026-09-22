<?php

namespace App\Filament\Resources\CashMutations;

use App\Filament\Resources\CashMutations\Pages\ListCashMutations;
use App\Filament\Resources\CashMutations\Pages\ViewCashMutation;
use App\Filament\Resources\CashMutations\Schemas\CashMutationInfolist;
use App\Filament\Resources\CashMutations\Tables\CashMutationsTable;
use App\Models\CashMutation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CashMutationResource extends Resource
{
    protected static ?string $model = CashMutation::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $recordTitleAttribute = 'reference_number';

    protected static ?string $navigationLabel = 'Mutasi Kas';

    protected static ?string $modelLabel = 'Mutasi Kas';

    protected static ?string $pluralModelLabel = 'Mutasi Kas';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 20;

    /**
     * Ledger bersifat read-only.
     * Mutasi hanya boleh dibuat melalui service transaksi.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return CashMutationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CashMutationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashMutations::route('/'),
            'view' => ViewCashMutation::route('/{record}'),
        ];
    }
}
