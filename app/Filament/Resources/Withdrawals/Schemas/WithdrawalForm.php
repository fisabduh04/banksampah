<?php

namespace App\Filament\Resources\Withdrawals\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class WithdrawalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('withdrawal_number')
                    ->label('No. Penarikan')
                    ->disabled()
                    ->dehydrated(false)
                    ->default('Otomatis saat disimpan'),

                Select::make('customer_id')
                    ->label('Nasabah')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        $customer = Customer::find($state);

                        $set(
                            'available_balance',
                            $customer?->balance ?? 0
                        );
                    }),

                TextInput::make('available_balance')
                    ->label('Saldo Tersedia')
                    ->prefix('Rp')
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false)
                    ->default(0),

                DatePicker::make('transaction_date')
                    ->label('Tanggal Transaksi')
                    ->default(now())
                    ->required(),

                TextInput::make('amount')
                    ->label('Jumlah Penarikan')
                    ->prefix('Rp')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->rule(function (Get $get) {
                        return function (
                            string $attribute,
                            $value,
                            \Closure $fail
                        ) use ($get) {
                            $balance = (float) (
                                $get('available_balance') ?? 0
                            );

                            if ((float) $value > $balance) {
                                $fail(
                                    'Jumlah penarikan tidak boleh melebihi saldo tersedia.'
                                );
                            }
                        };
                    }),

                /**
                 * Status transaksi dipilih oleh operator saat menyimpan.
                 *
                 * - Belum Dibukukan:
                 *   transaksi hanya disimpan sebagai draft dan belum memengaruhi saldo.
                 *
                 * - Telah Dibukukan:
                 *   transaksi akan disimpan lalu diproses melalui WithdrawalService
                 *   sehingga Mutasi Saldo Pengeluaran otomatis terbentuk.
                 *
                 * Status Dibatalkan tidak dipilih saat membuat transaksi baru.
                 * Pembatalan dilakukan melalui aksi "Batalkan" pada daftar transaksi.
                 */
                Select::make('status')
                    ->label('Status Transaksi')
                    ->options([
                        'draft' => 'Belum Dibukukan',
                        'posted' => 'Telah Dibukukan',
                    ])
                    ->default('draft')
                    ->required(),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
