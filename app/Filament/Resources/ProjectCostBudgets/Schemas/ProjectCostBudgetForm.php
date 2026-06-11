<?php

namespace App\Filament\Resources\ProjectCostBudgets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectCostBudgetForm
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
                TextInput::make('administrative_percentage')
                    ->label('نسبة الإدارة %')
                    ->numeric()
                    ->suffix('%')
                    ->default(0),
                TextInput::make('transfer_percentage')
                    ->label('نسبة التحويل %')
                    ->numeric()
                    ->suffix('%')
                    ->default(0),
                TextInput::make('exchange_percentage')
                    ->label('نسبة الصرف %')
                    ->numeric()
                    ->suffix('%')
                    ->default(0),
                TextInput::make('amount_after_percentages')
                    ->label('المبلغ بعد النسب')
                    ->numeric()
                    ->default(0),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
