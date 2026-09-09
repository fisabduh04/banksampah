<?php

namespace App\Filament\Resources\BalanceMutations\Tables;

use App\Models\BalanceMutation;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BalanceMutationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Nasabah')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Keterangan')
                    ->searchable()
                    ->wrap()
                    ->placeholder('-'),

                TextColumn::make('pemasukan')
                    ->label('Pemasukan')
                    ->state(
                        fn (BalanceMutation $record) => $record->type === 'credit'
                                ? $record->amount
                                : null
                    )
                    ->money('IDR')
                    ->placeholder('-'),

                TextColumn::make('pengeluaran')
                    ->label('Pengeluaran')
                    ->state(
                        fn (BalanceMutation $record) => $record->type === 'debit'
                                ? $record->amount
                                : null
                    )
                    ->money('IDR')
                    ->placeholder('-'),

                TextColumn::make('saldo')
                    ->label('Saldo')
                    ->state(function (BalanceMutation $record): float {
                        $pemasukan = BalanceMutation::query()
                            ->where('customer_id', $record->customer_id)
                            ->where(function (Builder $query) use ($record) {
                                $query
                                    ->whereDate(
                                        'transaction_date',
                                        '<',
                                        $record->transaction_date
                                    )
                                    ->orWhere(function (Builder $query) use ($record) {
                                        $query
                                            ->whereDate(
                                                'transaction_date',
                                                $record->transaction_date
                                            )
                                            ->where('id', '<=', $record->id);
                                    });
                            })
                            ->where('type', 'credit')
                            ->sum('amount');

                        $pengeluaran = BalanceMutation::query()
                            ->where('customer_id', $record->customer_id)
                            ->where(function (Builder $query) use ($record) {
                                $query
                                    ->whereDate(
                                        'transaction_date',
                                        '<',
                                        $record->transaction_date
                                    )
                                    ->orWhere(function (Builder $query) use ($record) {
                                        $query
                                            ->whereDate(
                                                'transaction_date',
                                                $record->transaction_date
                                            )
                                            ->where('id', '<=', $record->id);
                                    });
                            })
                            ->where('type', 'debit')
                            ->sum('amount');

                        return (float) $pemasukan - (float) $pengeluaran;
                    })
                    ->money('IDR'),

                TextColumn::make('created_at')
                    ->label('Dicatat')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Nasabah')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('transaction_date')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari Tanggal'),

                        DatePicker::make('until')
                            ->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate(
                                    'transaction_date',
                                    '>=',
                                    $date
                                )
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate(
                                    'transaction_date',
                                    '<=',
                                    $date
                                )
                            );
                    }),
            ])

            ->recordActions([
                //
            ])

            ->toolbarActions([
                //
            ])

            /**
             * Menampilkan transaksi terbaru di bagian paling atas.
             *
             * Ini memudahkan operator melihat mutasi terbaru
             * tanpa harus menggulir ke bagian bawah tabel.
             */
            ->defaultSort('transaction_date', 'desc');
    }
}
