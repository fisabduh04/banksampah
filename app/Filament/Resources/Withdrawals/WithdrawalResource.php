<?php

namespace App\Filament\Resources\Withdrawals;

use App\Filament\Resources\Withdrawals\Pages\CreateWithdrawal;
use App\Filament\Resources\Withdrawals\Pages\EditWithdrawal;
use App\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Resources\Withdrawals\Schemas\WithdrawalForm;
use App\Filament\Resources\Withdrawals\Tables\WithdrawalsTable;
use App\Models\Withdrawal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WithdrawalResource extends Resource
{
    /**
     * Transaksi penarikan saldo nasabah.
     */
    protected static ?string $navigationLabel = 'Penarikan Saldo';

    protected static ?string $modelLabel = 'Penarikan';

    protected static ?string $pluralModelLabel = 'Penarikan Saldo';

    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowUpTray;

    protected static ?string $recordTitleAttribute = 'withdrawal_number';

    public static function form(Schema $schema): Schema
    {
        return WithdrawalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WithdrawalsTable::configure($table);
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
            'index' => ListWithdrawals::route('/'),
            'create' => CreateWithdrawal::route('/create'),
            'edit' => EditWithdrawal::route('/{record}/edit'),
        ];
    }
}
