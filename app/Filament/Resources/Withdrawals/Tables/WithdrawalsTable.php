<?php

namespace App\Filament\Resources\Withdrawals\Tables;

use App\Services\WithdrawalService;
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

class WithdrawalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('withdrawal_number')
                    ->label('No. Penarikan')
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

                TextColumn::make('amount')
                    ->label('Jumlah Penarikan')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status Transaksi')
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
                    ->label('Status Transaksi')
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
                    ->label('Ubah')
                    ->visible(fn ($record) => $record->status === 'draft'),

                Action::make('post')
                    ->label('Posting')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Posting Penarikan Saldo')
                    ->modalDescription(
                        'Setelah diposting, saldo nasabah akan berkurang dan transaksi dianggap final.'
                    )
                    ->modalSubmitActionLabel('Ya, Posting')
                    ->visible(fn ($record) => $record->status === 'draft')
                    ->action(function ($record) {
                        try {
                            app(WithdrawalService::class)->post($record);

                            Notification::make()
                                ->title('Posting Berhasil')
                                ->body('Penarikan saldo telah dibukukan.')
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
                    ->modalHeading('Batalkan Penarikan')
                    ->modalDescription(
                        'Saldo nasabah akan dikembalikan sebesar nilai penarikan. Transaksi tetap disimpan dalam riwayat.'
                    )
                    ->modalSubmitActionLabel('Ya, Batalkan')
                    ->visible(fn ($record) => $record->status === 'posted')
                    ->action(function ($record) {
                        try {
                            app(WithdrawalService::class)->cancel($record);

                            Notification::make()
                                ->title('Pembatalan Berhasil')
                                ->body('Penarikan dibatalkan dan saldo nasabah telah dikembalikan.')
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
            ->defaultSort('transaction_date', 'desc');
    }
}
