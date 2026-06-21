<?php

namespace App\Filament\Resources\Accounts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table            ->columns([
                TextColumn::make('account_code')->label('رقم الحساب')->searchable()->sortable()->copyable(),
                TextColumn::make('name')->label('اسم الحساب')->searchable()->sortable(),
                TextColumn::make('accountType.name')->label('نوع الحساب')->badge()->sortable(),
                TextColumn::make('bankType.name')->label('نوع البنك')->badge()->sortable(),
                TextColumn::make('currency.code')->label('العملة')->badge(),
                TextColumn::make('current_balance')->label('الرصيد الحالي')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                TextColumn::make('iban')->label('رقم الآيبان')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('account_type_id')->relationship('accountType', 'name')->searchable()->preload()->label('نوع الحساب'),
                SelectFilter::make('currency_id')->relationship('currency', 'name')->searchable()->preload()->label('العملة'),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
