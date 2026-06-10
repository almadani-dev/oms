<?php

namespace App\Filament\Resources\ProjectStatuses\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectStatusForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label('اسم الحالة')
                    ->required()
                    ->maxLength(255),
                ColorPicker::make('color')
                    ->label('اللون'),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}