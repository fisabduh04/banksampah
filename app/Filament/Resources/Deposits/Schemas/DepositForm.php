<?php

namespace App\Filament\Resources\Deposits\Schemas;

use App\Models\WastePrice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DepositForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('deposit_number')
                    ->label('No. Transaksi')
                    ->default('Otomatis saat disimpan')
                    ->disabled()
                    ->dehydrated(false),

                Select::make('customer_id')
                    ->label('Nasabah')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('transaction_date')
                    ->label('Tanggal Transaksi')
                    ->default(now())
                    ->required(),

                /**
                 * Semua Setoran Nasabah selalu dibuat sebagai Belum Dibukukan.
                 *
                 * Status hanya berubah melalui proses Posting atau Pembatalan.
                 * Ini menjaga agar saldo dan persediaan selalu sinkron.
                 */
                Select::make('status')
                    ->label('Status Transaksi')
                    ->options([
                        'draft' => 'Belum Dibukukan',
                        'posted' => 'Telah Dibukukan',
                        'cancelled' => 'Dibatalkan',
                    ])
                    ->default('draft')
                    ->disabled()
                    ->dehydrated(),

                Repeater::make('items')
                    ->label('Detail Timbangan')
                    ->relationship('items')
                    ->schema([
                        Select::make('waste_type_id')
                            ->label('Jenis Bahan')
                            ->relationship('wasteType', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $price = WastePrice::query()
                                    ->where('waste_type_id', $state)
                                    ->where('is_active', true)
                                    ->whereDate('effective_from', '<=', now())
                                    ->where(function ($query) {
                                        $query
                                            ->whereNull('effective_until')
                                            ->orWhereDate('effective_until', '>=', now());
                                    })
                                    ->orderByDesc('effective_from')
                                    ->value('price');

                                $price = (float) ($price ?? 0);

                                $set('price', $price);

                                $weight = (float) ($get('weight') ?? 0);
                                $subtotal = $weight * $price;

                                $set('subtotal', $subtotal);

                                self::updateTotals($get, $set);
                            }),

                        TextInput::make('weight')
                            ->label('Berat')
                            ->numeric()
                            ->suffix('kg')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                $weight = (float) ($get('weight') ?? 0);
                                $price = (float) ($get('price') ?? 0);

                                $subtotal = $weight * $price;

                                $set('subtotal', $subtotal);

                                self::updateTotals($get, $set);
                            }),

                        TextInput::make('price')
                            ->label('Harga')
                            ->numeric()
                            ->prefix('Rp')
                            ->required()
                            ->readOnly(),

                        TextInput::make('subtotal')
                            ->label('Subtotal')
                            ->numeric()
                            ->prefix('Rp')
                            ->readOnly(),
                    ])
                    ->columns(4)
                    ->addActionLabel('Tambah Bahan')
                    ->minItems(1)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        self::updateTotalsFromRepeater($get, $set);
                    })
                    ->columnSpanFull(),

                TextInput::make('total_weight')
                    ->label('Total Berat')
                    ->numeric()
                    ->suffix('kg')
                    ->default(0)
                    ->disabled()
                    ->dehydrated(),

                TextInput::make('total_amount')
                    ->label('Total Nilai')
                    ->numeric()
                    ->prefix('Rp')
                    ->default(0)
                    ->disabled()
                    ->dehydrated(),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function updateTotals(Get $get, Set $set): void
    {
        $items = $get('../../items') ?? [];

        $totalWeight = collect($items)
            ->sum(fn ($item) => (float) ($item['weight'] ?? 0));

        $totalAmount = collect($items)
            ->sum(fn ($item) => (float) ($item['subtotal'] ?? 0));

        /*
         * Dalam callback item Repeater, state parent kadang belum
         * membawa subtotal terbaru yang baru saja kita set.
         * Karena itu kita hitung ulang item aktif dari weight × price.
         */
        $currentWeight = (float) ($get('weight') ?? 0);
        $currentPrice = (float) ($get('price') ?? 0);
        $currentSubtotal = $currentWeight * $currentPrice;

        /*
         * Cari subtotal lama pada item aktif.
         *
         * Jika state repeater belum ikut berubah, koreksi totalAmount
         * dengan subtotal terbaru.
         */
        $storedCurrentSubtotal = (float) ($get('subtotal') ?? 0);

        if ($currentSubtotal !== $storedCurrentSubtotal) {
            $totalAmount =
                $totalAmount
                - $storedCurrentSubtotal
                + $currentSubtotal;
        }

        $set('../../total_weight', $totalWeight);
        $set('../../total_amount', $totalAmount);
    }

    private static function updateTotalsFromRepeater(Get $get, Set $set): void
    {
        $items = $get('items') ?? [];

        $totalWeight = collect($items)
            ->sum(fn ($item) => (float) ($item['weight'] ?? 0));

        $totalAmount = collect($items)
            ->sum(fn ($item) => (float) ($item['subtotal'] ?? 0));

        $set('total_weight', $totalWeight);
        $set('total_amount', $totalAmount);
    }
}
