<?php

namespace App\Filament\Resources\ProjectCostBudgets\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'الدفعات';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('transaction.transaction_number')->label('المعاملة المالية')->searchable()->sortable(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('date')->label('التاريخ')->date()->sortable(),
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
