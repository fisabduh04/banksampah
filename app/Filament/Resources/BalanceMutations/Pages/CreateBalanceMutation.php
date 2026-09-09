<?php

namespace App\Filament\Resources\BalanceMutations\Pages;

use App\Filament\Resources\BalanceMutations\BalanceMutationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBalanceMutation extends CreateRecord
{
    protected static string $resource = BalanceMutationResource::class;
}
