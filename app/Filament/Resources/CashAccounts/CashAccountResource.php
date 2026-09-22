<?php

namespace App\Filament\Resources\CashAccounts;

use App\Filament\Resources\CashAccounts\Pages\CreateCashAccount;
use App\Filament\Resources\CashAccounts\Pages\EditCashAccount;
use App\Filament\Resources\CashAccounts\Pages\ListCashAccounts;
use App\Filament\Resources\CashAccounts\Pages\ViewCashAccount;
use App\Filament\Resources\CashAccounts\Schemas\CashAccountForm;
use App\Filament\Resources\CashAccounts\Schemas\CashAccountInfolist;
use App\Filament\Resources\CashAccounts\Tables\CashAccountsTable;
use App\Models\CashAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CashAccountResource extends Resource
{
    protected static ?string $model = CashAccount::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Kas & Bank';

    protected static ?string $modelLabel = 'Akun Kas/Bank';

    protected static ?string $pluralModelLabel = 'Kas & Bank';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return CashAccountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CashAccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CashAccountsTable::configure($table);
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
            'index' => ListCashAccounts::route('/'),
            'create' => CreateCashAccount::route('/create'),
            'view' => ViewCashAccount::route('/{record}'),
            'edit' => EditCashAccount::route('/{record}/edit'),
        ];
    }
}
