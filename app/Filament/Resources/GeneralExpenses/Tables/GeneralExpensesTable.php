<?php

namespace App\Filament\Resources\GeneralExpenses\Tables;

use App\Models\FiscalYear;
use App\Models\Partner;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
use App\Services\Audit\Financial\FinancialAccountRole;
use App\Services\Audit\Financial\FinancialAuditRecorder;
use App\Services\Audit\Financial\FinancialAuditSubject;
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

class GeneralExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->searchable()
                    ->sortable(),

                // المصروفات العامة الجديدة بلا جهة مستفيدة (NULL). العمود يبقى
                // للسجلات التاريخية فقط، ومخفي افتراضياً حتى لا يزدحم الجدول.
                TextColumn::make('partner.name')
                    ->label('الجهة / المستفيد')
                    ->state(fn ($record) => $record->partner?->name ?? $record->transaction?->partner?->name)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                // العملة: always visible (denormalized expense currency; both lines share it)
                TextColumn::make('currency.name')
                    ->label('العملة'),

                // --- Detailed columns (hidden by default, toggleable) ---
                TextColumn::make('debit_account')
                    ->label('الحساب المدين')
                    ->state(fn ($record) => self::accountLabel(self::debitLine($record)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('credit_account')
                    ->label('الحساب الدائن')
                    ->state(fn ($record) => self::accountLabel(self::creditLine($record)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

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
                SelectFilter::make('partner')
                    ->label('الجهة / المستفيد')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->where('partner_id', $data['value'])
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
                    ->action(fn ($record) => self::deleteExpense($record))
                    ->successNotificationTitle('تم حذف المصروف بنجاح'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                self::deleteExpense($record);
                            }
                        })
                        ->successNotificationTitle('تم حذف المصروفات المحددة بنجاح'),
                ]),
            ]);
    }

    /**
     * The debit / credit line of the expense, identified the same way as on delete
     * (by debit_base / credit_base). Reads the eager-loaded lines to avoid N+1.
     */
    protected static function debitLine($record): ?\App\Models\TransactionLine
    {
        return $record->transaction?->lines->first(fn ($l) => (float) $l->debit_base > 0);
    }

    protected static function creditLine($record): ?\App\Models\TransactionLine
    {
        return $record->transaction?->lines->first(fn ($l) => (float) $l->credit_base > 0);
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
     * Delete a general expense: reverse the two account balances, then remove the
     * transaction, its lines, the attachment file/record and the expense row.
     */
    public static function deleteExpense($record): void
    {
        DB::transaction(function () use ($record) {
            $transaction = $record->transaction;
            $debit       = null;
            $credit      = null;

            if ($transaction) {
                $lines = $transaction->lines()->with('account')->get();

                $debit  = $lines->first(fn ($l) => (float) $l->debit_base > 0);
                $credit = $lines->first(fn ($l) => (float) $l->credit_base > 0);
            }

            // Full pre-delete snapshot, captured while the expense row, its
            // transaction (number included) and its two lines are all still
            // intact - it is the only remaining description of what was removed.
            $audit    = app(FinancialAuditRecorder::class);
            $snapshot = $audit->snapshots()->generalExpense($record, [
                FinancialAccountRole::DEBIT  => $debit?->account_id,
                FinancialAccountRole::CREDIT => $credit?->account_id,
            ]);

            if ($transaction) {
                // STEP 1 - Reverse the account balances
                $debit?->account?->decrement('current_balance', (float) $debit->debit_base);
                $credit?->account?->increment('current_balance', (float) $credit->credit_base);

                // STEP 2 - Soft delete all transaction lines
                $transaction->lines()->delete();

                // STEP 3 - Soft delete the transaction
                $transaction->delete();
            }

            // STEP 5 - Soft delete the attachment record (keep the physical file for audit)
            $attachment = $record->attachments()->first();
            if ($attachment) {
                // One attachment.deleted event, recorded BEFORE the soft delete so
                // the preserved metadata is the pre-delete metadata. Distinct from,
                // and never a duplicate of, the single financial event below.
                app(AttachmentAuditRecorder::class)->deleted($attachment);
                $attachment->delete();
            }

            // STEP 4 - Soft delete the general expense row
            $record->delete();

            // STEP 6 - One financial AuditEvent carrying the pre-delete
            // snapshot, inside this same transaction and REQUIRED.
            $audit->deleted(FinancialAuditSubject::GeneralExpense, $record, $snapshot);
        });
    }
}
