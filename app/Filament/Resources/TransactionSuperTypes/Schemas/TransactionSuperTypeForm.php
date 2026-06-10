<?php

namespace App\Filament\Resources\TransactionSuperTypes\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransactionSuperTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')
                    ->label('اسم التصنيف')
                    ->required()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
