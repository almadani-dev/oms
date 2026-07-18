<?php

namespace App\Filament\Resources\GeneralExpenses\Pages;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\GeneralExpenses\GeneralExpenseResource;
use App\Filament\Resources\GeneralExpenses\Schemas\GeneralExpenseForm;
use App\Filament\Resources\GeneralExpenses\Tables\GeneralExpensesTable;
use App\Models\Attachment;
use App\Models\GeneralExpense;
use App\Models\TransactionLine;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use App\Services\Validation\FinancialAccountGuard;
use App\Services\Validation\FinancialAmountGuard;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EditGeneralExpense extends EditRecord
{
    protected static string $resource = GeneralExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(fn ($record) => GeneralExpensesTable::deleteExpense($record))
                ->successNotificationTitle('تم حذف المصروف بنجاح')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var GeneralExpense $record */
        $record = $this->getRecord();

        // The two lines: debit carries debit_base > 0, credit carries credit_base > 0.
        $lines      = $record->transaction?->lines()->with('account')->get();
        $debitLine  = $lines?->first(fn ($l) => (float) $l->debit_base > 0);
        $creditLine = $lines?->first(fn ($l) => (float) $l->credit_base > 0);

        $currencyId   = $debitLine?->currency_id ?? $creditLine?->currency_id;
        $currencyName = GeneralExpenseForm::currencyName($currencyId);

        // Section 1 - expense details
        $data['amount']                    = (float) $record->amount;
        $data['currency_id']               = $currencyId;
        $data['partner_id']                = $record->partner_id ?? $record->transaction?->partner_id;
        $data['transaction_super_type_id'] = $record->transaction?->transactionType?->transaction_super_type_id;
        $data['transaction_type_id']       = $record->transaction?->transaction_type_id;
        $data['fiscal_year_id']            = $record->transaction?->fiscal_year_id;
        $data['date']                      = $record->transaction?->transaction_time
            ? Carbon::parse($record->transaction->transaction_time)->toDateString()
            : ($record->date ? Carbon::parse($record->date)->toDateString() : null);

        // Section 2 - debit account cascade (type + bank_type + currency + account)
        $data['debit_account_type_id'] = $debitLine?->account?->account_type_id;
        $data['debit_bank_type_id']    = $debitLine?->account?->bank_type_id;
        $data['debit_currency_display'] = $currencyName;
        $data['debit_account_id']      = $debitLine?->account_id;

        // Section 3 - credit account cascade (type + bank_type + currency + account)
        $data['credit_account_type_id'] = $creditLine?->account?->account_type_id;
        $data['credit_bank_type_id']    = $creditLine?->account?->bank_type_id;
        $data['credit_currency_display'] = $currencyName;
        $data['credit_account_id']      = $creditLine?->account_id;

        // Section 4 - attachment
        $data['expense_image'] = $record->attachments()->first()?->file_path;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var GeneralExpense $record */
        $amount     = (float) $data['amount'];
        $currencyId = (int) $data['currency_id'];

        FinancialAmountGuard::assertSimpleAmount($amount, 'amount', 'مبلغ المصروف');

        // Old lines fetched before the account guard so an unchanged historical
        // account may remain inactive; see FinancialAccountGuard::requireActiveOnChange().
        $lines     = $record->transaction?->lines()->with('account')->get();
        $oldDebit  = $lines?->first(fn ($l) => (float) $l->debit_base > 0);
        $oldCredit = $lines?->first(fn ($l) => (float) $l->credit_base > 0);

        $accounts = FinancialAccountGuard::assertAccounts([
            'debit' => [
                'account_id'      => $data['debit_account_id'] ?? null,
                'account_type_id' => $data['debit_account_type_id'] ?? null,
                'bank_type_id'    => $data['debit_bank_type_id'] ?? null,
                'currency_id'     => $currencyId,
                'field'           => 'debit_account_id',
                'label'           => 'الحساب المدين',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldDebit?->account_id, $data['debit_account_id'] ?? null),
            ],
            'credit' => [
                'account_id'      => $data['credit_account_id'] ?? null,
                'account_type_id' => $data['credit_account_type_id'] ?? null,
                'bank_type_id'    => $data['credit_bank_type_id'] ?? null,
                'currency_id'     => $currencyId,
                'field'           => 'credit_account_id',
                'label'           => 'الحساب الدائن',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldCredit?->account_id, $data['credit_account_id'] ?? null),
            ],
        ]);

        return DB::transaction(function () use ($record, $data, $amount, $currencyId, $oldDebit, $oldCredit, $accounts) {
            $transaction = $record->transaction;

            // STEP 1 - Reverse old balances (old debit decrement, old credit increment)
            $oldDebit?->account?->decrement('current_balance', (float) $oldDebit->debit_base);
            $oldCredit?->account?->increment('current_balance', (float) $oldCredit->credit_base);

            // STEP 2 - Update the transaction
            $transaction?->update([
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'],
                'notes'               => $data['notes'] ?? null,
                'updated_by'          => auth()->id(),
            ]);

            // STEP 3 - Replace the two transaction lines (hard delete: these are being
            // immediately recreated, so no soft-deleted duplicates should accumulate)
            $transaction?->lines()->forceDelete();
            if ($transaction) {
                $this->rebuildLines($transaction->id, $data['debit_account_id'], $data['credit_account_id'], $currencyId, $amount);
            }

            // STEP 4 - Update the general expense row
            $record->update([
                'amount'      => $amount,
                'currency_id' => $currencyId,
                'date'        => Carbon::parse($data['date']),
                'partner_id'  => $data['partner_id'],
                'description' => $data['description'] ?? null,
                'notes'       => $data['notes'] ?? null,
                'updated_by'  => auth()->id(),
            ]);

            // STEP 5 - Apply new balances
            $accounts['debit']->increment('current_balance', $amount);  // مدين
            $accounts['credit']->decrement('current_balance', $amount);  // دائن

            // STEP 5b - Regenerate the Arabic line descriptions and the parent
            // transaction description from the final saved state
            if ($transaction) {
                app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                    $transaction,
                    $this->buildGeneralExpenseLinePurposes($record)
                );

                app(TransactionDescriptionBuilder::class)->buildAndSave(
                    $transaction,
                    $this->buildGeneralExpenseSummary($record)
                );
            }

            // STEP 6 - Handle file swap
            $existing    = $record->attachments()->first();
            $newFilePath = $data['expense_image'] ?? null;
            $isNewFile   = $newFilePath && $newFilePath !== $existing?->file_path;

            if ($isNewFile) {
                if ($existing) {
                    $existing->delete();
                }
                $this->storeAttachment($record, $newFilePath, $amount);
            } elseif (! $newFilePath && $existing) {
                $existing->delete();
            }

            // STEP 7 - Success
            Notification::make()
                ->title('تم تعديل المصروف بنجاح')
                ->success()
                ->send();

            return $record;
        });
    }

    /**
     * "تسجيل مصروف عام: {purpose}" using the expense's own short description
     * field when available, falling back to a bare label otherwise.
     */
    protected function buildGeneralExpenseSummary(GeneralExpense $expense): string
    {
        $purpose = trim((string) ($expense->description ?? ''));

        return $purpose !== ''
            ? "تسجيل مصروف عام: {$purpose}"
            : 'تسجيل مصروف عام';
    }

    /**
     * Per-line purposes keyed by line_role, using the expense's own short
     * description field when available (never the large notes field).
     *
     * @return array<string, string>
     */
    protected function buildGeneralExpenseLinePurposes(GeneralExpense $expense): array
    {
        $purpose = trim((string) ($expense->description ?? ''));

        return [
            TransactionLineRole::Source->value => $purpose !== ''
                ? "دفع مصروف {$purpose}"
                : 'دفع مصروف عام',
            TransactionLineRole::Expense->value => $purpose !== ''
                ? "إثبات مصروف {$purpose}"
                : 'إثبات مصروف عام',
        ];
    }

    protected function rebuildLines(int $transactionId, $debitAccountId, $creditAccountId, int $currencyId, float $amount): void
    {
        $uid = auth()->id();

        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $debitAccountId,
            'currency_id'     => $currencyId,
            'amount_currency' => $amount,
            'fx_rate'         => 1,
            'debit_base'      => $amount,
            'credit_base'     => 0,
            'line_role'       => TransactionLineRole::Expense->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);

        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $creditAccountId,
            'currency_id'     => $currencyId,
            'amount_currency' => $amount,
            'fx_rate'         => 1,
            'debit_base'      => 0,
            'credit_base'     => $amount,
            'line_role'       => TransactionLineRole::Source->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);
    }

    protected function storeAttachment(GeneralExpense $expense, string $tempPath, float $amount): void
    {
        $ext      = pathinfo($tempPath, PATHINFO_EXTENSION);
        $mimeType = Storage::disk('public')->mimeType($tempPath);
        $fileSize = Storage::disk('public')->size($tempPath);

        $attachment = Attachment::create([
            'attachable_type' => GeneralExpense::class,
            'attachable_id'   => $expense->id,
            'file_name'       => basename($tempPath),
            'file_path'       => $tempPath,
            'file_type'       => $mimeType,
            'file_size'       => $fileSize,
            'created_by'      => auth()->id(),
            'updated_by'      => auth()->id(),
        ]);

        $newName = 'gen_' . $attachment->id
            . '_' . Carbon::parse($expense->date ?? now())->format('Ymd')
            . '_' . (int) $amount
            . '.' . $ext;

        $newPath = 'general-expenses/' . $newName;
        Storage::disk('public')->move($tempPath, $newPath);

        $attachment->update(['file_name' => $newName, 'file_path' => $newPath]);
    }
}
