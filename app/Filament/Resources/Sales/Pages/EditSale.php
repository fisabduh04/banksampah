<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use App\Services\SalePostingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

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
                    } catch (RuntimeException $exception) {
                        /*
                         * Kesalahan aturan bisnis dapat ditampilkan
                         * langsung kepada operator.
                         *
                         * Contoh:
                         * - stok tidak cukup;
                         * - transaksi bukan Draft;
                         * - harga tidak valid.
                         */
                        Notification::make()
                            ->title('Penjualan tidak dapat diposting')
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (Throwable $exception) {
                        /*
                         * Kesalahan teknis tidak ditampilkan detailnya
                         * kepada pengguna.
                         */
                        report($exception);

                        Notification::make()
                            ->title('Terjadi kesalahan')
                            ->body(
                                'Posting penjualan gagal diproses. '
                                .'Silakan periksa log aplikasi.'
                            )
                            ->danger()
                            ->persistent()
                            ->send();
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
         * Nomor transaksi mengikuti tanggal transaksi.
         */
        $tanggal = Carbon::parse(
            $this->record->transaction_date
        )->format('Ymd');

        $nomorPenjualan =
            'PJ-'
            .$tanggal
            .'-'
            .str_pad(
                (string) $this->record->getKey(),
                6,
                '0',
                STR_PAD_LEFT
            );

        /*
         * Draft belum memiliki HPP dan laba final.
         */
        $this->record->update([
            'sale_number' => $nomorPenjualan,

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
