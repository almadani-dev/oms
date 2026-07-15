<?php

namespace App\Filament\Resources\Transactions\RelationManagers;

use App\Enums\TransactionLineRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
                TextColumn::make('line_role')
                    ->label('دور السطر')
                    ->badge()
                    ->formatStateUsing(fn ($state) => TransactionLineRole::labelFor($state) ?? $state)
                    ->placeholder('—'),
                TextColumn::make('amount_currency')->label('المبلغ بالعملة')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('fx_rate')->label('سعر الصرف')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state, 6))->html(),
                TextColumn::make('debit_base')->label('مدين')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('credit_base')->label('دائن')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('description')
                    ->label('وصف السطر')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->description)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Read-only audit table: lines are only ever written by the 6 legitimate
     * financial flows (which also keep account balances in sync), never
     * through this relation manager. Hardened at the authorization layer,
     * not just by omitting header/record actions above.
     */
    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    protected function canDeleteAny(): bool
    {
        return false;
    }

    protected function canRestore(Model $record): bool
    {
        return false;
    }

    protected function canRestoreAny(): bool
    {
        return false;
    }

    protected function canForceDelete(Model $record): bool
    {
        return false;
    }

    protected function canForceDeleteAny(): bool
    {
        return false;
    }
}
