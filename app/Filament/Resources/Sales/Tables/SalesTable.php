<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Filament\Resources\Sales\SaleResource;
use App\Filament\TransactionFailureNotification;
use App\Models\Sale;
use App\Services\SalePaymentService;
use App\Services\SalePostingService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use RuntimeException;

class SalesTable
{
    /**
     * Konfigurasi tabel transaksi penjualan ke pengepul.
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sale_number')
                    ->label('Nomor Penjualan')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('collector.name')
                    ->label('Pengepul')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => match ($state) {
                            Sale::STATUS_DRAFT => 'Draft',
                            Sale::STATUS_POSTED => 'Diposting',
                            Sale::STATUS_CANCELLED => 'Dibatalkan',
                            default => ucfirst($state),
                        }
                    )
                    ->color(
                        fn (string $state): string => match ($state) {
                            Sale::STATUS_DRAFT => 'warning',
                            Sale::STATUS_POSTED => 'success',
                            Sale::STATUS_CANCELLED => 'danger',
                            default => 'gray',
                        }
                    )
                    ->sortable(),

                TextColumn::make('total_weight')
                    ->label('Total Berat')
                    ->formatStateUsing(
                        fn ($state): string => number_format(
                            (float) $state,
                            3,
                            ',',
                            '.'
                        ).' kg'
                    )
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total Penjualan')
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

                TextColumn::make('total_cost')
                    ->label('HPP')
                    ->formatStateUsing(
                        fn ($state): string => 'Rp '.number_format(
                            (float) $state,
                            0,
                            ',',
                            '.'
                        )
                    )
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('gross_profit')
                    ->label('Laba Kotor')
                    ->formatStateUsing(
                        fn ($state): string => 'Rp '.number_format(
                            (float) $state,
                            0,
                            ',',
                            '.'
                        )
                    )
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('posted_at')
                    ->label('Waktu Posting')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('postedBy.name')
                    ->label('Diposting Oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cancelled_at')
                    ->label('Waktu Pembatalan')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cancelledBy.name')
                    ->label('Dibatalkan Oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Terakhir Diubah')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payment_status')
                    ->label('Status Pembayaran')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            'unpaid' => 'Belum Dibayar',
                            'partial' => 'Dibayar Sebagian',
                            'paid' => 'Lunas',
                            default => '-',
                        }
                    )
                    ->color(
                        fn (?string $state): string => match ($state) {
                            'unpaid' => 'danger',
                            'partial' => 'warning',
                            'paid' => 'success',
                            default => 'gray',
                        }
                    ),

                TextColumn::make('paid_amount')
                    ->label('Sudah Dibayar')
                    ->formatStateUsing(
                        fn ($state): string => 'Rp '.number_format(
                            (float) $state,
                            0,
                            ',',
                            '.'
                        )
                    )
                    ->alignEnd(),

                TextColumn::make('outstanding_amount')
                    ->label('Sisa Piutang')
                    ->formatStateUsing(
                        fn ($state): string => 'Rp '.number_format(
                            (float) $state,
                            0,
                            ',',
                            '.'
                        )
                    )
                    ->alignEnd(),
            ])

            ->filters([
                SelectFilter::make('status')
                    ->label('Status Transaksi')
                    ->options([
                        Sale::STATUS_DRAFT => 'Draft',
                        Sale::STATUS_POSTED => 'Diposting',
                        Sale::STATUS_CANCELLED => 'Dibatalkan',
                    ]),

                SelectFilter::make('collector_id')
                    ->label('Pengepul')
                    ->relationship(
                        'collector',
                        'name'
                    )
                    ->searchable()
                    ->preload(),
            ])

            ->recordActions([

                /*
     * Semua transaksi boleh dilihat detailnya.
     */
                ViewAction::make()
                    ->label('Lihat Detail')
                    ->icon('heroicon-o-eye'),
                /*
                 * Draft masih boleh diubah.
                 */
                EditAction::make()
                    ->label('Ubah Draft')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(
                        fn (Sale $record): bool => $record->status === Sale::STATUS_DRAFT
                    ),

                /*
                 * Transaksi Posted dapat dibatalkan.
                 */
                Action::make('batalkanPenjualan')
                    ->label('Batalkan Penjualan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(
                        fn (Sale $record): bool => $record->status === Sale::STATUS_POSTED
                    )
                    ->form([
                        Textarea::make('reason')
                            ->label('Alasan Pembatalan Penjualan')
                            ->placeholder(
                                'Jelaskan alasan transaksi penjualan dibatalkan.'
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
                    ->modalHeading('Batalkan Transaksi Penjualan?')
                    ->modalDescription(
                        'Transaksi penjualan akan dibatalkan dan persediaan '
                        .'akan dikembalikan melalui mutasi pembalik.'
                    )
                    ->modalSubmitActionLabel('Ya, Batalkan Penjualan')
                    ->action(
                        function (
                            Sale $record,
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
                 * Pembatalan SELURUH transaksi penjualan.
                 */
                                app(SalePostingService::class)->cancel(
                                    $record,
                                    (int) $userId,
                                    $data['reason'],
                                    (bool) ($data['confirmed_correction'] ?? false)
                                );

                                Notification::make()
                                    ->title('Penjualan berhasil dibatalkan')
                                    ->body(
                                        'Persediaan telah dikembalikan '
                                        .'dan transaksi ditandai Dibatalkan.'
                                    )
                                    ->success()
                                    ->send();

                            } catch (\Throwable $exception) {
                                TransactionFailureNotification::send($exception, 'Penjualan tidak dapat dibatalkan');
                            }
                        }
                    ),

                Action::make('catatPembayaran')
                    ->label('Catat Pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')

    /*
                         * Hanya penjualan yang sudah diposting dan
                         * belum lunas yang dapat menerima pembayaran.
                         */
                    ->visible(
                        fn (Sale $record): bool => $record->status === Sale::STATUS_POSTED
                            && $record->payment_status !== 'paid'
                    )
                    ->form([
                        Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid())->required(),
                        DatePicker::make('payment_date')
                            ->validationMessages([
                                'required' => 'Isi tanggal uang benar-benar diterima.',
                                'date' => 'Tanggal pembayaran tidak valid. Pilih tanggal sesuai bukti.',
                            ])
                            ->label('Tanggal Pembayaran')
                            ->default(now())
                            ->required(),

                        TextInput::make('amount')
                            ->validationMessages([
                                'required' => 'Isi nominal uang sesuai bukti transaksi.',
                                'numeric' => 'Nominal harus berupa angka.',
                                'min' => 'Nominal minimal Rp :min.',
                            ])
                            ->label('Jumlah Pembayaran')
                            ->numeric()
                            ->prefix('Rp')
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01),

                        Select::make('payment_method')
                            ->validationMessages([
                                'required' => 'Pilih cara pembayaran sesuai bukti penerimaan uang.',
                            ])
                            ->label('Metode Pembayaran')
                            ->options([
                                'cash' => 'Tunai',
                                'transfer' => 'Transfer',
                                'other' => 'Lainnya',
                            ])
                            ->required(),

                        TextInput::make('reference_number')
                            ->label('Nomor Referensi')
                            ->placeholder(
                                'Contoh: nomor transfer atau bukti pembayaran'
                            )
                            ->maxLength(100),

                        Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->modalHeading('Catat Pembayaran Pengepul')
                    ->modalDescription(
                        'Masukkan pembayaran yang diterima untuk transaksi penjualan ini.'
                    )
                    ->modalSubmitActionLabel('Simpan Pembayaran')
                    ->action(
                        function (
                            Sale $record,
                            array $data
                        ): void {
                            try {
                                $userId = auth()->id();

                                if (! $userId) {
                                    throw new RuntimeException(
                                        'Pengguna tidak terautentikasi.'
                                    );
                                }

                                app(SalePaymentService::class)
                                    ->recordPayment(
                                        sale: $record,
                                        amount: (string) $data['amount'],
                                        paymentDate: $data['payment_date'],
                                        paymentMethod: $data['payment_method'],
                                        referenceNumber: $data['reference_number'] ?? null,
                                        notes: $data['notes'] ?? null,
                                        userId: (int) $userId,
                                        idempotencyKey: $data['idempotency_key']
                                    );

                                Notification::make()
                                    ->title(
                                        'Pembayaran berhasil dicatat'
                                    )
                                    ->body(
                                        'Status pembayaran penjualan telah diperbarui.'
                                    )
                                    ->success()
                                    ->send();

                            } catch (\Throwable $exception) {
                                TransactionFailureNotification::send($exception, 'Pembayaran tidak dapat dicatat');
                            }
                        }
                    ),
            ])

            ->recordUrl(
                fn (Sale $record): ?string => $record->status === Sale::STATUS_DRAFT
                        ? SaleResource::getUrl(
                            'edit',
                            ['record' => $record]
                        )
                        : null
            )

            /*
             * Tidak ada penghapusan massal transaksi.
             */
            ->toolbarActions([])

            /*
             * Transaksi terbaru tampil terlebih dahulu.
             */
            ->defaultSort('transaction_date', 'desc');
    }
}
