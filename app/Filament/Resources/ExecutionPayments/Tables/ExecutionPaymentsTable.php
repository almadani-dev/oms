<?php

namespace App\Filament\Resources\ExecutionPayments\Tables;

use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectSuper;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ExecutionPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->searchable()
                    ->sortable(),

                // --- Detailed: project super (hidden by default) ---
                TextColumn::make('projectCostBudget.projectCost.project.projectSuper.name')
                    ->label('المشروع الرئيسي')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('projectCostBudget.projectCost.project.name')
                    ->label('المشروع')
                    ->sortable(),

                TextColumn::make('transaction.partner.name')
                    ->label('الجهة / المستفيد')
                    ->sortable(),

                // --- Detailed: project cost amount + reserved (budget) amount ---
                TextColumn::make('projectCostBudget.projectCost.amount')
                    ->label('تكلفة المشروع')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // عملة تكلفة المشروع (hidden by default)
                TextColumn::make('projectCostBudget.projectCost.currency.name')
                    ->label('عملة تكلفة المشروع')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('projectCostBudget.final_amount')
                    ->label('المبلغ المرصود')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // عملة المبلغ المرصود: denormalized disbursement currency of the budget (hidden by default)
                TextColumn::make('projectCostBudget.disbursementCurrency.name')
                    ->label('عملة المبلغ المرصود')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('amount')
                    ->label('مبلغ التنفيذ')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                // عملة مبلغ التنفيذ: always visible (denormalized execution payment currency)
                TextColumn::make('currency.name')
                    ->label('عملة مبلغ التنفيذ'),

                // --- Detailed: debit (beneficiary) + credit accounts ---
                TextColumn::make('beneficiary_account')
                    ->label('الحساب المدين / المستفيد')
                    ->state(fn ($record) => self::accountLabel(self::line($record, ProjectCostBudgetsPayment::LINE_BENEFICIARY)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('credit_account')
                    ->label('الحساب الدائن')
                    ->state(fn ($record) => self::accountLabel(self::line($record, ProjectCostBudgetsPayment::LINE_CREDIT)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

                // --- Detailed: transaction type + fiscal year ---
                TextColumn::make('transaction.transactionType.name')
                    ->label('نوع المعاملة')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transaction.fiscalYear.name')
                    ->label('السنة المالية')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('project_super')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('projectCostBudget.projectCost.project', fn ($q) => $q->where('project_super_id', $data['value']))
                        : $query),

                SelectFilter::make('project')
                    ->label('المشروع')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('projectCostBudget.projectCost', fn ($q) => $q->where('project_id', $data['value']))
                        : $query),

                SelectFilter::make('partner')
                    ->label('الجهة / المستفيد')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('transaction', fn ($q) => $q->where('partner_id', $data['value']))
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
                        DatePicker::make('date_from')->label('من'),
                        DatePicker::make('date_until')->label('إلى'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['date_from'] ?? null, fn ($q) => $q->whereDate('date', '>=', $data['date_from']))
                        ->when($data['date_until'] ?? null, fn ($q) => $q->whereDate('date', '<=', $data['date_until']))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(fn ($record) => self::deletePayment($record))
                    ->successNotificationTitle('تم حذف مبلغ التنفيذ بنجاح'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                self::deletePayment($record);
                            }
                        })
                        ->successNotificationTitle('تم حذف عمليات التنفيذ المحددة بنجاح'),
                ]),
            ]);
    }

    /**
     * Find a loaded transaction line by its role tag (notes), using the
     * eager-loaded transaction.lines collection to avoid extra queries.
     */
    protected static function line($record, string $note): ?\App\Models\TransactionLine
    {
        return $record->transaction?->lines->firstWhere('notes', $note);
    }

    /**
     * Build a "code - name" label for an account, or null when missing.
     */
    protected static function accountLabel($account): ?string
    {
        if (! $account) {
            return null;
        }

        return trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name);
    }

    /**
     * Delete an execution payment: reverse the two account balances, then remove
     * the transaction, its lines, the attachment file/record and the payment row.
     */
    public static function deletePayment($record): void
    {
        DB::transaction(function () use ($record) {
            $transaction = $record->transaction;

            if ($transaction) {
                $lines = $transaction->lines()->with('account')->get();

                $beneficiary = $lines->firstWhere('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY);
                $credit      = $lines->firstWhere('notes', ProjectCostBudgetsPayment::LINE_CREDIT);

                // STEP 1 - Reverse the account balances
                $beneficiary?->account?->decrement('current_balance', (float) $beneficiary->debit_base);
                $credit?->account?->increment('current_balance', (float) $credit->credit_base);

                // STEP 2 - Soft delete all transaction lines
                $transaction->lines()->delete();

                // STEP 3 - Soft delete the transaction
                $transaction->delete();
            }

            // STEP 5 - Soft delete the attachment record (keep the physical file for audit)
            $attachment = $record->attachments()->first();
            if ($attachment) {
                $attachment->delete();
            }

            // STEP 4 - Soft delete the execution payment row
            $record->delete();
        });
    }
}
