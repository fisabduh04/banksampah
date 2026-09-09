<?php

namespace App\Filament\Resources\BalanceMutations\Pages;

use App\Filament\Exports\BalanceMutationExporter;
use App\Filament\Resources\BalanceMutations\BalanceMutationResource;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListBalanceMutations extends ListRecords
{
    /**
     * Resource teknis untuk fitur Mutasi Saldo.
     */
    protected static string $resource = BalanceMutationResource::class;

    /**
     * Mutasi Saldo hanya boleh diekspor.
     *
     * Tidak menyediakan Impor, Tambah, Edit, atau Hapus
     * karena mutasi harus berasal dari transaksi yang sah.
     */
    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label('Ekspor Data')
                ->icon('heroicon-o-arrow-down-tray')
                ->exporter(BalanceMutationExporter::class),
        ];
    }
}
