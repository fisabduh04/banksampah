<?php

namespace App\Filament\Resources\Collectors;

use App\Filament\Resources\Collectors\Pages\CreateCollector;
use App\Filament\Resources\Collectors\Pages\EditCollector;
use App\Filament\Resources\Collectors\Pages\ListCollectors;
use App\Filament\Resources\Collectors\Schemas\CollectorForm;
use App\Filament\Resources\Collectors\Tables\CollectorsTable;
use App\Models\Collector;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CollectorResource extends Resource
{
    /**
     * Model yang digunakan untuk mengelola data pengepul.
     */
    protected static ?string $model = Collector::class;

    /**
     * Ikon truk untuk membedakan menu pengepul dari menu lainnya.
     */
    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedTruck;

    /**
     * Samakan nama kelompok ini dengan menu master yang sudah ada.
     */
    protected static string|UnitEnum|null $navigationGroup =
        'Data Master';

    /**
     * Nama menu dan label data dalam Bahasa Indonesia.
     */
    protected static ?string $navigationLabel = 'Pengepul';

    protected static ?string $modelLabel = 'Pengepul';

    protected static ?string $pluralModelLabel = 'Pengepul';

    /**
     * Gunakan nama pengepul sebagai identitas tampilan setiap data.
     */
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Susunan formulir tetap dikelola melalui CollectorForm.
     */
    public static function form(Schema $schema): Schema
    {
        return CollectorForm::configure($schema);
    }

    /**
     * Susunan tabel tetap dikelola melalui CollectorsTable.
     */
    public static function table(Table $table): Table
    {
        return CollectorsTable::configure($table);
    }

    /**
     * Belum menambahkan pengelola relasi pada tahap master pengepul.
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Tolak penghapusan satu data melalui Resource ini.
     * Pengepul yang tidak digunakan cukup dinonaktifkan.
     */
    public static function getDeleteAuthorizationResponse(
        Model $record
    ): Response {
        return Response::deny(
            'Data pengepul tidak boleh dihapus. Nonaktifkan data tersebut.'
        );
    }

    /**
     * Tolak penghapusan massal melalui Resource ini.
     */
    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny(
            'Data pengepul tidak boleh dihapus secara massal.'
        );
    }

    /**
     * Pertahankan halaman daftar, tambah, dan ubah hasil generator.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCollectors::route('/'),
            'create' => CreateCollector::route('/create'),
            'edit' => EditCollector::route('/{record}/edit'),
        ];
    }
}
