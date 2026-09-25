<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Filament\TransactionFailureNotification;
use App\Models\Sale;
use App\Services\SalePostingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeValidate(): void
    {
        $record = Sale::query()->whereKey($this->record->getKey())->lockForUpdate()->firstOrFail();
        if ($record->status !== Sale::STATUS_DRAFT) {
            throw ValidationException::withMessages(['data.status' => 'Transaksi sudah dibukukan atau dibatalkan. Perubahan tidak disimpan.']);
        }
    }

    /**
     * Judul halaman.
     */
    protected static ?string $title = 'Ubah Draft Penjualan';

    /**
     * Tombol pada bagian atas halaman.
     */
    protected function getHeaderActions(): array
    {
        return [
            /*
             * =========================================================
             * POSTING PENJUALAN
             * =========================================================
             */
            Action::make('posting')
                ->label('Posting Penjualan')
                ->icon('heroicon-o-check-circle')
                ->color('success')

                /*
                 * Posting merupakan tindakan final yang
                 * memengaruhi persediaan sehingga wajib dikonfirmasi.
                 */
                ->requiresConfirmation()
                ->modalHeading('Posting Penjualan?')
                ->modalDescription(
                    'Setelah diposting, transaksi tidak dapat diedit. '
                    .'Persediaan akan berkurang dan HPP serta laba kotor '
                    .'akan dihitung oleh sistem.'
                )
                ->modalSubmitActionLabel('Ya, Posting Penjualan')

                /*
                 * Tombol hanya berlaku untuk transaksi Draft.
                 */
                ->visible(
                    fn (): bool => $this->record->status === Sale::STATUS_DRAFT
                )

                /*
                 * Jalankan proses posting melalui SalePostingService.
                 */
                ->action(function (): void {
                    try {
                        /*
                         * Pastikan ada pengguna yang sedang login.
                         */
                        $userId = auth()->id();

                        if (! $userId) {
                            throw new RuntimeException(
                                'Pengguna tidak terautentikasi.'
                            );
                        }

                        /*
                         * Semua logika bisnis berada di service,
                         * bukan di halaman Filament.
                         */
                        app(SalePostingService::class)->post(
                            $this->record,
                            (int) $userId
                        );

                        /*
                         * Beri notifikasi bahwa transaksi berhasil.
                         */
                        Notification::make()
                            ->title('Penjualan berhasil diposting')
                            ->body(
                                'Persediaan telah dikurangi dan HPP serta laba kotor telah dihitung.'
                            )
                            ->success()
                            ->send();

                        /*
                         * Setelah posting berhasil, kembali ke tabel.
                         *
                         * Ini juga penting karena record sudah berubah
                         * dari Draft menjadi Posted dan tidak boleh diedit.
                         */
                        $this->redirect(
                            SaleResource::getUrl('index')
                        );
                    } catch (\Throwable $exception) {
                        TransactionFailureNotification::send($exception, 'Penjualan tidak dapat diposting');
                    }
                }),

            /*
             * =========================================================
             * HAPUS DRAFT
             * =========================================================
             */
            DeleteAction::make()
                ->label('Hapus Draft')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalHeading('Hapus Draft Penjualan?')
                ->modalDescription(
                    'Draft beserta seluruh rincian penjualannya akan dihapus. '
                    .'Tindakan ini tidak dapat dibatalkan.'
                )
                ->modalSubmitActionLabel('Ya, Hapus Draft'),
        ];
    }

    /**
     * Jangan menerima angka hasil perhitungan dari browser
     * sebagai angka resmi transaksi.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['sale_number']);
        unset($data['status']);

        unset($data['total_weight']);
        unset($data['total_amount']);

        unset($data['total_cost']);
        unset($data['gross_profit']);

        unset($data['posted_at']);
        unset($data['posted_by']);

        unset($data['cancelled_at']);
        unset($data['cancelled_by']);
        unset($data['cancellation_reason']);

        return $data;
    }

    /**
     * Setelah Draft disimpan, hitung ulang subtotal
     * serta total berdasarkan data database.
     */
    protected function afterSave(): void
    {
        /*
         * Hitung ulang subtotal setiap detail.
         */
        $this->record
            ->items()
            ->update([
                'subtotal' => DB::raw(
                    'ROUND(weight * price, 2)'
                ),
            ]);

        /*
         * Hitung total berat dan nilai penjualan.
         */
        $ringkasan = $this->record
            ->items()
            ->selectRaw(
                '
                    COALESCE(SUM(weight), 0) AS total_weight,
                    COALESCE(SUM(subtotal), 0) AS total_amount
                '
            )
            ->first();

        /*
         * Draft belum memiliki HPP dan laba final.
         */
        $this->record->update([
            'total_weight' => $ringkasan?->total_weight ?? 0,

            'total_amount' => $ringkasan?->total_amount ?? 0,

            'total_cost' => 0,
            'gross_profit' => 0,

            'status' => Sale::STATUS_DRAFT,
        ]);
    }

    /**
     * Tombol formulir bagian bawah.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label('Simpan Perubahan Draft')
                ->icon('heroicon-o-document-check'),

            $this->getCancelFormAction()
                ->label('Batal'),
        ];
    }

    /**
     * Setelah Draft disimpan, kembali ke tabel.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * Notifikasi perubahan Draft.
     */
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft penjualan berhasil diperbarui.';
    }
}
