<?php

namespace App\Filament\Resources\FiscalYears\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FiscalYearForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم السنة المالية')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        DatePicker::make('start_date')
                            ->label('تاريخ البداية')
                            ->required(),
                        DatePicker::make('end_date')
                            ->label('تاريخ النهاية')
                            ->required(),
                        Toggle::make('is_active')
                            ->label('نشطة')
                            ->default(false),
                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}