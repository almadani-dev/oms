<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Services\Transactions\TransactionDescriptionBuilder;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EditProjectCostBudgetsPayment extends EditRecord
{
    protected static string $resource = ProjectCostBudgetsPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(fn ($record) => ProjectCostBudgetsPaymentsTable::deletePayment($record))
                ->successNotificationTitle('تم حذف الصرف بنجاح')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ProjectCostBudget $record */
        $record = $this->getRecord();

        // Lines (identified by their role tag)
        $lines        = $record->transaction?->lines()->with('account')->get();
        $sourceLine   = $lines?->firstWhere('notes', ProjectCostBudget::LINE_SOURCE);
        $adminLine    = $lines?->firstWhere('notes', ProjectCostBudget::LINE_ADMIN);
        $transferLine = $lines?->firstWhere('notes', ProjectCostBudget::LINE_TRANSFER);
        $destLine     = $lines?->firstWhere('notes', ProjectCostBudget::LINE_DESTINATION);

        // Section 1 - project cascade
        $projectCost = ProjectCost::with('project', 'currency')->find($record->project_cost_id);
        $data['project_cost_id']  = $projectCost?->id;
        $data['project_id']       = $projectCost?->project_id;
        $data['project_super_id'] = $projectCost?->project?->project_super_id;
        $data['cost_currency']    = $projectCost?->currency?->name;

        // Section 2 - amounts & percentages
        $original    = (float) $record->original_amount;
        $adminPct    = (float) $record->administrative_percentage;
        $transferPct = (float) $record->transfer_percentage;
        $fxRate      = (float) ($record->fx_rate ?: 1);

        $adminAmount    = round($original * $adminPct / 100, 2);
        $transferAmount = round($original * $transferPct / 100, 2);
        $afterDeduct    = round($original - $adminAmount - $transferAmount, 2);
        $finalAmount    = round($afterDeduct * $fxRate, 2);

        $data['original_amount']           = $original;
        $data['administrative_percentage'] = $adminPct;
        $data['transfer_percentage']       = $transferPct;
        $data['fx_rate']                   = $fxRate;
        $data['disbursement_currency_id']  = $destLine?->currency_id;
        $data['administrative_amount']     = number_format($adminAmount, 2, '.', '');
        $data['transfer_amount']           = number_format($transferAmount, 2, '.', '');
        $data['amount_after_deductions']   = number_format($afterDeduct, 2, '.', '');
        $data['final_amount']              = number_format($finalAmount, 2, '.', '');

        // Section 3 - accounts (type + bank_type + currency display + account) from the lines
        $costCurrencyName        = $projectCost?->currency?->name;
        $disbursementCurrencyName = $destLine?->currency?->name;

        $data['source_account_id']         = $sourceLine?->account_id;
        $data['source_account_type_id']    = $sourceLine?->account?->account_type_id;
        $data['source_bank_type_id']       = $sourceLine?->account?->bank_type_id;
        $data['source_currency']           = $costCurrencyName;

        $data['admin_account_id']          = $adminLine?->account_id;
        $data['admin_account_type_id']     = $adminLine?->account?->account_type_id;
        $data['admin_bank_type_id']        = $adminLine?->account?->bank_type_id;
        $data['admin_currency']            = $costCurrencyName;

        $data['transfer_account_id']       = $transferLine?->account_id;
        $data['transfer_account_type_id']  = $transferLine?->account?->account_type_id;
        $data['transfer_bank_type_id']     = $transferLine?->account?->bank_type_id;
        $data['transfer_currency']         = $costCurrencyName;

        $data['destination_account_id']      = $destLine?->account_id;
        $data['destination_account_type_id'] = $destLine?->account?->account_type_id;
        $data['destination_bank_type_id']    = $destLine?->account?->bank_type_id;
        $data['destination_currency']        = $disbursementCurrencyName;

        // Section 4 - transaction details
        $data['transaction_super_type_id'] = $record->transaction?->transactionType?->transaction_super_type_id;
        $data['transaction_type_id']       = $record->transaction?->transaction_type_id;
        $data['fiscal_year_id']            = $record->transaction?->fiscal_year_id;
        $data['partner_id']                = $record->transaction?->partner_id;
        $data['date']                      = $record->transaction?->transaction_time
            ? Carbon::parse($record->transaction->transaction_time)->toDateString()
            : null;

        // Section 5 - attachment
        $data['payment_image'] = $record->attachments()->first()?->file_path;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ProjectCostBudget $record */
        $projectCost    = ProjectCost::find($data['project_cost_id']);
        $projectCostId  = $projectCost?->id;
        $costCurrencyId = $projectCost?->currency_id;

        $original    = (float) $data['original_amount'];
        $adminPct    = (float) ($data['administrative_percentage'] ?? 0);
        $transferPct = (float) ($data['transfer_percentage'] ?? 0);
        $fxRate      = (float) ($data['fx_rate'] ?: 1);

        $adminAmount    = round($original * $adminPct / 100, 2);
        $transferAmount = round($original * $transferPct / 100, 2);
        $afterDeduct    = round($original - $adminAmount - $transferAmount, 2);
        $finalAmount    = round($afterDeduct * $fxRate, 2);

        return DB::transaction(function () use (
            $record, $data, $projectCost, $projectCostId, $costCurrencyId,
            $original, $adminPct, $transferPct, $adminAmount, $transferAmount, $afterDeduct, $finalAmount, $fxRate
        ) {
            $transaction = $record->transaction;
            $lines       = $transaction?->lines()->with('account')->get();

            // STEP 1 - Reverse all old account balances
            $oldSource   = $lines?->firstWhere('notes', ProjectCostBudget::LINE_SOURCE);
            $oldAdmin    = $lines?->firstWhere('notes', ProjectCostBudget::LINE_ADMIN);
            $oldTransfer = $lines?->firstWhere('notes', ProjectCostBudget::LINE_TRANSFER);
            $oldDest     = $lines?->firstWhere('notes', ProjectCostBudget::LINE_DESTINATION);

            $oldSource?->account?->increment('current_balance', (float) $oldSource->credit_base);
            $oldAdmin?->account?->decrement('current_balance', (float) $oldAdmin->debit_base);
            $oldTransfer?->account?->decrement('current_balance', (float) $oldTransfer->debit_base);
            $oldDest?->account?->decrement('current_balance', (float) $oldDest->debit_base);

            // STEP 2 - Update transaction record
            $transaction?->update([
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'],
                'notes'               => $data['notes'] ?? null,
                'updated_by'          => auth()->id(),
            ]);

            // STEP 3 - Replace old transaction_lines, create new ones (hard delete: these are
            // being immediately recreated, so no soft-deleted duplicates should accumulate)
            $transaction?->lines()->forceDelete();

            if ($transaction) {
                $this->rebuildLines($transaction->id, $projectCostId, $costCurrencyId, $data, [
                    'original' => $original,
                    'admin'    => $adminAmount,
                    'transfer' => $transferAmount,
                    'final'    => $finalAmount,
                    'fx'       => $fxRate,
                ]);
            }

            // STEP 4 - Update project_cost_budgets row
            $record->update([
                'project_cost_id'           => $projectCostId,
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
                'updated_by'                => auth()->id(),
            ]);

            // STEP 5 - Apply new account balances
            Account::find($data['source_account_id'])?->decrement('current_balance', $original);
            Account::find($data['admin_account_id'])?->increment('current_balance', $adminAmount);
            Account::find($data['transfer_account_id'])?->increment('current_balance', $transferAmount);
            Account::find($data['destination_account_id'])?->increment('current_balance', $finalAmount);

            // STEP 5b - Regenerate the Arabic transaction description from the final saved state
            if ($transaction) {
                app(TransactionDescriptionBuilder::class)->buildAndSave(
                    $transaction,
                    $this->buildDisbursementSummary($projectCost)
                );
            }

            // STEP 6 - Handle file swap
            $existing    = $record->attachments()->first();
            $newFilePath = $data['payment_image'] ?? null;
            $isNewFile   = $newFilePath && $newFilePath !== $existing?->file_path;

            if ($isNewFile) {
                if ($existing) {
                    $existing->delete();
                }
                $this->storeAttachment($record, $newFilePath, $finalAmount);
            } elseif (! $newFilePath && $existing) {
                $existing->delete();
            }

            // STEP 7 - Success
            Notification::make()
                ->title('تم تعديل الصرف بنجاح')
                ->success()
                ->send();

            return $record;
        });
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

    protected function rebuildLines(int $transactionId, ?int $projectCostId, ?int $costCurrencyId, array $data, array $amounts): void
    {
        $uid = auth()->id();

        \App\Models\TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['source_account_id'],
            'project_cost_id' => $projectCostId, 'currency_id' => $costCurrencyId,
            'amount_currency' => $amounts['original'], 'fx_rate' => 1,
            'debit_base' => 0, 'credit_base' => $amounts['original'],
            'notes' => ProjectCostBudget::LINE_SOURCE, 'created_by' => $uid, 'updated_by' => $uid,
        ]);

        \App\Models\TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['admin_account_id'],
            'project_cost_id' => $projectCostId, 'currency_id' => $costCurrencyId,
            'amount_currency' => $amounts['admin'], 'fx_rate' => 1,
            'debit_base' => $amounts['admin'], 'credit_base' => 0,
            'notes' => ProjectCostBudget::LINE_ADMIN, 'created_by' => $uid, 'updated_by' => $uid,
        ]);

        \App\Models\TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['transfer_account_id'],
            'project_cost_id' => $projectCostId, 'currency_id' => $costCurrencyId,
            'amount_currency' => $amounts['transfer'], 'fx_rate' => 1,
            'debit_base' => $amounts['transfer'], 'credit_base' => 0,
            'notes' => ProjectCostBudget::LINE_TRANSFER, 'created_by' => $uid, 'updated_by' => $uid,
        ]);

        \App\Models\TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['destination_account_id'],
            'project_cost_id' => $projectCostId, 'currency_id' => $data['disbursement_currency_id'],
            'amount_currency' => $amounts['final'], 'fx_rate' => $amounts['fx'],
            'debit_base' => $amounts['final'], 'credit_base' => 0,
            'notes' => ProjectCostBudget::LINE_DESTINATION, 'created_by' => $uid, 'updated_by' => $uid,
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

        $attachment->update(['file_name' => $newName, 'file_path' => $newPath]);
    }
}
