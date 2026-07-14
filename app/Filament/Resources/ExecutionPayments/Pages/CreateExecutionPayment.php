<?php

namespace App\Filament\Resources\ExecutionPayments\Pages;

use App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource;
use App\Filament\Resources\ExecutionPayments\Schemas\ExecutionPaymentForm;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Transactions\TransactionDescriptionBuilder;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateExecutionPayment extends CreateRecord
{
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

        // STEP 1 - Determine the credit account (the disbursement destination).
        $destinationLine = ExecutionPaymentForm::budgetDestinationLine($budget?->id);
        $creditAccountId = $destinationLine?->account_id;

        // Non-blocking warning if the execution amount exceeds the budget remaining.
        $remaining = ExecutionPaymentForm::budgetRemaining($budget?->id);
        if ($amount > $remaining) {
            Notification::make()
                ->title('تحذير: المبلغ يتجاوز المتبقي من المرصود بـ ' . number_format($amount - $remaining, 2))
                ->warning()
                ->persistent()
                ->send();
        }

        return DB::transaction(function () use ($data, $budget, $amount, $currencyId, $creditAccountId) {
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

            // STEP 3 - Create the two transaction lines
            $this->createLines($transaction->id, $data['beneficiary_account_id'], $creditAccountId, $currencyId, $amount);

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

            // STEP 5b - Generate & save the Arabic transaction description
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
        });
    }

    /**
     * Build the next transaction number for the given prefix (e.g. "PAY-2026-").
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
     * Line 1: مدين - المستفيد. Line 2: دائن - الوجهة التلقائية.
     */
    protected function createLines(int $transactionId, $beneficiaryAccountId, $creditAccountId, ?int $currencyId, float $amount): void
    {
        $uid = auth()->id();

        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $beneficiaryAccountId,
            'currency_id'     => $currencyId,
            'amount_currency' => $amount,
            'fx_rate'         => 1,
            'debit_base'      => $amount,
            'credit_base'     => 0,
            'notes'           => ProjectCostBudgetsPayment::LINE_BENEFICIARY,
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
            'notes'           => ProjectCostBudgetsPayment::LINE_CREDIT,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);
    }

    protected function storeAttachment(ProjectCostBudgetsPayment $payment, string $tempPath, float $amount): void
    {
        $ext      = pathinfo($tempPath, PATHINFO_EXTENSION);
        $mimeType = Storage::disk('public')->mimeType($tempPath);
        $fileSize = Storage::disk('public')->size($tempPath);

        $attachment = Attachment::create([
            'attachable_type' => ProjectCostBudgetsPayment::class,
            'attachable_id'   => $payment->id,
            'file_name'       => basename($tempPath),
            'file_path'       => $tempPath,
            'file_type'       => $mimeType,
            'file_size'       => $fileSize,
            'created_by'      => auth()->id(),
            'updated_by'      => auth()->id(),
        ]);

        $newName = 'pay_' . $attachment->id
            . '_' . Carbon::parse($payment->date ?? now())->format('Ymd')
            . '_' . (int) $amount
            . '.' . $ext;

        $newPath = 'execution-payments/' . $newName;
        Storage::disk('public')->move($tempPath, $newPath);

        $attachment->update([
            'file_name' => $newName,
            'file_path' => $newPath,
        ]);
    }
}
