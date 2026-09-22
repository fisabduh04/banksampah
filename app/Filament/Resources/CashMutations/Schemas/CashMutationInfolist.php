<?php

namespace App\Filament\Resources\CashMutations\Schemas;

use App\Models\CashMutation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CashMutationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Mutasi')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('transaction_date')
                            ->label('Tanggal Transaksi')
                            ->date('d/m/Y'),

                        TextEntry::make('cashAccount.name')
                            ->label('Akun Kas/Bank')
                            ->formatStateUsing(
                                fn ($state, CashMutation $record): string => ($record->cashAccount?->code ?? '-')
                                    .' — '
                                    .($record->cashAccount?->name ?? '-')
                            ),

                        TextEntry::make('mutation_type')
                            ->label('Jenis Mutasi')
                            ->badge()
                            ->formatStateUsing(
                                fn (?string $state): string => match ($state) {
                                    CashMutation::TYPE_IN => 'Masuk',
                                    CashMutation::TYPE_OUT => 'Keluar',
                                    default => '-',
                                }
                            ),

                        TextEntry::make('amount')
                            ->label('Nominal')
                            ->formatStateUsing(
                                fn ($state): string => 'Rp '.number_format(
                                    (float) $state,
                                    0,
                                    ',',
                                    '.'
                                )
                            ),

                        TextEntry::make('reference_type')
                            ->label('Sumber Transaksi')
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
                            ),

                        TextEntry::make('reference_number')
                            ->label('Nomor Referensi')
                            ->placeholder('-'),

                        TextEntry::make('description')
                            ->label('Keterangan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),

                Section::make('Jejak Sistem')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('createdBy.name')
                            ->label('Dicatat Oleh')
                            ->placeholder('-'),

                        TextEntry::make('created_at')
                            ->label('Waktu Pencatatan')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('id')
                            ->label('ID Ledger'),

                        TextEntry::make('reference_id')
                            ->label('ID Sumber')
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
