<?php

namespace App\Filament\Resources\GeneralExpenses\Pages;

use App\Filament\Concerns\GeneratesSequentialTransactionNumbers;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\GeneralExpenses\GeneralExpenseResource;
use App\Filament\Resources\GeneralExpenses\Schemas\GeneralExpenseForm;
use App\Filament\Resources\GeneralExpenses\Tables\GeneralExpensesTable;
use App\Models\Attachment;
use App\Models\GeneralExpense;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Attachments\AttachmentUploadService;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
use App\Services\Audit\Financial\FinancialAccountRole;
use App\Services\Audit\Financial\FinancialAuditRecorder;
use App\Services\Audit\Financial\FinancialAuditSubject;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjector;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use App\Services\Validation\FinancialAccountGuard;
use App\Services\Validation\FinancialAmountGuard;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditGeneralExpense extends EditRecord
{
    use GeneratesSequentialTransactionNumbers;
    use RedirectsToResourceView;

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
        // لا prefill للجهة / المستفيد: الحقل أُزيل من النموذج، والقيمة التاريخية
        // تُحفظ في handleRecordUpdate() من السجل مباشرة، لا من حالة نموذج مخفية.
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

        // Section 4 - attachment: the replacement upload field is
        // intentionally left empty - the current attachment is shown
        // separately via the secure preview component, never prefilled
        // into FileUpload with a private path.

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

        $lines = $this->buildLines($data['debit_account_id'], $data['credit_account_id'], $currencyId, $amount);

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, $currencyId);

        // Pre-change snapshot: taken after every guard has passed but before
        // the transaction opens, while the expense row, its transaction and
        // its two old lines are all still pristine. The old accounts are read
        // from the OLD lines, never from the submitted data.
        $audit = app(FinancialAuditRecorder::class);

        $before = $audit->snapshots()->generalExpense($record, [
            FinancialAccountRole::DEBIT => $oldDebit?->account_id,
            FinancialAccountRole::CREDIT => $oldCredit?->account_id,
        ]);

        // الجهة / المستفيد أُزيلت من النموذج: المصروف العام لا يتطلب جهة.
        // القيمة تُقرأ من السجل نفسه - لا من البيانات المُرسَلة - حتى لا يؤدي
        // تعديل أي حقل آخر إلى مسح جهة مستفيدة تاريخية. السجلات الجديدة تبقى NULL.
        $preservedPartnerId = $record->partner_id ?? $record->transaction?->partner_id;

        // The stored number as it is BEFORE this edit, read once: a retried
        // attempt must decide from it, never from the in-memory value a
        // failed attempt left on the model.
        $originalNumber = $record->transaction?->transaction_number;

        // Only a renumber can collide on transaction_number; the retry re-runs
        // the whole (rolled back) DB transaction with a freshly computed number.
        return $this->retryOnTransactionNumberCollision(fn () => DB::transaction(function () use ($record, $data, $amount, $currencyId, $oldDebit, $oldCredit, $accounts, $lines, $audit, $before, $preservedPartnerId, $originalNumber) {
            $transaction = $record->transaction;

            // STEP 0 - Keep GEN-YYYY- in step with the expense date's year
            // (the Create rule). The FIRST write of this transaction, so a
            // number collision rolls back before any balance or line changed.
            $renumberedTo = $transaction
                ? $this->renumberForDateYear($transaction, $originalNumber, $data['date'])
                : null;

            // STEP 1 - Reverse old balances (old debit decrement, old credit increment)
            $oldDebit?->account?->decrement('current_balance', (float) $oldDebit->debit_base);
            $oldCredit?->account?->increment('current_balance', (float) $oldCredit->credit_base);

            // STEP 2 - Update the transaction
            $transaction?->update([
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $preservedPartnerId,
                'notes'               => $data['notes'] ?? null,
                'updated_by'          => auth()->id(),
            ]);

            // STEP 3 - Replace the two transaction lines (hard delete: these are being
            // immediately recreated, so no soft-deleted duplicates should accumulate)
            // with the validated payload built above, unchanged.
            $transaction?->lines()->forceDelete();
            if ($transaction) {
                foreach ($lines as $line) {
                    TransactionLine::create($line + ['transaction_id' => $transaction->id]);
                }
            }

            // STEP 4 - Update the general expense row
            $record->update([
                'amount'      => $amount,
                'currency_id' => $currencyId,
                'date'        => Carbon::parse($data['date']),
                // نفس القيمة المحفوظة المكتوبة في المعاملة - الجدولان يبقيان متطابقين.
                'partner_id'  => $preservedPartnerId,
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

            // Report projection: lines were force-deleted and recreated above;
            // re-derive this transaction's rows of "تقرير الحركات المالية الشامل"
            // ONCE, from the final ledger state, inside this same DB transaction.
            if ($transaction) {
                app(ComprehensiveReportProjector::class)->syncTransaction($transaction->id);
            }

            // STEP 6 - Handle file replacement/removal
            $existing        = $record->attachments()->latest('id')->first();
            $newTempPath     = $data['expense_image'] ?? null;
            $removeRequested = (bool) ($data['remove_current_attachment'] ?? false);

            if ($newTempPath) {
                // Store the replacement first; only soft-delete the previous
                // active attachment once the new one has succeeded. Passing
                // $existing makes this ONE attachment.replaced event carrying
                // both files' metadata - never an uploaded plus a deleted -
                // so the soft delete below adds no second event.
                $this->storeAttachment($record, $newTempPath, $amount, $existing);
                $existing?->delete();
            } elseif ($removeRequested && $existing) {
                // Recorded BEFORE the soft delete, while the metadata being
                // preserved is still the metadata of an active attachment.
                app(AttachmentAuditRecorder::class)->deleted($existing);
                $existing->delete();
            }

            // STEP 7 - One financial AuditEvent for this whole logical edit,
            // recording only the financial/business fields that actually
            // changed, with the old and new labels of any reassigned
            // account/currency/partner preserved.
            $audit->updated(
                FinancialAuditSubject::GeneralExpense,
                $record,
                $before,
                $audit->snapshots()->generalExpense($record, [
                    FinancialAccountRole::DEBIT => $data['debit_account_id'],
                    FinancialAccountRole::CREDIT => $data['credit_account_id'],
                ]),
            );

            // STEP 8 - Success (with the new number, as Create shows it, when
            // the expense was renumbered into another year)
            Notification::make()
                ->title('تم تعديل المصروف بنجاح')
                ->body($renumberedTo !== null ? 'رقم المعاملة: ' . $renumberedTo : null)
                ->success()
                ->send();

            return $record;
        }));
    }

    /**
     * The transaction number's year follows the expense date, exactly as on
     * Create ("GEN-" + year of the date). Same year: the number is kept.
     * Another year: the next number of THAT year's GEN sequence, from the
     * shared generator (MAX+1 over every existing number incl. soft-deleted
     * ones, under lockForUpdate; the UNIQUE index + retry cover the race).
     * A number not in the GEN-YYYY-NNNN form is never touched.
     *
     * @return string|null the new number, or null when it was kept
     */
    protected function renumberForDateYear(Transaction $transaction, ?string $originalNumber, mixed $date): ?string
    {
        $year = Carbon::parse($date)->format('Y');

        if (! preg_match('/^GEN-(\d{4})-\d+$/', (string) $originalNumber, $match) || $match[1] === $year) {
            return null;
        }

        $number = $this->generateTransactionNumber('GEN-' . $year . '-');

        $transaction->update(['transaction_number' => $number]);

        return $number;
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

    protected function storeAttachment(GeneralExpense $expense, string $tempPath, float $amount, ?Attachment $replacing = null): void
    {
        app(AttachmentUploadService::class)->store(
            parent: $expense,
            tempPath: $tempPath,
            directory: 'general-expenses',
            prefix: 'gen',
            date: $expense->date ?? now(),
            amount: $amount,
            replacing: $replacing,
        );
    }
}
