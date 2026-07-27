<?php

namespace App\Filament\Resources\ExecutionPayments\Pages;

use App\Filament\Concerns\GeneratesSequentialTransactionNumbers;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource;
use App\Filament\Resources\ExecutionPayments\Schemas\ExecutionPaymentForm;
use App\Models\Account;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Attachments\AttachmentUploadService;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use App\Services\Validation\FinancialAmountGuard;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateExecutionPayment extends CreateRecord
{
    use GeneratesSequentialTransactionNumbers;
    use RedirectsToResourceView;

    protected static string $resource = ExecutionPaymentResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $budget      = ProjectCostBudget::with(['transaction', 'projectCost.project'])->find($data['project_cost_budget_id']);
        $amount      = (float) $data['amount'];
        $currencyId  = ExecutionPaymentForm::budgetCurrencyId($budget?->id);

        FinancialAmountGuard::assertSimpleAmount($amount, 'amount', 'مبلغ التنفيذ');

        // STEP 1 - Validate the submitted credit account server-side (the form's
        // Select options can be bypassed); the user-selected account - which
        // defaults to the budget's destination account but may be replaced
        // with another same-currency account - is authoritative from here on.
        $creditAccount   = ExecutionPaymentForm::validateCreditAccount($data);
        $creditAccountId = $creditAccount->id;

        // Non-blocking warning if the execution amount exceeds the budget remaining.
        $remaining = ExecutionPaymentForm::budgetRemaining($budget?->id);
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

        return $this->retryOnTransactionNumberCollision(fn () => DB::transaction(function () use ($data, $budget, $amount, $currencyId, $creditAccountId, $lines) {
            // STEP 2 - Create the transaction
            $year              = Carbon::parse($data['date'])->format('Y');
            $transactionNumber = $this->generateTransactionNumber('PAY-' . $year . '-');

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

            // STEP 3 - Insert the two validated transaction lines unchanged,
            // exactly as built and validated above.
            foreach ($lines as $line) {
                TransactionLine::create($line + ['transaction_id' => $transaction->id]);
            }

            // STEP 4 - Create the execution payment row
            $payment = ProjectCostBudgetsPayment::create([
                'project_cost_budget_id' => $budget?->id,
                'transaction_id'         => $transaction->id,
                'amount'                 => $amount,
                'currency_id'            => $currencyId,
                'date'                   => Carbon::parse($data['date']),
                'notes'                  => $data['notes'] ?? null,
                'created_by'             => auth()->id(),
                'updated_by'             => auth()->id(),
            ]);

            // STEP 5 - Update account balances
            Account::find($data['beneficiary_account_id'])?->increment('current_balance', $amount); // مدين
            if ($creditAccountId) {
                Account::find($creditAccountId)?->decrement('current_balance', $amount);            // دائن
            }

            // STEP 5b - Generate & save the Arabic line descriptions, then the
            // parent transaction description (both from the final saved lines)
            app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                $transaction,
                $this->buildExecutionPaymentLinePurposes($budget)
            );

            app(TransactionDescriptionBuilder::class)->buildAndSave(
                $transaction,
                $this->buildExecutionPaymentSummary($budget)
            );

            // STEP 6 - Store the attachment if provided
            if (! empty($data['payment_image'])) {
                $this->storeAttachment($payment, $data['payment_image'], $amount);
            }

            // STEP 7 - Success notification
            Notification::make()
                ->title('تم صرف مبلغ التنفيذ بنجاح')
                ->body('رقم المعاملة: ' . $transactionNumber)
                ->success()
                ->send();

            return $payment;
        }));
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
