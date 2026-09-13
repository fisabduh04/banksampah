<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected static ?string $title = 'Penjualan ke Pengepul';

    /**
     * Tombol untuk membuat transaksi penjualan baru.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah Penjualan')
                ->icon('heroicon-o-plus'),
        ];
    }
}
