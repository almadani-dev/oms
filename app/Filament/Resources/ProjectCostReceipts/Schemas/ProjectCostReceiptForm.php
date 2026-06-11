<?php

namespace App\Filament\Resources\ProjectCostReceipts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectCostReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('project_cost_id')
                    ->label('تكلفة المشروع')
                    ->relationship('projectCost', 'id')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->columnSpanFull(),
                Select::make('transaction_id')
                    ->label('المعاملة المالية')
                    ->relationship('transaction', 'transaction_number')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required(),
                DatePicker::make('date')
                    ->label('التاريخ')
                    ->required(),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
