<?php

namespace App\Filament\Resources\ProjectCosts\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    protected static ?string $title = 'الاستلامات';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('transaction.partner.name')
                    ->label('الجهة المانحة')
                    ->sortable(),

                TextColumn::make('debit_account')
                    ->label('الحساب المدين')
                    ->state(fn ($record) => $record->transaction
                        ?->lines()->with('account')->where('debit_base', '>', 0)->first()
                        ?->account?->name),

                TextColumn::make('credit_account')
                    ->label('الحساب الدائن')
                    ->state(fn ($record) => $record->transaction
                        ?->lines()->with('account')->where('credit_base', '>', 0)->first()
                        ?->account?->name),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                TextColumn::make('date')
                    ->label('التاريخ')
                    ->date()
                    ->sortable(),

                TextColumn::make('has_attachment')
                    ->label('إشعار مرفق')
                    ->badge()
                    ->state(fn ($record) => $record->attachments()->exists() ? 'نعم' : 'لا')
                    ->color(fn ($state) => $state === 'نعم' ? 'success' : 'gray'),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
