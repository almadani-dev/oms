<?php

namespace App\Filament\Resources\ProjectCostReceipts\Tables;

use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectSuper;
use App\Models\TransactionSuperType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProjectCostReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('projectCost.project.name')
                    ->label('المشروع')
                    ->sortable(),

                TextColumn::make('transaction.partner.name')
                    ->label('الجهة المانحة')
                    ->sortable(),

                TextColumn::make('transaction.transactionType.name')
                    ->label('نوع المعاملة')
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
            ->filters([
                SelectFilter::make('project_super')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('projectCost.project', fn ($q) => $q->where('project_super_id', $data['value']))
                        : $query),

                SelectFilter::make('project')
                    ->label('المشروع')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('projectCost', fn ($q) => $q->where('project_id', $data['value']))
                        : $query),

                SelectFilter::make('partner')
                    ->label('الجهة المانحة')
                    ->options(fn () => Partner::where('is_donor', true)->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('transaction', fn ($q) => $q->where('partner_id', $data['value']))
                        : $query),

                SelectFilter::make('transaction_super_type')
                    ->label('تصنيف المعاملة')
                    ->options(fn () => TransactionSuperType::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('transaction.transactionType', fn ($q) => $q->where('transaction_super_type_id', $data['value']))
                        : $query),

                SelectFilter::make('fiscal_year')
                    ->label('السنة المالية')
                    ->options(fn () => FiscalYear::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('transaction', fn ($q) => $q->where('fiscal_year_id', $data['value']))
                        : $query),

                Filter::make('date')
                    ->label('نطاق التاريخ')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('date_from')->label('من'),
                        \Filament\Forms\Components\DatePicker::make('date_until')->label('إلى'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['date_from'] ?? null, fn ($q) => $q->whereDate('date', '>=', $data['date_from']))
                        ->when($data['date_until'] ?? null, fn ($q) => $q->whereDate('date', '<=', $data['date_until']))),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(fn ($record) => self::deleteReceipt($record))
                    ->successNotificationTitle('تم حذف الاستلام بنجاح'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                self::deleteReceipt($record);
                            }
                        })
                        ->successNotificationTitle('تم حذف الاستلامات المحددة بنجاح'),
                ]),
            ]);
    }

    /**
     * Delete a receipt while reversing account balances and removing the
     * related transaction, transaction lines and attachment.
     */
    public static function deleteReceipt($record): void
    {
        DB::transaction(function () use ($record) {

            // STEP 1 - Get the transaction
            $transaction = $record->transaction;

            if ($transaction) {
                // STEP 2 - Get the transaction lines (relationship is lines())
                $debitLine  = $transaction->lines()->where('debit_base', '>', 0)->first();
                $creditLine = $transaction->lines()->where('credit_base', '>', 0)->first();

                // STEP 3 - Reverse account balances
                if ($debitLine && $debitLine->account) {
                    $debitLine->account->decrement('current_balance', $record->amount);
                }
                if ($creditLine && $creditLine->account) {
                    $creditLine->account->increment('current_balance', $record->amount);
                }

                // STEP 4 - Soft delete the transaction lines
                $transaction->lines()->delete();

                // STEP 5 - Soft delete the transaction
                $transaction->delete();
            }

            // STEP 6 - Soft delete the attachment record (keep the physical file for audit)
            $attachment = $record->attachments()->first();
            if ($attachment) {
                $attachment->delete();
            }

            // STEP 7 - Soft delete the receipt record
            $record->delete();
        });
    }
}
