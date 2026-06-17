<?php

namespace App\Filament\Resources\ExecutionPayments\Pages;

use App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource;
use App\Filament\Resources\ExecutionPayments\Schemas\ExecutionPaymentForm;
use App\Filament\Resources\ExecutionPayments\Tables\ExecutionPaymentsTable;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\TransactionLine;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EditExecutionPayment extends EditRecord
{
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
        $lines           = $record->transaction?->lines()->with('account')->get();
        $beneficiaryLine = $lines?->firstWhere('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY);

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
        $data['credit_account_display']      = ExecutionPaymentForm::creditAccountLabel($budgetId);

        // Section 4 - attachment
        $data['payment_image'] = $record->attachments()->first()?->file_path;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ProjectCostBudgetsPayment $record */
        $amount    = (float) $data['amount'];
        $budget    = ProjectCostBudget::with('transaction')->find($data['project_cost_budget_id']);
        $currencyId = ExecutionPaymentForm::budgetCurrencyId($budget?->id);

        // Non-blocking warning if the new amount exceeds the remaining (excluding this row).
        $remaining = ExecutionPaymentForm::budgetRemaining($budget?->id, $record->id);
        if ($amount > $remaining) {
            Notification::make()
                ->title('تحذير: المبلغ يتجاوز المتبقي من المرصود بـ ' . number_format($amount - $remaining, 2))
                ->warning()
                ->persistent()
                ->send();
        }

        return DB::transaction(function () use ($record, $data, $amount, $budget, $currencyId) {
            $transaction = $record->transaction;
            $lines       = $transaction?->lines()->with('account')->get();

            $oldBeneficiary = $lines?->firstWhere('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY);
            $oldCredit      = $lines?->firstWhere('notes', ProjectCostBudgetsPayment::LINE_CREDIT);

            // STEP 1 - Reverse old balances
            $oldBeneficiary?->account?->decrement('current_balance', (float) $oldBeneficiary->debit_base);
            $oldCredit?->account?->increment('current_balance', (float) $oldCredit->credit_base);

            // STEP 2 - Recompute the credit account from the (possibly new) budget
            $creditAccountId = ExecutionPaymentForm::budgetDestinationLine($budget?->id)?->account_id;

            // STEP 3 - Update the transaction
            $transaction?->update([
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'],
                'notes'               => $data['notes'] ?? null,
                'updated_by'          => auth()->id(),
            ]);

            // STEP 4 - Replace the two transaction lines
            $transaction?->lines()->delete();
            if ($transaction) {
                $this->rebuildLines($transaction->id, $data['beneficiary_account_id'], $creditAccountId, $currencyId, $amount);
            }

            // STEP 5 - Update the execution payment row
            $record->update([
                'project_cost_budget_id' => $budget?->id,
                'amount'                 => $amount,
                'date'                   => Carbon::parse($data['date']),
                'notes'                  => $data['notes'] ?? null,
                'updated_by'             => auth()->id(),
            ]);

            // STEP 6 - Apply new balances
            Account::find($data['beneficiary_account_id'])?->increment('current_balance', $amount); // مدين
            if ($creditAccountId) {
                Account::find($creditAccountId)?->decrement('current_balance', $amount);            // دائن
            }

            // STEP 7 - Handle file swap
            $existing    = $record->attachments()->first();
            $newFilePath = $data['payment_image'] ?? null;
            $isNewFile   = $newFilePath && $newFilePath !== $existing?->file_path;

            if ($isNewFile) {
                if ($existing) {
                    Storage::disk('public')->delete($existing->file_path);
                    $existing->forceDelete();
                }
                $this->storeAttachment($record, $newFilePath, $amount);
            } elseif (! $newFilePath && $existing) {
                Storage::disk('public')->delete($existing->file_path);
                $existing->forceDelete();
            }

            // STEP 8 - Success
            Notification::make()
                ->title('تم تعديل مبلغ التنفيذ بنجاح')
                ->success()
                ->send();

            return $record;
        });
    }

    protected function rebuildLines(int $transactionId, $beneficiaryAccountId, $creditAccountId, ?int $currencyId, float $amount): void
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

        $attachment->update(['file_name' => $newName, 'file_path' => $newPath]);
    }
}
