<?php

namespace App\Filament\Resources\Transactions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_number')->label('رقم المعاملة')->searchable()->sortable()->copyable(),
                TextColumn::make('transactionType.name')->label('نوع المعاملة')->badge()->sortable(),
                TextColumn::make('fiscalYear.name')->label('السنة المالية')->sortable(),
                TextColumn::make('partner.name')->label('الشريك')->searchable()->sortable(),
                TextColumn::make('transaction_time')->label('التاريخ')->dateTime()->sortable(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('fiscal_year_id')->relationship('fiscalYear', 'name')->label('السنة المالية'),
                SelectFilter::make('transaction_type_id')->relationship('transactionType', 'name')->label('نوع المعاملة'),
                SelectFilter::make('partner_id')->relationship('partner', 'name')->searchable()->preload()->label('الشريك'),
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
