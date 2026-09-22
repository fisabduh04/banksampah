<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Resources\CashAccounts\CashAccountResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateCashAccount extends CreateRecord
{
    protected static string $resource = CashAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kembali')
                ->label('Kembali')
                ->icon('heroicon-o-arrow-left')
                ->url(CashAccountResource::getUrl('index')),
        ];
    }
}
