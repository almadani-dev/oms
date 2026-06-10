<?php

namespace App\Filament\Resources\Currencies\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم العملة')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('رمز العملة')
                            ->required()
                            ->maxLength(10)
                            ->dehydrateStateUsing(fn ($state) => strtoupper($state))
                            ->afterStateUpdated(fn ($set, $state) => $set('code', strtoupper($state)))
                            ->live(),
                        TextInput::make('symbol')
                            ->label('الرمز المختصر')
                            ->required()
                            ->maxLength(10),
                        Toggle::make('is_base')
                            ->label('العملة الأساسية')
                            ->default(false),
                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}