<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
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
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditProjectCostReceipt extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = ProjectCostReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(fn ($record) => ProjectCostReceiptsTable::deleteReceipt($record))
                ->successNotificationTitle('تم حذف الاستلام بنجاح')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['project_id']                = $record->projectCost?->project_id;
        $data['project_super_id']          = $record->projectCost?->project?->project_super_id;
        $data['cost_currency']             = $record->projectCost?->currency?->name;
        $data['transaction_super_type_id'] = $record->transaction?->transactionType?->transaction_super_type_id;
        $data['transaction_type_id']       = $record->transaction?->transaction_type_id;
        $data['fiscal_year_id']            = $record->transaction?->fiscal_year_id;
        $data['partner_id']                = $record->transaction?->partner_id;

        $debitLine  = $record->transaction?->lines()->with('account')->where('debit_base', '>', 0)->first();
        $creditLine = $record->transaction?->lines()->with('account')->where('credit_base', '>', 0)->first();

        $data['debit_account_type_id'] = $debitLine?->account?->account_type_id;
        $data['debit_bank_type_id']    = $debitLine?->account?->bank_type_id;
        $data['debit_account_id']      = $debitLine?->account_id;

        $data['credit_account_type_id'] = $creditLine?->account?->account_type_id;
        $data['credit_bank_type_id']    = $creditLine?->account?->bank_type_id;
        $data['credit_account_id']      = $creditLine?->account_id;

        // The replacement upload field is intentionally left empty - the
        // current attachment is shown separately via the secure preview
        // component, never prefilled into FileUpload with a private path.

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        FinancialAmountGuard::assertSimpleAmount((float) $data['amount'], 'amount', 'مبلغ الاستلام');

        // Old lines fetched before the account guard so an unchanged historical
        // account may remain inactive; see FinancialAccountGuard::requireActiveOnChange().
        $oldDebitLine  = $record->transaction?->lines()->with('account')->where('debit_base', '>', 0)->first();
        $oldCreditLine = $record->transaction?->lines()->with('account')->where('credit_base', '>', 0)->first();

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
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldDebitLine?->account_id, $data['debit_account_id'] ?? null),
            ],
            'credit' => [
                'account_id'      => $data['credit_account_id'] ?? null,
                'account_type_id' => $data['credit_account_type_id'] ?? null,
                'bank_type_id'    => $data['credit_bank_type_id'] ?? null,
                'currency_id'     => $costCurrencyId,
                'field'           => 'credit_account_id',
                'label'           => 'الحساب الدائن',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldCreditLine?->account_id, $data['credit_account_id'] ?? null),
            ],
        ]);

        $lineUpdates = $this->buildReceiptLineUpdates($data, $projectCost);

        FinancialTransactionBalanceGuard::assertValidLinePayload(array_values($lineUpdates));
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines(array_values($lineUpdates), (int) $costCurrencyId);

        // Pre-change snapshot: taken after every guard has passed but before
        // the transaction opens, while the record, its transaction and its
        // old lines are all still pristine. The old accounts are read from
        // the OLD lines, never from the submitted data.
        $audit = app(FinancialAuditRecorder::class);

        $before = $audit->snapshots()->projectCostReceipt($record, [
            FinancialAccountRole::DEBIT => $oldDebitLine?->account_id,
            FinancialAccountRole::CREDIT => $oldCreditLine?->account_id,
        ]);

        return DB::transaction(function () use ($record, $data, $oldDebitLine, $oldCreditLine, $projectCost, $accounts, $lineUpdates, $audit, $before) {
            $oldAmount = $record->amount;

            // STEP 1 - Reverse old balances
            $oldDebitLine?->account?->decrement('current_balance', $oldAmount);
            $oldCreditLine?->account?->increment('current_balance', $oldAmount);

            // STEP 2 - Update transaction
            $record->transaction?->update([
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'],
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'notes'               => $data['notes'] ?? null,
                'updated_by'          => auth()->id(),
            ]);

            // STEP 3 - Apply the validated debit/credit transaction_line updates
            // unchanged, exactly as built and validated above. (line_role is set
            // explicitly so receipts created before the line_role feature self-heal.)
            $oldDebitLine?->update($lineUpdates['debit']);
            $oldCreditLine?->update($lineUpdates['credit']);

            // STEP 5 - Update project_cost_receipts
            $record->update([
                'project_cost_id' => $data['project_cost_id'],
                'amount'          => $data['amount'],
                'currency_id'     => $projectCost?->currency_id,
                'date'            => $data['date'],
                'notes'           => $data['notes'] ?? null,
                'updated_by'      => auth()->id(),
            ]);

            // STEP 6 - Apply new balances
            $accounts['debit']->increment('current_balance', $data['amount']);
            $accounts['credit']->decrement('current_balance', $data['amount']);

            // STEP 6b - Regenerate the Arabic line descriptions and the parent
            // transaction description from the final saved state
            if ($record->transaction) {
                app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                    $record->transaction,
                    $this->buildReceiptLinePurposes($projectCost)
                );

                app(TransactionDescriptionBuilder::class)->buildAndSave(
                    $record->transaction,
                    $this->buildReceiptSummary($record->transaction, $projectCost)
                );
            }

            // STEP 7 - Handle file upload/replacement/removal
            $existingAttachment = $record->attachments()->latest('id')->first();
            $newTempPath        = $data['receipt_image'] ?? null;
            $removeRequested    = (bool) ($data['remove_current_attachment'] ?? false);

            if ($newTempPath) {
                // Store the replacement first; only soft-delete the previous
                // active attachment once the new one has succeeded. If
                // store() throws, the whole DB::transaction() rolls back and
                // the previous attachment is left untouched.
                app(AttachmentUploadService::class)->store(
                    parent: $record,
                    tempPath: $newTempPath,
                    directory: 'receipts',
                    prefix: 'receive',
                    date: $record->date,
                    amount: (float) $record->amount,
                );

                $existingAttachment?->delete();
            } elseif ($removeRequested && $existingAttachment) {
                $existingAttachment->delete();
            }

            // STEP 8 - One financial AuditEvent for this whole logical edit,
            // recording only the financial/business fields that actually
            // changed (old and new account/project/currency labels included
            // for any reassigned foreign key). A save that changed nothing
            // writes no event.
            $audit->updated(
                FinancialAuditSubject::ProjectCostReceipt,
                $record,
                $before,
                $audit->snapshots()->projectCostReceipt($record, [
                    FinancialAccountRole::DEBIT => $data['debit_account_id'],
                    FinancialAccountRole::CREDIT => $data['credit_account_id'],
                ]),
            );

            // STEP 9 - Success notification
            Notification::make()
                ->title('تم تعديل الاستلام بنجاح')
                ->success()
                ->send();

            return $record;
        });
    }

    /**
     * Build the exact debit/credit ->update() payloads in memory so they can
     * be validated by FinancialTransactionBalanceGuard before DB::transaction()
     * opens and before any old balance is reversed. Applied unchanged, with
     * no recalculation, once validation succeeds.
     *
     * @return array{debit: array<string, mixed>, credit: array<string, mixed>}
     */
    protected function buildReceiptLineUpdates(array $data, ?ProjectCost $projectCost): array
    {
        $currencyId = $projectCost?->currency_id;

        return [
            'debit' => [
                'account_id'      => $data['debit_account_id'],
                'currency_id'     => $currencyId,
                'amount_currency' => $data['amount'],
                'fx_rate'         => 1,
                'debit_base'      => $data['amount'],
                'credit_base'     => 0,
                'line_role'       => TransactionLineRole::ReceiptDestination->value,
                'updated_by'      => auth()->id(),
            ],
            'credit' => [
                'account_id'      => $data['credit_account_id'],
                'currency_id'     => $currencyId,
                'amount_currency' => $data['amount'],
                'fx_rate'         => 1,
                'debit_base'      => 0,
                'credit_base'     => $data['amount'],
                'line_role'       => TransactionLineRole::FundingSource->value,
                'updated_by'      => auth()->id(),
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
