<?php

namespace App\Filament\Resources\ProjectCosts\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BudgetsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgets';

    protected static ?string $title = 'الميزانيات';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('administrative_percentage')->label('نسبة الإدارة %')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('transfer_percentage')->label('نسبة التحويل %')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('exchange_percentage')->label('نسبة الصرف %')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('amount_after_percentages')->label('المبلغ بعد النسب')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
