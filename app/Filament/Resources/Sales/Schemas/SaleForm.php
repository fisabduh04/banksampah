<?php

namespace App\Filament\Resources\Sales\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SaleForm
{
    /**
     * Konfigurasi formulir transaksi penjualan ke pengepul.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                /*
                 * ============================================================
                 * INFORMASI TRANSAKSI
                 * ============================================================
                 */
                Section::make('Informasi Penjualan')
                    ->description(
                        'Masukkan informasi dasar transaksi penjualan ke pengepul.'
                    )
                    ->schema([

                        TextInput::make('sale_number')
                            ->label('Nomor Penjualan')
                            ->placeholder('Dibuat otomatis saat draft disimpan')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(
                                'Nomor transaksi dibuat otomatis oleh sistem.'
                            ),

                        Select::make('collector_id')
                            ->label('Pengepul')
                            ->relationship(
                                name: 'collector',
                                titleAttribute: 'name',

                                /*
                                 * Hanya pengepul aktif yang boleh dipilih
                                 * untuk transaksi baru.
                                 */
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('is_active', true)
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),

                        DatePicker::make('transaction_date')
                            ->label('Tanggal Penjualan')
                            ->default(now())
                            ->required(),

                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                /*
                 * ============================================================
                 * DETAIL PENJUALAN
                 * ============================================================
                 */
                Section::make('Rincian Sampah yang Dijual')
                    ->description(
                        'Tambahkan jenis sampah, berat, dan harga jual kepada pengepul.'
                    )
                    ->schema([

                        Repeater::make('items')
                            ->label('Rincian Penjualan')

                            /*
                             * Menggunakan relasi Sale -> items().
                             * Filament akan mengelola data sale_items.
                             */
                            ->relationship('items')

                            ->schema([

                                Select::make('waste_type_id')
                                    ->label('Jenis Sampah')
                                    ->relationship(
                                        name: 'wasteType',
                                        titleAttribute: 'name',

                                        /*
                                         * Hanya jenis sampah aktif
                                         * yang tersedia untuk dipilih.
                                         */
                                        modifyQueryUsing: fn (Builder $query) => $query
                                            ->where('is_active', true)
                                            ->orderBy('name'),
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->required()

                                    /*
                                     * Jenis sampah yang sama tidak boleh
                                     * dipilih dua kali dalam satu transaksi.
                                     */
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                                TextInput::make('weight')
                                    ->label('Berat')
                                    ->numeric()
                                    ->suffix('kg')
                                    ->required()
                                    ->minValue(0.001)
                                    ->default(0)
                                    ->live(onBlur: true)

                                    /*
                                     * Hitung subtotal setiap kali berat berubah.
                                     */
                                    ->afterStateUpdated(
                                        function (Get $get, Set $set): void {
                                            self::hitungSubtotalItem($get, $set);
                                            self::hitungTotalPenjualan($get, $set);
                                        }
                                    ),

                                TextInput::make('price')
                                    ->label('Harga Jual / kg')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->required()
                                    ->minValue(0)
                                    ->default(0)
                                    ->live(onBlur: true)

                                    /*
                                     * Harga ini adalah harga jual ke pengepul,
                                     * bukan harga beli dari nasabah.
                                     */
                                    ->afterStateUpdated(
                                        function (Get $get, Set $set): void {
                                            self::hitungSubtotalItem($get, $set);
                                            self::hitungTotalPenjualan($get, $set);
                                        }
                                    ),

                                TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->default(0)

                                    /*
                                     * Operator tidak boleh mengubah subtotal.
                                     * Nilai dihitung oleh sistem.
                                     */
                                    ->disabled()
                                    ->dehydrated(),

                            ])
                            ->columns(4)

                            /*
                             * Minimal harus ada satu rincian.
                             */
                            ->defaultItems(1)
                            ->minItems(1)

                            ->addActionLabel('Tambah Jenis Sampah')

                            /*
                             * Tidak perlu drag-and-drop karena urutan
                             * rincian tidak memiliki arti akuntansi.
                             */
                            ->reorderable(false)

                            /*
                             * Pastikan total juga diperbarui jika item
                             * ditambah atau dihapus.
                             */
                            ->live()
                            ->afterStateUpdated(
                                function (Get $get, Set $set): void {
                                    self::hitungTotalDariRepeater($get, $set);
                                }
                            )
                            ->columnSpanFull(),

                    ])
                    ->columnSpanFull(),

                /*
                 * ============================================================
                 * RINGKASAN PENJUALAN
                 * ============================================================
                 */
                Section::make('Ringkasan Penjualan')
                    ->schema([

                        TextInput::make('total_weight')
                            ->label('Total Berat')
                            ->numeric()
                            ->suffix('kg')
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                        TextInput::make('total_amount')
                            ->label('Total Penjualan')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0)
                            ->disabled()
                            ->dehydrated(),

                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                /*
                 * ============================================================
                 * CATATAN
                 * ============================================================
                 */
                Section::make('Catatan')
                    ->schema([

                        Textarea::make('notes')
                            ->label('Catatan Penjualan')
                            ->placeholder(
                                'Informasi tambahan mengenai transaksi.'
                            )
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),

                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Menghitung subtotal satu baris rincian.
     *
     * subtotal = berat × harga jual
     */
    private static function hitungSubtotalItem(
        Get $get,
        Set $set
    ): void {
        $berat = (float) ($get('weight') ?? 0);
        $harga = (float) ($get('price') ?? 0);

        $subtotal = $berat * $harga;

        $set(
            'subtotal',
            round($subtotal, 2)
        );
    }

    /**
     * Menghitung total transaksi dari seluruh item repeater.
     *
     * Dipanggil dari field berat/harga di dalam satu item.
     */
    private static function hitungTotalPenjualan(
        Get $get,
        Set $set
    ): void {
        /*
         * Karena callback berada di dalam item Repeater,
         * ../ berarti naik satu tingkat ke seluruh daftar item.
         */
        $items = $get('../') ?? [];

        $totalBerat = 0;
        $totalPenjualan = 0;

        foreach ($items as $item) {
            $berat = (float) ($item['weight'] ?? 0);
            $harga = (float) ($item['price'] ?? 0);

            $totalBerat += $berat;
            $totalPenjualan += ($berat * $harga);
        }

        /*
         * ../../ berarti kembali ke level form Sale.
         */
        $set(
            '../../total_weight',
            round($totalBerat, 3)
        );

        $set(
            '../../total_amount',
            round($totalPenjualan, 2)
        );
    }

    /**
     * Menghitung ulang total ketika item Repeater
     * ditambah atau dihapus.
     */
    private static function hitungTotalDariRepeater(
        Get $get,
        Set $set
    ): void {
        $items = $get('items') ?? [];

        $totalBerat = 0;
        $totalPenjualan = 0;

        foreach ($items as $item) {
            $berat = (float) ($item['weight'] ?? 0);
            $harga = (float) ($item['price'] ?? 0);

            $totalBerat += $berat;
            $totalPenjualan += ($berat * $harga);
        }

        $set(
            'total_weight',
            round($totalBerat, 3)
        );

        $set(
            'total_amount',
            round($totalPenjualan, 2)
        );
    }
}
