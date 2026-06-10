<?php

namespace App\Filament\Resources\Transactions\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'سطور القيد';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('account_id')->relationship('account', 'name')->searchable()->preload()->required()->label('الحساب'),
            Select::make('currency_id')->relationship('currency', 'name')->searchable()->preload()->required()->label('العملة'),
            Select::make('project_cost_id')->relationship('projectCost', 'id')->searchable()->preload()->label('تكلفة المشروع'),
            TextInput::make('amount_currency')->numeric()->required()->label('المبلغ بالعملة'),
            TextInput::make('fx_rate')->numeric()->default(1)->label('سعر الصرف'),
            TextInput::make('debit_base')->numeric()->default(0)->label('مدين'),
            TextInput::make('credit_base')->numeric()->default(0)->label('دائن'),
            Textarea::make('notes')->label('ملاحظات'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account.name')
            ->columns([
                TextColumn::make('account.name')->label('الحساب')->sortable(),
                TextColumn::make('currency.code')->label('العملة')->badge(),
                TextColumn::make('amount_currency')->label('المبلغ بالعملة')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('fx_rate')->label('سعر الصرف')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state, 6))->html(),
                TextColumn::make('debit_base')->label('مدين')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('credit_base')->label('دائن')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
