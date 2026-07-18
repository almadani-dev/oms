<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use App\Services\Validation\FinancialAmountGuard;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateProjectCostBudgetsPayment extends CreateRecord
{
    protected static string $resource = ProjectCostBudgetsPaymentResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        // Derive every amount on the server from the trusted inputs.
        $projectCost    = ProjectCost::find($data['project_cost_id']);
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

        return DB::transaction(function () use (
            $data, $projectCost, $projectCostId, $costCurrencyId,
            $original, $adminPct, $transferPct, $adminAmount, $transferAmount, $afterDeduct, $finalAmount, $fxRate
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

            // STEP 2 - Create transaction_lines
            $this->createLines($transaction->id, $projectCostId, $costCurrencyId, $data, [
                'original' => $original,
                'admin'    => $adminAmount,
                'transfer' => $transferAmount,
                'final'    => $finalAmount,
                'fx'       => $fxRate,
            ]);

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

            // STEP 4 - Update account balances
            Account::find($data['source_account_id'])?->decrement('current_balance', $original);
            Account::find($data['admin_account_id'])?->increment('current_balance', $adminAmount);
            Account::find($data['transfer_account_id'])?->increment('current_balance', $transferAmount);
            Account::find($data['destination_account_id'])?->increment('current_balance', $finalAmount);

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

            // STEP 6 - Success notification
            Notification::make()
                ->title('تم صرف المبلغ بنجاح')
                ->body('رقم المعاملة: ' . $transactionNumber)
                ->success()
                ->send();

            return $budget;
        });
    }

    /**
     * Build the next transaction number for the given prefix (e.g. "BUD-2026-").
     *
     * Uses the real MAX of the existing numeric suffixes — including soft-deleted
     * rows — instead of a row count, so deletions can never cause a duplicate.
     * A row-level lock guards against concurrent inserts within the transaction.
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
     * Create the four disbursement lines, tagged via notes for later identification.
     */
    protected function createLines(int $transactionId, ?int $projectCostId, ?int $costCurrencyId, array $data, array $amounts): void
    {
        $uid = auth()->id();

        // Line 1 - دائن - المصدر (بعملة التكلفة)
        TransactionLine::create([
            'transaction_id'  => $transactionId,
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
        ]);

        // Line 2 - مدين - النسبة الإدارية
        TransactionLine::create([
            'transaction_id'  => $transactionId,
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
        ]);

        // Line 3 - مدين - التحويل
        TransactionLine::create([
            'transaction_id'  => $transactionId,
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
        ]);

        // Line 4 - مدين - الوجهة (بعملة الصرف)
        TransactionLine::create([
            'transaction_id'  => $transactionId,
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
        ]);
    }

    protected function storeAttachment(ProjectCostBudget $budget, string $tempPath, float $amount): void
    {
        $ext      = pathinfo($tempPath, PATHINFO_EXTENSION);
        $mimeType = Storage::disk('public')->mimeType($tempPath);
        $fileSize = Storage::disk('public')->size($tempPath);

        $attachment = Attachment::create([
            'attachable_type' => ProjectCostBudget::class,
            'attachable_id'   => $budget->id,
            'file_name'       => basename($tempPath),
            'file_path'       => $tempPath,
            'file_type'       => $mimeType,
            'file_size'       => $fileSize,
            'created_by'      => auth()->id(),
            'updated_by'      => auth()->id(),
        ]);

        $newName = 'pay_' . $attachment->id
            . '_' . Carbon::parse($budget->transaction?->transaction_time ?? now())->format('Ymd')
            . '_' . (int) $amount
            . '.' . $ext;

        $newPath = 'payments/' . $newName;
        Storage::disk('public')->move($tempPath, $newPath);

        $attachment->update([
            'file_name' => $newName,
            'file_path' => $newPath,
        ]);
    }
}
