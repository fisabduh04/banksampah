<?php

namespace App\Filament\Resources\Withdrawals\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    ->minValue(0.01)
                    ->rule('decimal:0,2')
                    ->required(),

                Select::make('status')->label('Status Transaksi')->options(['draft' => 'Belum Dibukukan'])->default('draft')->disabled()->dehydrated(false),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
