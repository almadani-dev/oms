<?php

namespace App\Filament\Resources\ExchangeRateHistories\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExchangeRateHistoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('currency_id')
                            ->label('العملة')
                            ->relationship('currency', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('date')
                            ->label('التاريخ')
                            ->required(),
                        TextInput::make('rate')
                            ->label('سعر الصرف')
                            ->required()
                            ->numeric()
                            ->step(0.000001),
                    ]),
            ]);
    }
}