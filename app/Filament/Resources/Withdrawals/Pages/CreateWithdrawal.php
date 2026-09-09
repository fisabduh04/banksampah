<?php

namespace App\Filament\Resources\Withdrawals\Pages;

use App\Filament\Resources\Withdrawals\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Exception;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWithdrawal extends CreateRecord
{
    /**
     * Resource teknis untuk fitur Penarikan Saldo.
     */
    protected static string $resource = WithdrawalResource::class;

    /**
     * Status yang dipilih operator pada form sebelum transaksi disimpan.
     *
     * Nilai ini digunakan untuk menentukan apakah transaksi:
     * - hanya disimpan sebagai draft, atau
     * - langsung dibukukan.
     */
    protected ?string $statusPilihan = null;

    /**
     * Tidak menampilkan fitur "buat dan buat lagi".
     * Untuk transaksi keuangan kita gunakan alur yang lebih sederhana.
     */
    protected static bool $canCreateAnother = false;

    /**
     * Menyiapkan data sebelum record dibuat.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /**
         * Simpan terlebih dahulu status yang dipilih operator.
         */
        $this->statusPilihan = $data['status'] ?? 'draft';

        /**
         * Record selalu dibuat sebagai draft terlebih dahulu.
         *
         * Ini sangat penting:
         * status posted tidak boleh langsung disimpan tanpa menjalankan
         * WithdrawalService karena Mutasi Saldo harus ikut terbentuk.
         */
        $data['status'] = 'draft';

        $year = now()->year;

        /**
         * Mengambil nomor penarikan terakhir pada tahun berjalan.
         */
        $lastWithdrawal = Withdrawal::query()
            ->whereYear('created_at', $year)
            ->latest('id')
            ->first();

        /**
         * Menentukan nomor urut transaksi berikutnya.
         *
         * Contoh:
         * WD-2026-000001
         * WD-2026-000002
         */
        $nextNumber = $lastWithdrawal
            ? ((int) substr($lastWithdrawal->withdrawal_number, -6)) + 1
            : 1;

        /**
         * Membuat nomor penarikan otomatis.
         */
        $data['withdrawal_number'] = sprintf(
            'WD-%s-%06d',
            $year,
            $nextNumber
        );

        return $data;
    }

    /**
     * Dijalankan setelah transaksi berhasil disimpan.
     */
    protected function afterCreate(): void
    {
        /**
         * Jika operator memilih Belum Dibukukan,
         * transaksi tetap draft dan belum memengaruhi saldo.
         */
        if ($this->statusPilihan !== 'posted') {
            Notification::make()
                ->title('Transaksi Tersimpan')
                ->body('Penarikan saldo disimpan sebagai Belum Dibukukan.')
                ->success()
                ->send();

            return;
        }

        try {
            /**
             * Jika operator memilih Telah Dibukukan,
             * jalankan proses pembukuan resmi.
             *
             * WithdrawalService akan:
             * 1. memeriksa saldo nasabah,
             * 2. mengubah status menjadi posted,
             * 3. membuat Mutasi Saldo Pengeluaran,
             * 4. mengurangi saldo nasabah.
             */
            app(WithdrawalService::class)->post($this->record);

            Notification::make()
                ->title('Penarikan Berhasil Dibukukan')
                ->body(
                    'Penarikan telah masuk ke Riwayat Saldo dan saldo nasabah telah dikurangi.'
                )
                ->success()
                ->send();

        } catch (Exception $e) {
            /**
             * Jika pembukuan gagal, transaksi tetap tersimpan sebagai draft.
             *
             * Contohnya:
             * - saldo nasabah tidak cukup,
             * - nominal tidak valid.
             */
            Notification::make()
                ->title('Penarikan Belum Dibukukan')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    /**
     * Setelah proses selesai, kembali ke daftar Penarikan Saldo.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
