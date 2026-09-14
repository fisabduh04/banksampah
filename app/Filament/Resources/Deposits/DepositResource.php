<?php

namespace App\Filament\Resources\Deposits;

use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Filament\Resources\Deposits\Pages\EditDeposit;
use App\Filament\Resources\Deposits\Pages\ListDeposits;
use App\Filament\Resources\Deposits\Schemas\DepositForm;
use App\Filament\Resources\Deposits\Tables\DepositsTable;
use App\Models\Deposit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class DepositResource extends Resource
{
    protected static ?string $navigationLabel = 'Setoran Nasabah';

    protected static ?string $modelLabel = 'Setoran';

    protected static ?string $pluralModelLabel = 'Setoran Nasabah';

    protected static ?string $model = Deposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowDownTray;

    protected static ?string $recordTitleAttribute = 'deposit_number';

    public static function form(Schema $schema): Schema
    {
        return DepositForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepositsTable::configure($table);
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return $record->status === 'draft' ? parent::getEditAuthorizationResponse($record)
            : Response::deny('Transaksi final tidak dapat diubah.');
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return $record->status === 'draft' ? parent::getDeleteAuthorizationResponse($record)
            : Response::deny('Riwayat transaksi final tidak dapat dihapus.');
    }

    public static function canDeleteAny(): bool
    {
        return false;
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
            'index' => ListDeposits::route('/'),
            'create' => CreateDeposit::route('/create'),
            'edit' => EditDeposit::route('/{record}/edit'),
        ];
    }
}
