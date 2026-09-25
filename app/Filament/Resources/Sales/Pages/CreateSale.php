<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\CreatesFinancialDocument;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateSale extends CreateRecord
{
    use CreatesFinancialDocument;

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
        /** Nomor tetap untuk setiap pengiriman ulang formulir yang sama. */
        $data['sale_number'] = $this->creationNumber;

        /*
         * Setiap transaksi baru selalu dimulai sebagai Draft.
         */
        $data['status'] = Sale::STATUS_DRAFT;

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
     * Hitung ulang subtotal serta total dalam transaksi penyimpanan dokumen.
     */
    protected function afterCreate(): void
    {
        /*
         * ============================================================
         * 2. HITUNG ULANG SUBTOTAL DI DATABASE
         * ============================================================
         *
         * Jangan hanya mempercayai subtotal dari Livewire/browser.
         *
         * MySQL melakukan perkalian terhadap kolom DECIMAL
         * langsung di database.
         */
        $this->record
            ->items()
            ->update([
                'subtotal' => DB::raw(
                    'ROUND(weight * price, 2)'
                ),
            ]);

        /*
         * ============================================================
         * 3. HITUNG TOTAL BERAT DAN TOTAL PENJUALAN
         * ============================================================
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
         * ============================================================
         * 4. SIMPAN NILAI FINAL HEADER TRANSAKSI
         * ============================================================
         */
        $this->record->update([
            'total_weight' => $ringkasan?->total_weight ?? 0,

            'total_amount' => $ringkasan?->total_amount ?? 0,

            /*
             * Tetap nol karena transaksi masih Draft.
             *
             * HPP dan laba dihitung ketika Posting.
             */
            'total_cost' => 0,
            'gross_profit' => 0,

            'status' => Sale::STATUS_DRAFT,
        ]);
    }

    /**
     * Tombol pada bagian bawah formulir.
     */
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
