<?php

namespace App\Filament\Resources\Settings\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('key')
                    ->label('المفتاح')
                    ->required()
                    ->maxLength(255),
                TextInput::make('group')
                    ->label('المجموعة')
                    ->required()
                    ->maxLength(255),
                TextInput::make('description')
                    ->label('الوصف')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('value')
                    ->label('القيمة')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}