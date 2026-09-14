<?php

namespace App\Filament\Resources\Sales\RelationManagers;

use App\Filament\Concerns\UsesIndonesianLocale;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\SalePayment;
use App\Services\SalePaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnexpectedValueException;

class PaymentsRelationManager extends RelationManager
{
    use UsesIndonesianLocale;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['receivedBy', 'cancelledBy', 'verifiedBy']))

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
                TextColumn::make('verified_at')->label('Verifikasi Bukti')->dateTime('d/m/Y H:i')->placeholder('Belum diverifikasi'),
                TextColumn::make('verifiedBy.name')->label('Diverifikasi Oleh')->placeholder('-'),
                TextColumn::make('verification_reference')->label('Bukti Verifikasi')->placeholder('-'),
                TextColumn::make('cancellation_type')->label('Jenis Pembatalan')->formatStateUsing(fn (?string $state): string => match ($state) {
                    'entry_error' => 'Salah catat', 'refund' => 'Pengembalian dana', default => '-'
                }),
                TextColumn::make('refund_reference')->label('Bukti Pengembalian')->placeholder('-'),
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
                Action::make('verifikasiPembayaran')
                    ->label('Verifikasi Bukti')
                    ->authorize(fn (): bool => auth()->user()?->canPerformFinancialOperation('approve') ?? false)
                    ->visible(fn (SalePayment $record): bool => $record->status === SalePayment::STATUS_POSTED && $record->verified_at === null)
                    ->schema([TextInput::make('reference')->label('Referensi Kuitansi atau Rekening Koran')->required()->maxLength(100)])
                    ->action(function (SalePayment $record, array $data): void {
                        try {
                            app(SalePaymentService::class)->verifyPayment($record, $data['reference'], (int) auth()->id());
                            Notification::make()->title('Bukti pembayaran telah diverifikasi')->success()->send();
                        } catch (UnexpectedValueException $exception) {
                            Notification::make()->title('Verifikasi ditolak')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                /*
                 * Pembayaran aktif boleh dibatalkan.
                 */
                Action::make('batalkanPembayaran')
                    ->authorize(fn (): bool => (auth()->user()?->canPerformFinancialOperation('approve') ?? false) && SaleResource::can('update', $this->getOwnerRecord()))
                    ->label('Batalkan Pembayaran')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')

                    ->visible(
                        fn (SalePayment $record): bool => $record->status === SalePayment::STATUS_POSTED
                    )

                    ->schema([
                        Select::make('cancellation_type')->label('Jenis Pembatalan')->options(['entry_error' => 'Salah catat, dana tidak diterima', 'refund' => 'Dana dikembalikan'])->required()->default('entry_error'),
                        TextInput::make('refund_reference')->label('Referensi Bukti Pengembalian Dana')->maxLength(100),
                        Textarea::make('reason')
                            ->label('Alasan Pembatalan')
                            ->placeholder(
                                'Jelaskan alasan pembayaran harus dibatalkan.'
                            )
                            ->required()
                            ->rows(3)
                            ->maxLength(2000),
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
                                    throw new UnexpectedValueException(
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
                                        cancellationType: $data['cancellation_type'],
                                        refundReference: $data['refund_reference'] ?? null,
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

                            } catch (UnexpectedValueException $exception) {
                                Notification::make()
                                    ->title(
                                        'Pembayaran tidak dapat dibatalkan'
                                    )
                                    ->body(
                                        $exception->getMessage()
                                    )
                                    ->danger()
                                    ->persistent()
                                    ->send();

                            } catch (Throwable $exception) {
                                report($exception);

                                Notification::make()
                                    ->title('Terjadi kesalahan')
                                    ->body('Pembatalan gagal diproses. Hubungi administrator.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
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
