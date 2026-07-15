<?php

namespace App\Filament\Resources\TransactionLines\Tables;

use App\Enums\TransactionLineRole;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction.transaction_number')->label('المعاملة')->searchable()->sortable(),
                TextColumn::make('account.name')->label('الحساب')->searchable()->sortable(),
                TextColumn::make('currency.code')->label('العملة')->badge(),
                TextColumn::make('line_role')
                    ->label('دور السطر')
                    ->badge()
                    ->formatStateUsing(fn ($state) => TransactionLineRole::labelFor($state) ?? $state)
                    ->placeholder('—'),
                TextColumn::make('amount_currency')->label('المبلغ بالعملة')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('debit_base')->label('مدين')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('credit_base')->label('دائن')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('description')
                    ->label('وصف السطر')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->description)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('notes')->label('ملاحظات')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('transaction_id')->relationship('transaction', 'transaction_number')->searchable()->label('المعاملة'),
                SelectFilter::make('account_id')->relationship('account', 'name')->searchable()->preload()->label('الحساب'),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
