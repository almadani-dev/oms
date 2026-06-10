<?php

namespace App\Filament\Resources\ProjectCosts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectCostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('project_id')
                    ->label('المشروع')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->columnSpanFull(),
                Select::make('account_type_id')
                    ->label('نوع الحساب')
                    ->relationship('accountType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required(),
                TextInput::make('administrative_percentage')
                    ->label('نسبة الإدارة %')
                    ->numeric()
                    ->suffix('%')
                    ->default(0),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}