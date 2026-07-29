<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Concerns\GeneratesSequentialTransactionNumbers;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
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
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateProjectCostReceipt extends CreateRecord
{
    use GeneratesSequentialTransactionNumbers;
    use RedirectsToResourceView;

    protected static string $resource = ProjectCostReceiptResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        FinancialAmountGuard::assertSimpleAmount((float) $data['amount'], 'amount', 'مبلغ الاستلام');

        $projectCost    = ProjectCost::find($data['project_cost_id']);
        $costCurrencyId = $projectCost?->currency_id;

        $accounts = FinancialAccountGuard::assertAccounts([
            'debit' => [
                'account_id'      => $data['debit_account_id'] ?? null,
                'account_type_id' => $data['debit_account_type_id'] ?? null,
                'bank_type_id'    => $data['debit_bank_type_id'] ?? null,
                'currency_id'     => $costCurrencyId,
                'field'           => 'debit_account_id',
                'label'           => 'الحساب المدين',
            ],
            'credit' => [
                'account_id'      => $data['credit_account_id'] ?? null,
                'account_type_id' => $data['credit_account_type_id'] ?? null,
                'bank_type_id'    => $data['credit_bank_type_id'] ?? null,
                'currency_id'     => $costCurrencyId,
                'field'           => 'credit_account_id',
                'label'           => 'الحساب الدائن',
            ],
        ]);

        $lines = $this->buildReceiptLines($data, $projectCost);

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, (int) $costCurrencyId);

        return $this->retryOnTransactionNumberCollision(fn () => DB::transaction(function () use ($data, $projectCost, $accounts, $lines) {
            // STEP 1 - Create transaction
            $year              = Carbon::parse($data['date'])->format('Y');
            $transactionNumber = $this->generateTransactionNumber('REC-' . $year . '-');

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

            // STEP 2 - Insert the validated debit/credit transaction_lines
            // unchanged, exactly as built and validated above.
            foreach ($lines as $line) {
                TransactionLine::create($line + ['transaction_id' => $transaction->id]);
            }

            // STEP 4 - Create project_cost_receipts
            $receipt = ProjectCostReceipt::create([
                'project_cost_id' => $data['project_cost_id'],
                'transaction_id'  => $transaction->id,
                'amount'          => $data['amount'],
                'currency_id'     => $projectCost?->currency_id,
                'date'            => $data['date'],
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

            // STEP 5 - Update account balances
            $accounts['debit']->increment('current_balance', $data['amount']);
            $accounts['credit']->decrement('current_balance', $data['amount']);

            // STEP 5b - Generate & save the Arabic line descriptions, then the
            // parent transaction description (both from the final saved lines)
            app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                $transaction,
                $this->buildReceiptLinePurposes($projectCost)
            );

            app(TransactionDescriptionBuilder::class)->buildAndSave(
                $transaction,
                $this->buildReceiptSummary($transaction, $projectCost)
            );

            // STEP 6 - If file uploaded
            if (!empty($data['receipt_image'])) {
                app(AttachmentUploadService::class)->store(
                    parent: $receipt,
                    tempPath: $data['receipt_image'],
                    directory: 'receipts',
                    prefix: 'receive',
                    date: $receipt->date,
                    amount: (float) $receipt->amount,
                );
            }

            // STEP 7 - One financial AuditEvent for this whole logical action
            // (receipt + transaction + lines + balances), inside this same
            // transaction and REQUIRED, so a failed audit rolls all of it
            // back. Recorded before the notification so a rollback can never
            // be reported to the user as a success.
            $receipt->setRelation('transaction', $transaction);

            $audit = app(FinancialAuditRecorder::class);

            $audit->created(
                FinancialAuditSubject::ProjectCostReceipt,
                $receipt,
                $audit->snapshots()->projectCostReceipt($receipt, [
                    FinancialAccountRole::DEBIT => $data['debit_account_id'],
                    FinancialAccountRole::CREDIT => $data['credit_account_id'],
                ]),
            );

            // STEP 8 - Success notification
            Notification::make()
                ->title('تم تسجيل الاستلام بنجاح')
                ->body('رقم المعاملة: ' . $transactionNumber)
                ->success()
                ->send();

            return $receipt;
        }));
    }

    /**
     * Build the exact two-line TransactionLine payload (debit destination +
     * credit funding source) in memory, without transaction_id, so it can be
     * validated by FinancialTransactionBalanceGuard before DB::transaction()
     * opens. The transaction_id is merged in at insert time; nothing else is
     * recalculated.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildReceiptLines(array $data, ?ProjectCost $projectCost): array
    {
        $uid        = auth()->id();
        $currencyId = $projectCost?->currency_id;

        return [
            [
                'account_id'      => $data['debit_account_id'],
                'currency_id'     => $currencyId,
                'amount_currency' => $data['amount'],
                'fx_rate'         => 1,
                'debit_base'      => $data['amount'],
                'credit_base'     => 0,
                'line_role'       => TransactionLineRole::ReceiptDestination->value,
                'created_by'      => $uid,
                'updated_by'      => $uid,
            ],
            [
                'account_id'      => $data['credit_account_id'],
                'currency_id'     => $currencyId,
                'amount_currency' => $data['amount'],
                'fx_rate'         => 1,
                'debit_base'      => 0,
                'credit_base'     => $data['amount'],
                'line_role'       => TransactionLineRole::FundingSource->value,
                'created_by'      => $uid,
                'updated_by'      => $uid,
            ],
        ];
    }

    /**
     * "استلام مبلغ من {partner} لتمويل مشروع {project}" with graceful fallbacks
     * when the partner and/or project cost's project are unavailable.
     */
    protected function buildReceiptSummary(Transaction $transaction, ?ProjectCost $projectCost): string
    {
        $partnerName = $transaction->partner?->name;
        $projectName = $projectCost?->project?->name;

        return match (true) {
            $partnerName && $projectName => "استلام مبلغ من {$partnerName} لتمويل مشروع {$projectName}",
            ! $partnerName && $projectName => "استلام مبلغ لتمويل مشروع {$projectName}",
            $partnerName && ! $projectName => "استلام مبلغ من {$partnerName}",
            default => 'تسجيل مبلغ مستلم',
        };
    }

    /**
     * Per-line purposes keyed by line_role, with graceful fallbacks when the
     * project cost's project is unavailable.
     *
     * @return array<string, string>
     */
    protected function buildReceiptLinePurposes(?ProjectCost $projectCost): array
    {
        $projectName = $projectCost?->project?->name;

        return [
            TransactionLineRole::FundingSource->value => $projectName
                ? "إثبات تمويل مشروع {$projectName}"
                : 'إثبات تمويل المشروع',
            TransactionLineRole::ReceiptDestination->value => $projectName
                ? "إيداع المبلغ المستلم لمشروع {$projectName}"
                : 'إيداع المبلغ المستلم للمشروع',
        ];
    }
}
