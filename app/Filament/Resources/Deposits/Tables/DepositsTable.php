<?php

namespace App\Filament\Resources\Deposits\Tables;

use App\Models\BalanceMutation;
use App\Services\DepositService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DepositsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'customer_balance' => BalanceMutation::query()
                    ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount WHEN type = 'debit' THEN -amount ELSE 0 END), 0)")
                    ->whereColumn('customer_id', 'deposits.customer_id'),
            ]))
            ->columns([
                TextColumn::make('deposit_number')
                    ->label('No. Transaksi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Nasabah')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('total_weight')
                    ->label('Total Berat')
                    ->suffix(' kg')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total Nilai')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('customer_balance')
                    ->label('Saldo Nasabah')
                    ->money('IDR')
                    ->tooltip('Saldo terkini nasabah dari seluruh mutasi yang sudah dibukukan.'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Belum Dibukukan',
                        'posted' => 'Telah Dibukukan',
                        'cancelled' => 'Dibatalkan',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Belum Dibukukan',
                        'posted' => 'Telah Dibukukan',
                        'cancelled' => 'Dibatalkan',
                    ]),

                SelectFilter::make('customer_id')
                    ->label('Nasabah')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('transaction_date')
                    ->label('Tanggal Transaksi')
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
                                fn (Builder $query, $date): Builder => $query->whereDate('transaction_date', '>=', $date)
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('transaction_date', '<=', $date)
                            );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Edit')
                    ->visible(fn ($record) => $record->status === 'draft'),

                Action::make('post')
                    ->label('Posting')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Posting Setoran')
                    ->modalDescription(
                        'Pastikan seluruh detail timbangan sudah benar. Setelah diposting, transaksi dianggap final.'
                    )
                    ->modalSubmitActionLabel('Ya, Posting')
                    ->visible(fn ($record) => $record->status === 'draft')
                    ->action(function ($record) {
                        try {
                            app(DepositService::class)->post($record);

                            Notification::make()
                                ->title('Posting Berhasil')
                                ->body('Setoran telah dibukukan dan saldo nasabah telah ditambahkan.')
                                ->success()
                                ->send();

                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Posting Gagal')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Setoran')
                    ->modalDescription(
                        'Nilai setoran akan dibalik dari saldo nasabah. Transaksi tetap disimpan dalam riwayat.'
                    )
                    ->modalSubmitActionLabel('Ya, Batalkan')
                    ->visible(fn ($record) => $record->status === 'posted')
                    ->action(function ($record) {
                        try {
                            app(DepositService::class)->cancel($record);

                            Notification::make()
                                ->title('Pembatalan Berhasil')
                                ->body('Setoran dibatalkan dan saldo nasabah telah disesuaikan.')
                                ->success()
                                ->send();

                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Pembatalan Gagal')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                //
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
