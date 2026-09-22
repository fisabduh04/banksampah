<?php

namespace App\Filament\Resources\CashAccounts\Pages;

use App\Filament\Resources\CashAccounts\CashAccountResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCashAccount extends EditRecord
{
    protected static string $resource = CashAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kembali')
                ->label('Kembali')
                ->icon('heroicon-o-arrow-left')
                ->url(CashAccountResource::getUrl('index')),

            DeleteAction::make(),
        ];
    }
}
