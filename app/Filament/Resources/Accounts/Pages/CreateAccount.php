<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Concerns\GeneratesSequentialTransactionNumbers;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAccount extends CreateRecord
{
    use GeneratesSequentialTransactionNumbers;
    use RedirectsToResourceView;

    protected static string $resource = AccountResource::class;

    public const OPENING_CLEARING_ACCOUNT_NAME = 'أرصدة افتتاحية';

    public const OPENING_TRANSACTION_TYPE_NAME = 'قيد افتتاحي';

    public const OPENING_TRANSACTION_SUPER_TYPE_NAME = 'قيود افتتاحية';

    protected function handleRecordCreation(array $data): Model
    {
        $openingBalance = (float) ($data['opening_balance'] ?? 0);
        $openingDate    = $data['opening_balance_date'] ?? now()->toDateString();
        $openingFxRate  = (float) ($data['opening_balance_fx_rate'] ?? 1) ?: 1;

        // ليست أعمدة على جدول الحسابات
        unset($data['opening_balance'], $data['opening_balance_date'], $data['opening_balance_fx_rate']);

        if ($openingBalance <= 0) {
            return static::getModel()::create($data);
        }

        return $this->retryOnTransactionNumberCollision(fn () => DB::transaction(function () use ($data, $openingBalance, $openingDate, $openingFxRate) {
            // STEP 1 - Create the account (current_balance starts at 0)
            $account = Account::create($data);

            // STEP 2 - Resolve the fiscal year from the opening date
            $fiscalYear = FiscalYear::whereDate('start_date', '<=', $openingDate)
                ->whereDate('end_date', '>=', $openingDate)
                ->first()
                ?? FiscalYear::where('is_active', true)->first();

            if (! $fiscalYear) {
                throw ValidationException::withMessages([
                    'data.opening_balance_date' => 'لا توجد سنة مالية تغطي تاريخ الرصيد الافتتاحي.',
                ]);
            }

            // STEP 3 - Resolve lookups + clearing account, then create the opening entry
            $transactionTypeId = $this->resolveOpeningTransactionTypeId();
            $clearingAccount   = $this->resolveOpeningClearingAccount($account);

            $year              = Carbon::parse($openingDate)->format('Y');
            $transactionNumber = $this->generateTransactionNumber('OPB-' . $year . '-');

            $transaction = Transaction::create([
                'fiscal_year_id'      => $fiscalYear->id,
                'transaction_type_id' => $transactionTypeId,
                'transaction_number'  => $transactionNumber,
                'transaction_time'    => Carbon::parse($openingDate),
                'notes'               => 'تم إنشاء هذا القيد تلقائياً عند إنشاء الحساب.',
                'created_by'          => auth()->id(),
                'updated_by'          => auth()->id(),
            ]);

            // STEP 4 - Two balanced lines, no project relation (project_cost_id stays null)
            $this->createOpeningLines($transaction->id, $account, $clearingAccount, $openingBalance, $openingFxRate);

            // STEP 5 - Update balances with the standard debit/credit pattern
            $account->increment('current_balance', $openingBalance);          // مدين
            $clearingAccount->decrement('current_balance', $openingBalance);  // دائن

            // STEP 6 - Generate & save the Arabic line descriptions, then the
            // parent transaction description (both from the final saved lines)
            app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction($transaction, [
                TransactionLineRole::OpeningBalanceTarget->value      => 'إثبات الرصيد الافتتاحي للحساب',
                TransactionLineRole::OpeningBalanceCounterpart->value => 'الطرف المقابل للقيد الافتتاحي',
            ]);

            app(TransactionDescriptionBuilder::class)->buildAndSave(
                $transaction,
                "تسجيل الرصيد الافتتاحي لحساب {$account->name}"
            );

            Notification::make()
                ->title('تم إنشاء القيد الافتتاحي')
                ->body('رقم المعاملة: ' . $transactionNumber)
                ->success()
                ->send();

            return $account;
        }));
    }

    /**
     * "قيد افتتاحي" under the "قيود افتتاحية" super type; both are revived
     * if soft-deleted so numbering/lookups never split across duplicates.
     */
    protected function resolveOpeningTransactionTypeId(): int
    {
        $superType = TransactionSuperType::withTrashed()
            ->where('name', self::OPENING_TRANSACTION_SUPER_TYPE_NAME)
            ->first();

        if ($superType?->trashed()) {
            $superType->restore();
        }

        $superType ??= TransactionSuperType::create([
            'name'       => self::OPENING_TRANSACTION_SUPER_TYPE_NAME,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $type = TransactionType::withTrashed()
            ->where('name', self::OPENING_TRANSACTION_TYPE_NAME)
            ->first();

        if ($type?->trashed()) {
            $type->restore();
        }

        $type ??= TransactionType::create([
            'transaction_super_type_id' => $superType->id,
            'name'                      => self::OPENING_TRANSACTION_TYPE_NAME,
            'created_by'                => auth()->id(),
            'updated_by'                => auth()->id(),
        ]);

        return $type->id;
    }

    /**
     * One clearing account per currency, same currency as the target account.
     */
    protected function resolveOpeningClearingAccount(Account $target): Account
    {
        $clearing = Account::withTrashed()
            ->where('name', self::OPENING_CLEARING_ACCOUNT_NAME)
            ->where('currency_id', $target->currency_id)
            ->first();

        if ($clearing?->trashed()) {
            $clearing->restore();
        }

        if ($clearing) {
            return $clearing;
        }

        $accountType = AccountType::withTrashed()
            ->where('name', self::OPENING_CLEARING_ACCOUNT_NAME)
            ->first();

        if ($accountType?->trashed()) {
            $accountType->restore();
        }

        $accountType ??= AccountType::create([
            'name'  => self::OPENING_CLEARING_ACCOUNT_NAME,
            'notes' => 'حسابات مقابلة للقيود الافتتاحية (أُنشئ تلقائياً)',
        ]);

        $code = 'OPB-' . strtoupper((string) $target->currency?->code);
        if (Account::withTrashed()->where('account_code', $code)->exists()) {
            $code .= '-' . $target->currency_id;
        }

        return Account::create([
            'account_code'    => $code,
            'name'            => self::OPENING_CLEARING_ACCOUNT_NAME,
            'account_type_id' => $accountType->id,
            'bank_type_id'    => $target->bank_type_id,
            'currency_id'     => $target->currency_id,
            'is_active'       => true,
            'notes'           => 'حساب مقابل للقيود الافتتاحية (أُنشئ تلقائياً)',
        ]);
    }

    /**
     * Line 1: مدين (target). Line 2: دائن (clearing). Same currency on both
     * sides; debit_base/credit_base equal amount_currency per the system-wide
     * convention (fx_rate is stored as metadata only, never multiplied in).
     */
    protected function createOpeningLines(int $transactionId, Account $target, Account $clearing, float $amount, float $fxRate): void
    {
        $uid = auth()->id();

        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $target->id,
            'currency_id'     => $target->currency_id,
            'amount_currency' => $amount,
            'fx_rate'         => $fxRate,
            'debit_base'      => $amount,
            'credit_base'     => 0,
            'notes'           => 'قيد افتتاحي',
            'line_role'       => TransactionLineRole::OpeningBalanceTarget->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);

        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $clearing->id,
            'currency_id'     => $target->currency_id,
            'amount_currency' => $amount,
            'fx_rate'         => $fxRate,
            'debit_base'      => 0,
            'credit_base'     => $amount,
            'notes'           => 'قيد افتتاحي',
            'line_role'       => TransactionLineRole::OpeningBalanceCounterpart->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);
    }

    /**
     * Build the next transaction number for the given prefix (e.g. "OPB-2026-").
     *
     * Uses the real MAX of the existing numeric suffixes - including soft-deleted
     * rows - instead of a row count, so deletions can never cause a duplicate.
     */
}
