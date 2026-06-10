<?php

namespace App\Filament\Resources\ProjectSupers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectSuperForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(1)->schema([
                TextInput::make('name')
                    ->label('اسم المشروع الرئيسي')
                    ->required()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}