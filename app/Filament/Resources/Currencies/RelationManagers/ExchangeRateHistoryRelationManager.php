<?php

namespace App\Filament\Resources\Currencies\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExchangeRateHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'exchangeRateHistory';

    protected static ?string $title = 'تاريخ أسعار الصرف';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')->label('التاريخ')->required(),
            TextInput::make('rate')
                ->label('سعر الصرف')
                ->required()
                ->numeric()
                ->step(0.000001),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                TextColumn::make('date')->label('التاريخ')->date()->sortable(),
                TextColumn::make('rate')->label('سعر الصرف')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state, 6))->html()->sortable(),
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
