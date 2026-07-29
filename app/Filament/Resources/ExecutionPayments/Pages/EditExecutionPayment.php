<?php

namespace App\Filament\Resources\ExecutionPayments\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource;
use App\Filament\Resources\ExecutionPayments\Schemas\ExecutionPaymentForm;
use App\Filament\Resources\ExecutionPayments\Tables\ExecutionPaymentsTable;
use App\Models\Account;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\TransactionLine;
use App\Services\Attachments\AttachmentUploadService;
use App\Services\Audit\Financial\FinancialAccountRole;
use App\Services\Audit\Financial\FinancialAuditRecorder;
use App\Services\Audit\Financial\FinancialAuditSubject;
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

class EditExecutionPayment extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = ExecutionPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(fn ($record) => ExecutionPaymentsTable::deletePayment($record))
                ->successNotificationTitle('تم حذف مبلغ التنفيذ بنجاح')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ProjectCostBudgetsPayment $record */
        $record   = $this->getRecord();
        $budgetId = $record->project_cost_budget_id;

        // Lines (identified by their role tag)
        $lines           = $record->transaction?->lines()->with('account', 'currency')->get();
        $beneficiaryLine = $lines?->firstWhere('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY);
        $creditLine      = $lines?->firstWhere('notes', ProjectCostBudgetsPayment::LINE_CREDIT);

        // Section 1 - project cascade + budget
        $budget      = ProjectCostBudget::find($budgetId);
        $projectCost = $budget?->projectCost()->with('project')->first();

        $data['project_super_id']        = $projectCost?->project?->project_super_id;
        $data['project_id']              = $projectCost?->project_id;
        $data['project_cost_id']         = $projectCost?->id;
        $data['project_cost_budget_id']  = $budgetId;
        $data['remaining_amount']        = number_format(ExecutionPaymentForm::budgetRemaining($budgetId, $record->id), 2, '.', '');
        $data['budget_currency']         = ExecutionPaymentForm::budgetCurrencyName($budgetId);

        // Section 2 - payment details
        $data['amount']                    = (float) $record->amount;
        $data['transaction_super_type_id'] = $record->transaction?->transactionType?->transaction_super_type_id;
        $data['transaction_type_id']       = $record->transaction?->transaction_type_id;
        $data['fiscal_year_id']            = $record->transaction?->fiscal_year_id;
        $data['partner_id']                = $record->transaction?->partner_id;
        $data['date']                      = $record->transaction?->transaction_time
            ? Carbon::parse($record->transaction->transaction_time)->toDateString()
            : ($record->date ? Carbon::parse($record->date)->toDateString() : null);

        // Section 3 - beneficiary account cascade (type + bank_type + currency + account)
        $data['beneficiary_account_id']      = $beneficiaryLine?->account_id;
        $data['beneficiary_account_type_id'] = $beneficiaryLine?->account?->account_type_id;
        $data['beneficiary_bank_type_id']    = $beneficiaryLine?->account?->bank_type_id;
        $data['beneficiary_currency']        = ExecutionPaymentForm::budgetCurrencyName($budgetId);

        // Historical credit account: hydrate from THIS payment's own saved line,
        // never from the budget's current destination (which may have drifted
        // since creation if the budget was edited afterwards). A genuine budget
        // change during this Edit session is handled separately by the form's
        // own project_cost_budget_id afterStateUpdated callback, not here.
        $data['credit_account_id']      = $creditLine?->account_id;
        $data['credit_account_type_id'] = $creditLine?->account?->account_type_id;
        $data['credit_bank_type_id']    = $creditLine?->account?->bank_type_id;
        $data['credit_currency']        = $creditLine?->currency?->name;

        // Section 4 - attachment: the replacement upload field is
        // intentionally left empty - the current attachment is shown
        // separately via the secure preview component, never prefilled
        // into FileUpload with a private path.

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ProjectCostBudgetsPayment $record */
        $amount    = (float) $data['amount'];
        $budget    = ProjectCostBudget::with(['transaction', 'projectCost.project'])->find($data['project_cost_budget_id']);
        $currencyId = ExecutionPaymentForm::budgetCurrencyId($budget?->id);

        FinancialAmountGuard::assertSimpleAmount($amount, 'amount', 'مبلغ التنفيذ');

        // Old lines fetched before validation so an unchanged historical account
        // may remain inactive; see FinancialAccountGuard::requireActiveOnChange().
        $lines          = $record->transaction?->lines()->with('account')->get();
        $oldBeneficiary = $lines?->firstWhere('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY);
        $oldCredit      = $lines?->firstWhere('notes', ProjectCostBudgetsPayment::LINE_CREDIT);

        // Validate the submitted credit account server-side before any mutation.
        // If the budget was genuinely changed during this Edit session, $data
        // already carries the new budget's default (applied by the form's own
        // afterStateUpdated); otherwise this is still the historical saved account.
        $creditAccount   = ExecutionPaymentForm::validateCreditAccount(
            $data,
            requireActiveCredit: FinancialAccountGuard::requireActiveOnChange($oldCredit?->account_id, $data['credit_account_id'] ?? null),
            requireActiveBeneficiary: FinancialAccountGuard::requireActiveOnChange($oldBeneficiary?->account_id, $data['beneficiary_account_id'] ?? null),
        );
        $creditAccountId = $creditAccount->id;

        // Non-blocking warning if the new amount exceeds the remaining (excluding this row).
        $remaining = ExecutionPaymentForm::budgetRemaining($budget?->id, $record->id);
        if ($amount > $remaining) {
            Notification::make()
                ->title('تحذير: المبلغ يتجاوز المتبقي من المرصود بـ ' . number_format($amount - $remaining, 2))
                ->warning()
                ->persistent()
                ->send();
        }

        $lines = $this->buildLines($data['beneficiary_account_id'], $creditAccountId, $currencyId, $amount);

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, (int) $currencyId);

        // Pre-change snapshot: taken after every guard has passed but before
        // the transaction opens, while the payment row, its transaction and
        // its two old lines are all still pristine. The old accounts are read
        // from the OLD lines, never from the submitted data.
        $audit = app(FinancialAuditRecorder::class);

        $before = $audit->snapshots()->executionPayment($record, [
            FinancialAccountRole::BENEFICIARY => $oldBeneficiary?->account_id,
            FinancialAccountRole::CREDIT => $oldCredit?->account_id,
        ]);

        return DB::transaction(function () use ($record, $data, $amount, $budget, $currencyId, $creditAccountId, $oldBeneficiary, $oldCredit, $lines, $audit, $before) {
            $transaction = $record->transaction;

            // STEP 1 - Reverse old balances
            $oldBeneficiary?->account?->decrement('current_balance', (float) $oldBeneficiary->debit_base);
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
            // with the validated payload built above, unchanged.
            $transaction?->lines()->forceDelete();
            if ($transaction) {
                foreach ($lines as $line) {
                    TransactionLine::create($line + ['transaction_id' => $transaction->id]);
                }
            }

            // STEP 4 - Update the execution payment row
            $record->update([
                'project_cost_budget_id' => $budget?->id,
                'amount'                 => $amount,
                'currency_id'            => $currencyId,
                'date'                   => Carbon::parse($data['date']),
                'notes'                  => $data['notes'] ?? null,
                'updated_by'             => auth()->id(),
            ]);

            // STEP 5 - Apply new balances
            Account::find($data['beneficiary_account_id'])?->increment('current_balance', $amount); // مدين
            if ($creditAccountId) {
                Account::find($creditAccountId)?->decrement('current_balance', $amount);            // دائن
            }

            // STEP 5b - Regenerate the Arabic line descriptions and the parent
            // transaction description from the final saved state
            if ($transaction) {
                app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                    $transaction,
                    $this->buildExecutionPaymentLinePurposes($budget)
                );

                app(TransactionDescriptionBuilder::class)->buildAndSave(
                    $transaction,
                    $this->buildExecutionPaymentSummary($budget)
                );
            }

            // STEP 6 - Handle file replacement/removal
            $existing        = $record->attachments()->latest('id')->first();
            $newTempPath     = $data['payment_image'] ?? null;
            $removeRequested = (bool) ($data['remove_current_attachment'] ?? false);

            if ($newTempPath) {
                // Store the replacement first; only soft-delete the previous
                // active attachment once the new one has succeeded.
                $this->storeAttachment($record, $newTempPath, $amount);
                $existing?->delete();
            } elseif ($removeRequested && $existing) {
                $existing->delete();
            }

            // STEP 7 - One financial AuditEvent for this whole logical edit,
            // recording only the financial/business fields that actually
            // changed, with the old and new labels of any reassigned
            // account/budget/currency preserved.
            $audit->updated(
                FinancialAuditSubject::ExecutionPayment,
                $record,
                $before,
                $audit->snapshots()->executionPayment($record, [
                    FinancialAccountRole::BENEFICIARY => $data['beneficiary_account_id'],
                    FinancialAccountRole::CREDIT => $creditAccountId,
                ]),
            );

            // STEP 8 - Success
            Notification::make()
                ->title('تم تعديل مبلغ التنفيذ بنجاح')
                ->success()
                ->send();

            return $record;
        });
    }

    /**
     * "صرف مبلغ تنفيذ ضمن مشروع {project}" with a fallback when the budget's
     * project cost/project is unavailable. Never exposes beneficiary details.
     */
    protected function buildExecutionPaymentSummary(?ProjectCostBudget $budget): string
    {
        $projectName = $budget?->projectCost?->project?->name;

        return $projectName
            ? "صرف مبلغ تنفيذ ضمن مشروع {$projectName}"
            : 'صرف مبلغ تنفيذ مشروع';
    }

    /**
     * Per-line purposes keyed by line_role, with a graceful fallback when the
     * budget's project is unavailable. Never exposes beneficiary details.
     *
     * @return array<string, string>
     */
    protected function buildExecutionPaymentLinePurposes(?ProjectCostBudget $budget): array
    {
        $projectName = $budget?->projectCost?->project?->name;

        return [
            TransactionLineRole::Beneficiary->value => $projectName
                ? "إثبات مبلغ التنفيذ ضمن مشروع {$projectName}"
                : 'إثبات مبلغ التنفيذ ضمن المشروع',
            TransactionLineRole::ExecutionSource->value => 'تخفيض رصيد مبلغ المشروع المتاح للتنفيذ',
        ];
    }

    /**
     * Build the exact two-line TransactionLine payload (debit beneficiary +
     * credit destination) in memory, without transaction_id, so it can be
     * validated by FinancialTransactionBalanceGuard before DB::transaction()
     * opens. The transaction_id is merged in at insert time; nothing else is
     * recalculated.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildLines($beneficiaryAccountId, $creditAccountId, ?int $currencyId, float $amount): array
    {
        $uid = auth()->id();

        return [
            [
                'account_id'      => $beneficiaryAccountId,
                'currency_id'     => $currencyId,
                'amount_currency' => $amount,
                'fx_rate'         => 1,
                'debit_base'      => $amount,
                'credit_base'     => 0,
                'notes'           => ProjectCostBudgetsPayment::LINE_BENEFICIARY,
                'line_role'       => TransactionLineRole::Beneficiary->value,
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
                'notes'           => ProjectCostBudgetsPayment::LINE_CREDIT,
                'line_role'       => TransactionLineRole::ExecutionSource->value,
                'created_by'      => $uid,
                'updated_by'      => $uid,
            ],
        ];
    }

    protected function storeAttachment(ProjectCostBudgetsPayment $payment, string $tempPath, float $amount): void
    {
        app(AttachmentUploadService::class)->store(
            parent: $payment,
            tempPath: $tempPath,
            directory: 'execution-payments',
            prefix: 'pay',
            date: $payment->date ?? now(),
            amount: $amount,
        );
    }
}
