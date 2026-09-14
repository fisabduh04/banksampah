<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Concerns\UsesIndonesianLocale;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use App\Services\SaleDraftService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateSale extends CreateRecord
{
    use UsesIndonesianLocale;

    protected static string $resource = SaleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /**
     * Judul halaman dalam Bahasa Indonesia.
     */
    protected static ?string $title = 'Tambah Penjualan ke Pengepul';

    /**
     * Kita tidak menggunakan fitur "Create & create another".
     */
    protected static bool $canCreateAnother = false;

    /**
     * Ubah data sebelum header transaksi Sale disimpan.
     *
     * Field internal tidak pernah dipercaya dari input pengguna.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /*
         * Gunakan nomor sementara yang unik.
         *
         * Setelah database memberikan ID transaksi,
         * nomor final akan dibuat pada afterCreate().
         */
        $data['sale_number'] = 'TMP-'.Str::ulid();

        /*
         * Setiap transaksi baru selalu dimulai sebagai Draft.
         */
        $data['status'] = Sale::STATUS_DRAFT;
        $data['payment_status'] = 'unpaid';

        /*
         * Total dari browser tidak dijadikan nilai final.
         * Setelah sale_items tersimpan, sistem menghitung ulang
         * berdasarkan data database.
         */
        $data['total_weight'] = 0;
        $data['total_amount'] = 0;

        /*
         * HPP dan laba belum dihitung pada tahap Draft.
         * Nilai ini baru dihitung saat transaksi diposting.
         */
        $data['total_cost'] = 0;
        $data['gross_profit'] = 0;

        /*
         * Informasi posting dan pembatalan harus kosong
         * ketika transaksi baru dibuat.
         */
        $data['posted_at'] = null;
        $data['posted_by'] = null;

        $data['cancelled_at'] = null;
        $data['cancelled_by'] = null;
        $data['cancellation_reason'] = null;

        return $data;
    }

    /**
     * Dipanggil setelah:
     *
     * 1. Header Sale dibuat.
     * 2. Relasi items dari Repeater disimpan.
     *
     * Pada tahap ini kita membuat nomor transaksi final
     * dan menghitung ulang subtotal serta total transaksi.
     */
    protected function afterCreate(): void
    {
        app(SaleDraftService::class)->recalculate($this->record);
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Simpan Draft')
                ->icon('heroicon-o-document-check'),

            $this->getCancelFormAction()
                ->label('Batal'),
        ];
    }

    /**
     * Setelah draft berhasil disimpan,
     * kembali ke daftar penjualan.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * Pesan setelah penyimpanan berhasil.
     */
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft penjualan berhasil disimpan.';
    }
}
