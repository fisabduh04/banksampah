<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    /**
     * Judul halaman detail transaksi.
     */
    protected static ?string $title = 'Detail Penjualan';

    /**
     * Tidak menyediakan tombol Edit pada halaman detail.
     *
     * Draft diedit melalui halaman EditSale.
     * Posted dan Cancelled hanya boleh dilihat.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
