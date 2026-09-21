<?php

namespace App\Filament\Resources\Sales\RelationManagers;

use App\Filament\TransactionFailureNotification;
use App\Models\SalePayment;
use App\Services\SalePaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class PaymentsRelationManager extends RelationManager
{
    /**
     * Relasi payments() berada pada model Sale.
     */
    protected static string $relationship = 'payments';

    /**
     * Judul relation manager.
     */
    protected static ?string $title = 'Riwayat Pembayaran';

    /**
     * Form generik tidak digunakan.
     *
     * Pencatatan pembayaran dilakukan melalui
     * SalePaymentService dari tabel Penjualan.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    /**
     * Tabel riwayat pembayaran pengepul.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_number')

            ->columns([
                TextColumn::make('payment_number')
                    ->label('Nomor Pembayaran')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payment_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Jumlah Pembayaran')
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

                TextColumn::make('payment_method')
                    ->label('Metode')
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            'cash' => 'Tunai',
                            'transfer' => 'Transfer',
                            'other' => 'Lainnya',
                            default => ucfirst((string) $state),
                        }
                    ),

                TextColumn::make('reference_number')
                    ->label('Nomor Referensi')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            SalePayment::STATUS_POSTED => 'Aktif',
                            SalePayment::STATUS_CANCELLED => 'Dibatalkan',
                            default => '-',
                        }
                    )
                    ->color(
                        fn (?string $state): string => match ($state) {
                            SalePayment::STATUS_POSTED => 'success',
                            SalePayment::STATUS_CANCELLED => 'danger',
                            default => 'gray',
                        }
                    ),

                /*
                 * Tampilkan nama pengguna, bukan ID.
                 */
                TextColumn::make('receivedBy.name')
                    ->label('Diterima Oleh')
                    ->placeholder('-'),

                TextColumn::make('cancelled_at')
                    ->label('Waktu Pembatalan')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cancelledBy.name')
                    ->label('Dibatalkan Oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cancellation_reason')
                    ->label('Alasan Pembatalan')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Dicatat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            /*
             * Tidak ada CreateAction di Relation Manager.
             * Pencatatan pembayaran tetap melalui
             * tombol Catat Pembayaran pada SalesTable.
             */
            ->headerActions([])

            ->recordActions([
                /*
                 * Pembayaran aktif boleh dibatalkan.
                 */
                Action::make('batalkanPembayaran')
                    ->label('Batalkan Pembayaran')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')

                    ->visible(
                        fn (SalePayment $record): bool => $record->status === SalePayment::STATUS_POSTED
                    )

                    ->form([
                        Textarea::make('reason')
                            ->label('Alasan Pembatalan')
                            ->placeholder(
                                'Jelaskan alasan pembayaran harus dibatalkan.'
                            )
                            ->required()
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Jelaskan salah input. Jika tercatat ganda, cantumkan nomor transaksi yang benar.'),
                        Checkbox::make('confirmed_correction')
                            ->label('Saya telah memeriksa: ini koreksi pencatatan, bukan pengembalian uang atau barang.')
                            ->rules(['accepted']),
                    ])

                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Pembayaran?')
                    ->modalDescription(
                        'Pembayaran tidak akan dihapus. '
                        .'Status pembayaran akan diubah menjadi Dibatalkan '
                        .'dan saldo piutang akan dihitung ulang.'
                    )
                    ->modalSubmitActionLabel(
                        'Ya, Batalkan Pembayaran'
                    )

                    ->action(
                        function (
                            SalePayment $record,
                            array $data
                        ): void {
                            try {
                                $userId = auth()->id();

                                if (! $userId) {
                                    throw new RuntimeException(
                                        'Pengguna tidak terautentikasi.'
                                    );
                                }

                                /*
                                 * Semua aturan pembatalan pembayaran
                                 * ditangani melalui service.
                                 */
                                app(SalePaymentService::class)
                                    ->cancelPayment(
                                        payment: $record,
                                        reason: $data['reason'],
                                        userId: (int) $userId,
                                        confirmedCorrection: (bool) ($data['confirmed_correction'] ?? false)
                                    );

                                Notification::make()
                                    ->title(
                                        'Pembayaran berhasil dibatalkan'
                                    )
                                    ->body(
                                        'Status pembayaran dan sisa piutang telah dihitung ulang.'
                                    )
                                    ->success()
                                    ->send();

                            } catch (\Throwable $exception) {
                                TransactionFailureNotification::send($exception, 'Pembayaran tidak dapat dibatalkan');
                            }
                        }
                    ),
            ])

            /*
             * Tidak ada bulk delete/dissociate.
             */
            ->toolbarActions([])

            ->defaultSort(
                'payment_date',
                'desc'
            );
    }
}
