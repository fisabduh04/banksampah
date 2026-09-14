<?php

namespace App\Filament\Resources\BalanceMutations;

use App\Filament\Resources\BalanceMutations\Pages\ListBalanceMutations;
use App\Filament\Resources\BalanceMutations\Schemas\BalanceMutationForm;
use App\Filament\Resources\BalanceMutations\Tables\BalanceMutationsTable;
use App\Models\BalanceMutation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class BalanceMutationResource extends Resource
{
    protected static ?string $navigationLabel = 'Mutasi Saldo';

    protected static ?string $modelLabel = 'Mutasi Saldo';

    protected static ?string $pluralModelLabel = 'Mutasi Saldo';

    protected static ?string $model = BalanceMutation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowsRightLeft;

    public static function form(Schema $schema): Schema
    {
        return BalanceMutationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BalanceMutationsTable::configure($table);
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny('Mutasi saldo hanya dibuat melalui transaksi.');
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny('Riwayat saldo tidak dapat diubah.');
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny('Riwayat saldo tidak dapat dihapus.');
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny('Riwayat saldo tidak dapat dihapus.');
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
            'index' => ListBalanceMutations::route('/'),
        ];
    }
}
