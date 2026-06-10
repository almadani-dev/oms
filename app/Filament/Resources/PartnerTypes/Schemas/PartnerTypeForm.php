<?php

namespace App\Filament\Resources\PartnerTypes\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')
                    ->label('اسم النوع')
                    ->required()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}