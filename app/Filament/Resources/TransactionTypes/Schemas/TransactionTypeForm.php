<?php

namespace App\Filament\Resources\TransactionTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransactionTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('transaction_super_type_id')
                    ->label('التصنيف')
                    ->relationship('transactionSuperType', 'name')
                    ->searchable()
                    ->preload(),
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