<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Filament\Concerns\GeneratesSequentialTransactionNumbers;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Concerns\ReportsFinancialValidationFailures;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
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
use Illuminate\Support\Facades\Log;

class CreateProjectCostBudgetsPayment extends CreateRecord
{
    use GeneratesSequentialTransactionNumbers;
    use RedirectsToResourceView;
    use ReportsFinancialValidationFailures;

    protected static string $resource = ProjectCostBudgetsPaymentResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withVisibleFinancialValidation(function () use ($data): Model {
            // Derive every amount on the server from the trusted inputs.
            $projectCost    = ProjectCost::find($data['project_cost_id'] ?? null);
            $projectCostId  = $projectCost?->id;
            $costCurrencyId = $projectCost?->currency_id;

            $original    = (float) $data['original_amount'];
            $adminPct    = (float) ($data['administrative_percentage'] ?? 0);
            $transferPct = (float) ($data['transfer_percentage'] ?? 0);
            $fxRate      = (float) ($data['fx_rate'] ?? 1);

            $adminAmount    = round($original * $adminPct / 100, 2);
            $transferAmount = round($original * $transferPct / 100, 2);
            $afterDeduct    = round($original - $adminAmount - $transferAmount, 2);
            $finalAmount    = round($afterDeduct * $fxRate, 2);

            FinancialAmountGuard::assertDisbursementInputs($original, $adminPct, $transferPct, $fxRate, $afterDeduct, $finalAmount);
            FinancialAmountGuard::assertDeductionsAreRecordable($adminPct, $adminAmount, $transferPct, $transferAmount);

            // A 0% deduction is a valid business case: it has no account, no
            // transaction line, no balance movement and no audit role. After
            // assertDeductionsAreRecordable() above, "amount > 0" and
            // "percentage > 0" are equivalent for both deductions, so this
            // single pair of flags drives account validation, the line
            // payload, the balance mutations and the audit role map
            // consistently - they can never disagree about which optional
            // roles exist.
            $hasAdmin    = $adminAmount > 0;
            $hasTransfer = $transferAmount > 0;

            $accountSpecs = [
                'source' => [
                    'account_id'      => $data['source_account_id'] ?? null,
                    'account_type_id' => $data['source_account_type_id'] ?? null,
                    'bank_type_id'    => $data['source_bank_type_id'] ?? null,
                    'currency_id'     => $costCurrencyId,
                    'field'           => 'source_account_id',
                    'label'           => 'حساب المصدر',
                ],
            ];

            if ($hasAdmin) {
                $accountSpecs['admin'] = [
                    'account_id'      => $data['admin_account_id'] ?? null,
                    'account_type_id' => $data['admin_account_type_id'] ?? null,
                    'bank_type_id'    => $data['admin_bank_type_id'] ?? null,
                    'currency_id'     => $costCurrencyId,
                    'field'           => 'admin_account_id',
                    'label'           => 'حساب النسبة الإدارية',
                ];
            }

            if ($hasTransfer) {
                $accountSpecs['transfer'] = [
                    'account_id'      => $data['transfer_account_id'] ?? null,
                    'account_type_id' => $data['transfer_account_type_id'] ?? null,
                    'bank_type_id'    => $data['transfer_bank_type_id'] ?? null,
                    'currency_id'     => $costCurrencyId,
                    'field'           => 'transfer_account_id',
                    'label'           => 'حساب التحويل',
                ];
            }

            $accountSpecs['destination'] = [
                'account_id'      => $data['destination_account_id'] ?? null,
                'account_type_id' => $data['destination_account_type_id'] ?? null,
                'bank_type_id'    => $data['destination_bank_type_id'] ?? null,
                'currency_id'     => $data['disbursement_currency_id'] ?? null,
                'field'           => 'destination_account_id',
                'label'           => 'حساب الوجهة',
            ];

            $accounts = FinancialAccountGuard::assertAccounts($accountSpecs);

            $lines = $this->buildLines($projectCostId, $costCurrencyId, $data, [
                'original' => $original,
                'admin'    => $adminAmount,
                'transfer' => $transferAmount,
                'final'    => $finalAmount,
                'fx'       => $fxRate,
            ]);

            FinancialTransactionBalanceGuard::assertValidLinePayload(array_values($lines));
            FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
                $lines['source'],
                $lines['admin'] ?? null,
                $lines['transfer'] ?? null,
                $lines['destination'],
                (int) $costCurrencyId,
                (int) $data['disbursement_currency_id']
            );

            return $this->retryOnTransactionNumberCollision(fn () => DB::transaction(function () use (
                $data, $projectCost, $projectCostId, $costCurrencyId,
                $original, $adminPct, $transferPct, $adminAmount, $transferAmount, $afterDeduct, $finalAmount, $fxRate,
                $accounts, $lines, $hasAdmin, $hasTransfer
            ) {
                // STEP 1 - Create transaction
                $year              = Carbon::parse($data['date'])->format('Y');
                $transactionNumber = $this->generateTransactionNumber('BUD-' . $year . '-');

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

                // STEP 2 - Insert the two-to-four validated transaction_lines
                // unchanged, exactly as built and validated above. The payload
                // is keyed by role, never by position - a deduction line is
                // ABSENT, not empty, when its percentage is 0.
                foreach ($lines as $line) {
                    TransactionLine::create($line + ['transaction_id' => $transaction->id]);
                }

                // STEP 3 - Create row in project_cost_budgets (each disbursement = 1 row)
                $budgetData = [
                    'project_cost_id'           => $projectCostId,
                    'transaction_id'            => $transaction->id,
                    'original_amount'           => $original,
                    'amount_after_deductions'   => $afterDeduct,
                    'source_currency_id'        => $costCurrencyId,
                    'disbursement_currency_id'  => $data['disbursement_currency_id'],
                    'administrative_percentage' => $adminPct,
                    'transfer_percentage'       => $transferPct,
                    'exchange_percentage'       => 0,
                    'fx_rate'                   => $fxRate,
                    'final_amount'              => $finalAmount,
                    'notes'                     => $data['notes'] ?? null,
                    'created_by'                => auth()->id(),
                    'updated_by'                => auth()->id(),
                ];

                Log::info('Disbursement STEP 3: creating project_cost_budgets row', $budgetData);

                $budget = ProjectCostBudget::create($budgetData);

                Log::info('Disbursement STEP 3: created project_cost_budgets row', ['id' => $budget->id]);

                // STEP 4 - Update account balances. Source and destination always
                // move; a deduction account moves only when that deduction
                // exists, so a 0% role never receives a no-op increment.
                $accounts['source']->decrement('current_balance', $original);

                if ($hasAdmin) {
                    $accounts['admin']->increment('current_balance', $adminAmount);
                }

                if ($hasTransfer) {
                    $accounts['transfer']->increment('current_balance', $transferAmount);
                }

                $accounts['destination']->increment('current_balance', $finalAmount);

                // STEP 4b - Generate & save the Arabic line descriptions, then the
                // parent transaction description (both from the final saved lines)
                app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                    $transaction,
                    $this->buildDisbursementLinePurposes()
                );

                app(TransactionDescriptionBuilder::class)->buildAndSave(
                    $transaction,
                    $this->buildDisbursementSummary($projectCost)
                );

                // STEP 5 - If file uploaded
                if (! empty($data['payment_image'])) {
                    $this->storeAttachment($budget, $data['payment_image'], $finalAmount);
                }

                // STEP 6 - One financial AuditEvent for this whole logical
                // disbursement (budget row + transaction + two-to-four lines +
                // the matching balance changes), inside this same transaction
                // and REQUIRED. Recorded before the notification so a rollback
                // can never be reported to the user as a success.
                $budget->setRelation('transaction', $transaction);

                $audit = app(FinancialAuditRecorder::class);

                $audit->created(
                    FinancialAuditSubject::ProjectDisbursement,
                    $budget,
                    $audit->snapshots()->projectDisbursement(
                        $budget,
                        $this->buildAuditAccountRoles($data, $hasAdmin, $hasTransfer),
                    ),
                );

                // STEP 7 - Success notification
                Notification::make()
                    ->title('تم صرف المبلغ بنجاح')
                    ->body('رقم المعاملة: ' . $transactionNumber)
                    ->success()
                    ->send();

                return $budget;
            }));
        });
    }

    /**
     * The account role => account id map handed to the audit snapshot.
     *
     * A deduction whose percentage is 0 has no account at all, so its role is
     * OMITTED rather than passed as null: the snapshot of a disbursement that
     * never had an administrative account must not carry an
     * `admin_account_id` key. On an edit from a positive percentage down to
     * 0 that omission is exactly what makes FinancialAuditDiff report the
     * account as removed, with its old label preserved on the old side.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function buildAuditAccountRoles(array $data, bool $hasAdmin, bool $hasTransfer): array
    {
        $roles = [
            FinancialAccountRole::SOURCE      => $data['source_account_id'] ?? null,
            FinancialAccountRole::DESTINATION => $data['destination_account_id'] ?? null,
        ];

        if ($hasAdmin) {
            $roles[FinancialAccountRole::ADMIN] = $data['admin_account_id'] ?? null;
        }

        if ($hasTransfer) {
            $roles[FinancialAccountRole::TRANSFER] = $data['transfer_account_id'] ?? null;
        }

        return $roles;
    }

    /**
     * "صرف مبلغ لمشروع {project} بعد الخصومات والتحويل" with a fallback when
     * the project cost's project is unavailable. Never mentions percentages.
     */
    protected function buildDisbursementSummary(?ProjectCost $projectCost): string
    {
        $projectName = $projectCost?->project?->name;

        return $projectName
            ? "صرف مبلغ لمشروع {$projectName} بعد الخصومات والتحويل"
            : 'صرف مبلغ مشروع بعد الخصومات والتحويل';
    }

    /**
     * Per-line purposes keyed by line_role (fixed wording for this flow).
     *
     * @return array<string, string>
     */
    protected function buildDisbursementLinePurposes(): array
    {
        return [
            TransactionLineRole::Source->value                  => 'إخراج مبلغ الصرف من حساب مصدر المشروع',
            TransactionLineRole::AdministrativeDeduction->value => 'إثبات الخصم الإداري على مبلغ المشروع',
            TransactionLineRole::TransferFee->value             => 'إثبات عمولة تحويل مبلغ المشروع',
            TransactionLineRole::Destination->value             => 'إثبات صافي مبلغ المشروع في حساب التنفيذ',
        ];
    }

    /**
     * Build the TransactionLine payload in memory, KEYED BY ROLE
     * ('source' / 'admin' / 'transfer' / 'destination') and without
     * transaction_id, so it can be validated by
     * FinancialTransactionBalanceGuard before DB::transaction() opens. The
     * transaction_id is merged in at insert time; nothing else is
     * recalculated. Each line is tagged via notes for later identification.
     *
     * The two deduction lines are OPTIONAL. A 0% administrative or transfer
     * percentage yields a 0.00 amount, and a line carrying
     * debit_base = credit_base = amount_currency = 0 is meaningless
     * accounting that assertValidLinePayload() rejects outright and must keep
     * rejecting. So the line is simply not built, giving 4, 3 or 2 lines:
     *
     *   admin > 0, transfer > 0  ->  source + admin + transfer + destination
     *   admin = 0, transfer > 0  ->  source + transfer + destination
     *   admin > 0, transfer = 0  ->  source + admin + destination
     *   admin = 0, transfer = 0  ->  source + destination
     *
     * Keying by role rather than by position is what makes a variable length
     * safe: every consumer asks for $lines['source'] or $lines['admin'] ?? null,
     * so a shorter payload can never silently shift the destination line into
     * the administrative slot the way $lines[1] once could.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function buildLines(?int $projectCostId, ?int $costCurrencyId, array $data, array $amounts): array
    {
        $uid = auth()->id();

        $lines = [];

        // دائن - المصدر (بعملة التكلفة) - always present
        $lines['source'] = [
            'account_id'      => $data['source_account_id'],
            'project_cost_id' => $projectCostId,
            'currency_id'     => $costCurrencyId,
            'amount_currency' => $amounts['original'],
            'fx_rate'         => 1,
            'debit_base'      => 0,
            'credit_base'     => $amounts['original'],
            'notes'           => ProjectCostBudget::LINE_SOURCE,
            'line_role'       => TransactionLineRole::Source->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ];

        // مدين - النسبة الإدارية - only when the deduction actually exists
        if ($amounts['admin'] > 0) {
            $lines['admin'] = [
                'account_id'      => $data['admin_account_id'],
                'project_cost_id' => $projectCostId,
                'currency_id'     => $costCurrencyId,
                'amount_currency' => $amounts['admin'],
                'fx_rate'         => 1,
                'debit_base'      => $amounts['admin'],
                'credit_base'     => 0,
                'notes'           => ProjectCostBudget::LINE_ADMIN,
                'line_role'       => TransactionLineRole::AdministrativeDeduction->value,
                'created_by'      => $uid,
                'updated_by'      => $uid,
            ];
        }

        // مدين - التحويل - only when the deduction actually exists
        if ($amounts['transfer'] > 0) {
            $lines['transfer'] = [
                'account_id'      => $data['transfer_account_id'],
                'project_cost_id' => $projectCostId,
                'currency_id'     => $costCurrencyId,
                'amount_currency' => $amounts['transfer'],
                'fx_rate'         => 1,
                'debit_base'      => $amounts['transfer'],
                'credit_base'     => 0,
                'notes'           => ProjectCostBudget::LINE_TRANSFER,
                'line_role'       => TransactionLineRole::TransferFee->value,
                'created_by'      => $uid,
                'updated_by'      => $uid,
            ];
        }

        // مدين - الوجهة (بعملة الصرف) - always present
        $lines['destination'] = [
            'account_id'      => $data['destination_account_id'],
            'project_cost_id' => $projectCostId,
            'currency_id'     => $data['disbursement_currency_id'],
            'amount_currency' => $amounts['final'],
            'fx_rate'         => $amounts['fx'],
            'debit_base'      => $amounts['final'],
            'credit_base'     => 0,
            'notes'           => ProjectCostBudget::LINE_DESTINATION,
            'line_role'       => TransactionLineRole::Destination->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ];

        return $lines;
    }

    protected function storeAttachment(ProjectCostBudget $budget, string $tempPath, float $amount): void
    {
        app(AttachmentUploadService::class)->store(
            parent: $budget,
            tempPath: $tempPath,
            directory: 'payments',
            prefix: 'pay',
            date: $budget->transaction?->transaction_time ?? now(),
            amount: $amount,
        );
    }
}
