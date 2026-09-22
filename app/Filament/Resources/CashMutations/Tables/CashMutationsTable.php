<?php

namespace App\Filament\Resources\CashMutations\Tables;

use App\Models\CashAccount;
use App\Models\CashMutation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CashMutationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('cashAccount.name')
                    ->label('Kas/Bank')
                    ->description(
                        fn (CashMutation $record): ?string => $record->cashAccount?->code
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make('mutation_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            CashMutation::TYPE_IN => 'Masuk',
                            CashMutation::TYPE_OUT => 'Keluar',
                            default => '-',
                        }
                    )
                    ->color(
                        fn (?string $state): string => match ($state) {
                            CashMutation::TYPE_IN => 'success',
                            CashMutation::TYPE_OUT => 'danger',
                            default => 'gray',
                        }
                    ),

                TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(
                        fn ($state): string => 'Rp '.number_format(
                            (float) $state,
                            0,
                            ',',
                            '.'
                        )
                    )
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('reference_number')
                    ->label('Nomor Referensi')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('reference_type')
                    ->label('Sumber')
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            'opening_balance' => 'Saldo Awal',
                            'sale_payment' => 'Pembayaran Penjualan',
                            'sale_payment_cancellation' => 'Pembatalan Pembayaran',
                            'manual_receipt' => 'Penerimaan Manual',
                            'manual_expense' => 'Pengeluaran Manual',
                            'transfer' => 'Transfer Antar Akun',
                            default => $state ?? '-',
                        }
                    )
                    ->badge(),

                TextColumn::make('description')
                    ->label('Keterangan')
                    ->placeholder('-')
                    ->limit(50)
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('createdBy.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Waktu Pencatatan')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('cash_account_id')
                    ->label('Kas/Bank')
                    ->options(
                        fn (): array => CashAccount::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(
                                fn (CashAccount $account): array => [
                                    $account->id => $account->code.' — '.$account->name,
                                ]
                            )
                            ->all()
                    ),

                SelectFilter::make('mutation_type')
                    ->label('Jenis Mutasi')
                    ->options([
                        CashMutation::TYPE_IN => 'Masuk',
                        CashMutation::TYPE_OUT => 'Keluar',
                    ]),

                SelectFilter::make('reference_type')
                    ->label('Sumber Transaksi')
                    ->options([
                        'opening_balance' => 'Saldo Awal',
                        'sale_payment' => 'Pembayaran Penjualan',
                        'sale_payment_cancellation' => 'Pembatalan Pembayaran',
                        'manual_receipt' => 'Penerimaan Manual',
                        'manual_expense' => 'Pengeluaran Manual',
                        'transfer' => 'Transfer Antar Akun',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat'),
            ])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
