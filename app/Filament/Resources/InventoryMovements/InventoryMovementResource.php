<?php

namespace App\Filament\Resources\InventoryMovements;

use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Filament\Resources\InventoryMovements\Schemas\InventoryMovementForm;
use App\Filament\Resources\InventoryMovements\Tables\InventoryMovementsTable;
use App\Models\InventoryMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InventoryMovementResource extends Resource
{
    /**
     * Nama menu yang tampil di sidebar.
     */
    protected static ?string $navigationLabel = 'Mutasi Persediaan';

    /**
     * Nama satu record yang digunakan Filament di UI.
     */
    protected static ?string $modelLabel = 'Mutasi Persediaan';

    /**
     * Nama jamak yang digunakan Filament di halaman daftar.
     */
    protected static ?string $pluralModelLabel = 'Mutasi Persediaan';

    protected static ?string $model = InventoryMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowsUpDown;

    public static function form(Schema $schema): Schema
    {
        return InventoryMovementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryMovementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Mutasi Persediaan hanya untuk melihat riwayat.
     *
     * Karena data dibuat otomatis dari transaksi,
     * halaman create dan edit tidak disediakan.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryMovements::route('/'),
        ];
    }
}
