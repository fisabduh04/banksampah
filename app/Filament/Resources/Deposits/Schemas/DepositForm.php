<?php

namespace App\Filament\Resources\Deposits\Schemas;

use App\Models\WastePrice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
                    ->validationMessages([
                        'required' => 'Pilih nasabah yang melakukan transaksi.',
                    ])
                    ->label('Nasabah')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(),

                DatePicker::make('transaction_date')
                    ->validationMessages([
                        'required' => 'Isi tanggal kejadian transaksi sesuai bukti.',
                        'date' => 'Tanggal transaksi tidak valid. Pilih tanggal kejadian yang benar.',
                    ])
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
                            ->validationMessages([
                                'required' => 'Pilih jenis sampah pada rincian transaksi.',
                            ])
                            ->label('Jenis Bahan')
                            ->relationship('wasteType', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
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

                                $price = (string) ($price ?? '0.00');

                                $set('price', $price);
                                $set('subtotal', self::calculateSubtotal($get('weight'), $price));

                                self::updateTotals($get, $set);
                            }),

                        TextInput::make('weight')
                            ->validationMessages([
                                'required' => 'Isi berat sampah dalam kilogram.',
                                'numeric' => 'Berat sampah harus berupa angka.',
                                'min' => 'Berat sampah minimal :min kg.',
                            ])
                            ->label('Berat')
                            ->numeric()
                            ->suffix('kg')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                $set('subtotal', self::calculateSubtotal($get('weight'), $get('price')));

                                self::updateTotals($get, $set);
                            }),

                        TextInput::make('price')
                            ->validationMessages([
                                'required' => 'Harga belum tersedia. Periksa jenis sampah dan harga yang berlaku.',
                                'numeric' => 'Harga harus berupa angka.',
                                'min' => 'Harga minimal Rp :min.',
                            ])
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
        $totals = self::calculateTotals($get('../../items') ?? []);
        $set('../../total_weight', $totals['weight']);
        $set('../../total_amount', $totals['amount']);
    }

    private static function updateTotalsFromRepeater(Get $get, Set $set): void
    {
        $totals = self::calculateTotals($get('items') ?? []);
        $set('total_weight', $totals['weight']);
        $set('total_amount', $totals['amount']);
    }

    private static function decimalValue(mixed $value): BigDecimal
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return BigDecimal::of(is_numeric($value) ? $value : '0');
    }

    private static function calculateSubtotal(mixed $weight, mixed $price): string
    {
        return (string) self::decimalValue($weight)->multipliedBy(self::decimalValue($price))
            ->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $items
     * @return array{weight: string, amount: string}
     */
    private static function calculateTotals(array $items): array
    {
        $weight = BigDecimal::of('0.000');
        $amount = BigDecimal::of('0.00');
        foreach ($items as $item) {
            $weight = $weight->plus(self::decimalValue($item['weight'] ?? null));
            $amount = $amount->plus(self::calculateSubtotal($item['weight'] ?? null, $item['price'] ?? null));
        }

        return ['weight' => (string) $weight, 'amount' => (string) $amount];
    }
}
