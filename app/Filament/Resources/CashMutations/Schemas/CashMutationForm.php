<?php

namespace App\Filament\Resources\CashMutations\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class CashMutationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cash_account_id')
                    ->relationship('cashAccount', 'name')
                    ->required(),
                DatePicker::make('transaction_date')
                    ->required(),
                TextInput::make('mutation_type')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('reference_type')
                    ->required(),
                TextInput::make('reference_id')
                    ->numeric(),
                TextInput::make('reference_number'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('created_by')
                    ->numeric(),
            ]);
    }
}
