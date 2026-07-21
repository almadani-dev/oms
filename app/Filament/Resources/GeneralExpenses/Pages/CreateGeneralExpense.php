<?php

namespace App\Filament\Resources\GeneralExpenses\Pages;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\GeneralExpenses\GeneralExpenseResource;
use App\Models\GeneralExpense;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Attachments\AttachmentUploadService;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use App\Services\Validation\FinancialAccountGuard;
use App\Services\Validation\FinancialAmountGuard;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateGeneralExpense extends CreateRecord
{
    protected static string $resource = GeneralExpenseResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $amount     = (float) $data['amount'];
        $currencyId = (int) $data['currency_id'];

        FinancialAmountGuard::assertSimpleAmount($amount, 'amount', 'مبلغ المصروف');

        $accounts = FinancialAccountGuard::assertAccounts([
            'debit' => [
                'account_id'      => $data['debit_account_id'] ?? null,
                'account_type_id' => $data['debit_account_type_id'] ?? null,
                'bank_type_id'    => $data['debit_bank_type_id'] ?? null,
                'currency_id'     => $currencyId,
                'field'           => 'debit_account_id',
                'label'           => 'الحساب المدين',
            ],
            'credit' => [
                'account_id'      => $data['credit_account_id'] ?? null,
                'account_type_id' => $data['credit_account_type_id'] ?? null,
                'bank_type_id'    => $data['credit_bank_type_id'] ?? null,
                'currency_id'     => $currencyId,
                'field'           => 'credit_account_id',
                'label'           => 'الحساب الدائن',
            ],
        ]);

        $lines = $this->buildLines($data['debit_account_id'], $data['credit_account_id'], $currencyId, $amount);

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, $currencyId);

        return DB::transaction(function () use ($data, $amount, $currencyId, $accounts, $lines) {
            // STEP 1 - Create the transaction (GEN-YYYY-XXXX)
            $year              = Carbon::parse($data['date'])->format('Y');
            $transactionNumber = $this->generateTransactionNumber('GEN-' . $year . '-');

            $transaction = Transaction::create([
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_number'  => $transactionNumber,
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'],
                'notes'               => $data['notes'] ?? null,
                'created_by'          => auth()->id(),
                'updated_by'          => auth()->id(),
            ]);

            // STEP 2 - Insert the two validated transaction lines unchanged,
            // exactly as built and validated above.
            foreach ($lines as $line) {
                TransactionLine::create($line + ['transaction_id' => $transaction->id]);
            }

            // STEP 3 - Create the general expense row
            $expense = GeneralExpense::create([
                'transaction_id' => $transaction->id,
                'amount'         => $amount,
                'currency_id'    => $currencyId,
                'date'           => Carbon::parse($data['date']),
                'partner_id'     => $data['partner_id'],
                'description'    => $data['description'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => auth()->id(),
                'updated_by'     => auth()->id(),
            ]);

            // STEP 4 - Update account balances
            $accounts['debit']->increment('current_balance', $amount);  // مدين
            $accounts['credit']->decrement('current_balance', $amount);  // دائن

            // STEP 4b - Generate & save the Arabic line descriptions, then the
            // parent transaction description (both from the final saved lines)
            app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                $transaction,
                $this->buildGeneralExpenseLinePurposes($expense)
            );

            app(TransactionDescriptionBuilder::class)->buildAndSave(
                $transaction,
                $this->buildGeneralExpenseSummary($expense)
            );

            // STEP 5 - Store the attachment if provided
            if (! empty($data['expense_image'])) {
                $this->storeAttachment($expense, $data['expense_image'], $amount);
            }

            // STEP 6 - Success notification
            Notification::make()
                ->title('تم تسجيل المصروف بنجاح')
                ->body('رقم المعاملة: ' . $transactionNumber)
                ->success()
                ->send();

            return $expense;
        });
    }

    /**
     * Build the next transaction number for the given prefix (e.g. "GEN-2026-").
     *
     * Uses the real MAX of the existing numeric suffixes - including soft-deleted
     * rows - instead of a row count, so deletions can never cause a duplicate.
     */
    protected function generateTransactionNumber(string $prefix): string
    {
        $numbers = Transaction::withTrashed()
            ->where('transaction_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->pluck('transaction_number');

        $max = 0;
        foreach ($numbers as $number) {
            $suffix = (int) substr((string) $number, strrpos((string) $number, '-') + 1);
            $max    = max($max, $suffix);
        }

        return $prefix . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
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

    /**
     * Build the exact two-line TransactionLine payload (debit expense +
     * credit source) in memory, without transaction_id, so it can be
     * validated by FinancialTransactionBalanceGuard before DB::transaction()
     * opens. The transaction_id is merged in at insert time; nothing else is
     * recalculated.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildLines($debitAccountId, $creditAccountId, int $currencyId, float $amount): array
    {
        $uid = auth()->id();

        return [
            [
                'account_id'      => $debitAccountId,
                'currency_id'     => $currencyId,
                'amount_currency' => $amount,
                'fx_rate'         => 1,
                'debit_base'      => $amount,
                'credit_base'     => 0,
                'line_role'       => TransactionLineRole::Expense->value,
                'created_by'      => $uid,
                'updated_by'      => $uid,
            ],
            [
                'account_id'      => $creditAccountId,
                'currency_id'     => $currencyId,
                'amount_currency' => $amount,
                'fx_rate'         => 1,
                'debit_base'      => 0,
                'credit_base'     => $amount,
                'line_role'       => TransactionLineRole::Source->value,
                'created_by'      => $uid,
                'updated_by'      => $uid,
            ],
        ];
    }

    protected function storeAttachment(GeneralExpense $expense, string $tempPath, float $amount): void
    {
        app(AttachmentUploadService::class)->store(
            parent: $expense,
            tempPath: $tempPath,
            directory: 'general-expenses',
            prefix: 'gen',
            date: $expense->date ?? now(),
            amount: $amount,
        );
    }
}
