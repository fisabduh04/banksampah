<?php

namespace App\Filament\Resources\Sales;

use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Sales\Schemas\SaleForm;
use App\Filament\Resources\Sales\Tables\SalesTable;
use App\Models\Sale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SaleResource extends Resource
{
    /**
     * Model transaksi penjualan ke pengepul.
     */
    protected static ?string $model = Sale::class;

    /**
     * Ikon menu transaksi penjualan.
     */
    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedShoppingCart;

    /**
     * Kelompok menu transaksi.
     *
     * Jika project Anda sudah mempunyai nama kelompok transaksi
     * yang berbeda, samakan dengan kelompok yang sudah ada.
     */
    protected static string|UnitEnum|null $navigationGroup =
        'Transaksi';

    /**
     * Nama menu yang tampil di sidebar.
     */
    protected static ?string $navigationLabel =
        'Penjualan ke Pengepul';

    /**
     * Label untuk satu record.
     */
    protected static ?string $modelLabel =
        'Penjualan';

    /**
     * Label untuk kumpulan record.
     */
    protected static ?string $pluralModelLabel =
        'Penjualan ke Pengepul';

    /**
     * Nomor penjualan menjadi identitas transaksi.
     */
    protected static ?string $recordTitleAttribute =
        'sale_number';

    /**
     * Form transaksi dikelola pada SaleForm.
     */
    public static function form(Schema $schema): Schema
    {
        return SaleForm::configure($schema);
    }

    /**
     * Tabel transaksi dikelola pada SalesTable.
     */
    public static function table(Table $table): Table
    {
        return SalesTable::configure($table);
    }

    /**
     * Belum menggunakan Relation Manager pada tahap ini.
     *
     * Detail penjualan nantinya dikelola melalui Repeater
     * pada SaleForm.
     */
    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    /**
     * Hanya transaksi Draft yang boleh diedit.
     *
     * Pemeriksaan parent tetap dipertahankan agar Policy
     * atau aturan otorisasi aplikasi tetap berlaku.
     */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record)
            && $record instanceof Sale
            && $record->status === Sale::STATUS_DRAFT;
    }

    /**
     * Hanya transaksi Draft yang boleh dihapus.
     *
     * Transaksi Posted dan Cancelled harus tetap disimpan
     * sebagai histori transaksi.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record)
            && $record instanceof Sale
            && $record->status === Sale::STATUS_DRAFT;
    }

    /**
     * Halaman yang tersedia:
     * daftar, tambah, dan ubah transaksi.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'create' => CreateSale::route('/create'),
            'view' => ViewSale::route('/{record}'),
            'edit' => EditSale::route('/{record}/edit'),
        ];
    }
}
