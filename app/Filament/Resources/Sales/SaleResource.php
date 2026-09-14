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
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Penjualan')->schema([
                TextEntry::make('sale_number')->label('Nomor Penjualan'),
                TextEntry::make('collector.name')->label('Pengepul'),
                TextEntry::make('transaction_date')->label('Tanggal Penjualan')->date('d/m/Y'),
                TextEntry::make('due_date')->label('Jatuh Tempo Pembayaran')->date('d/m/Y')->placeholder('-'),
                TextEntry::make('status')->label('Status Transaksi')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'draft' => 'Draft', 'posted' => 'Diposting', 'cancelled' => 'Dibatalkan', default => $state,
                }),
                TextEntry::make('payment_status')->label('Status Pembayaran')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'unpaid' => 'Belum Dibayar', 'partial' => 'Dibayar Sebagian', 'paid' => 'Lunas', default => $state,
                }),
            ])->columns(3)->columnSpanFull(),
            Section::make('Rincian dan Ringkasan')->schema([
                RepeatableEntry::make('items')->label('Rincian Penjualan')->schema([
                    TextEntry::make('wasteType.name')->label('Jenis Sampah'),
                    TextEntry::make('weight')->label('Berat')->numeric(decimalPlaces: 3)->suffix(' kg'),
                    TextEntry::make('price')->label('Harga Jual')->money('IDR'),
                    TextEntry::make('subtotal')->label('Subtotal')->money('IDR'),
                    TextEntry::make('cost_total')->label('HPP')->money('IDR'),
                    TextEntry::make('gross_profit')->label('Laba Kotor')->money('IDR'),
                ])->columns(3)->columnSpanFull(),
                TextEntry::make('total_weight')->label('Total Berat')->numeric(decimalPlaces: 3)->suffix(' kg'),
                TextEntry::make('total_amount')->label('Total Penjualan')->money('IDR'),
                TextEntry::make('total_cost')->label('HPP')->money('IDR'),
                TextEntry::make('gross_profit')->label('Laba Kotor')->money('IDR'),
                TextEntry::make('paid_amount')->label('Sudah Dibayar')->money('IDR'),
                TextEntry::make('outstanding_amount')->label('Sisa Piutang')->money('IDR'),
            ])->columns(3)->columnSpanFull(),
            Section::make('Riwayat Transaksi')->schema([
                TextEntry::make('posted_at')->label('Waktu Posting')->dateTime('d/m/Y H:i')->placeholder('-'),
                TextEntry::make('postedBy.name')->label('Diposting Oleh')->placeholder('-'),
                TextEntry::make('cancelled_at')->label('Waktu Pembatalan')->dateTime('d/m/Y H:i')->placeholder('-'),
                TextEntry::make('cancelledBy.name')->label('Dibatalkan Oleh')->placeholder('-'),
                TextEntry::make('cancellation_reason')->label('Alasan Pembatalan')->placeholder('-'),
                TextEntry::make('notes')->label('Catatan')->placeholder('-'),
                TextEntry::make('cost_reconciliation')->label('Riwayat Koreksi HPP')
                    ->state(function (Sale $record): string {
                        return DB::table('inventory_cost_reconciliations')->where('sale_id', $record->id)->orderBy('id')->get()
                            ->map(function (object $audit): string {
                                $before = json_decode($audit->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
                                $after = json_decode($audit->corrected_snapshot, true, flags: JSON_THROW_ON_ERROR);

                                return 'HPP rincian Rp '.number_format((float) $before['item']['cost_total'], 2, ',', '.')
                                    .' menjadi Rp '.number_format((float) $after['item']['cost_total'], 2, ',', '.')
                                    .'. Alasan: '.$audit->reason.'. Disetujui oleh: '.$audit->approved_by
                                    .'. Dicatat: '.$audit->created_at.'.';
                            })->implode(' ');
                    })->placeholder('Tidak ada koreksi biaya.')->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return SalesTable::configure($table);
    }

    /**
     * Riwayat pembayaran dikelola melalui Relation Manager.
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
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['collector', 'postedBy', 'cancelledBy'])
            ->withSum(['payments as active_paid_amount' => fn (Builder $query): Builder => $query->where('status', 'posted')], 'amount');
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return $record instanceof Sale && $record->isDraft()
            ? parent::getEditAuthorizationResponse($record)
            : Response::deny('Hanya draft penjualan yang boleh diubah.');
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return $record instanceof Sale && $record->isDraft()
            ? parent::getDeleteAuthorizationResponse($record)
            : Response::deny('Riwayat penjualan tidak boleh dihapus.');
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny('Penjualan tidak boleh dihapus secara massal.');
    }

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
