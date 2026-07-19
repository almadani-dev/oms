<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable;
use App\Models\Attachment;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\TransactionLine;
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
        $fxRate      = (float) ($data['fx_rate'] ?? 1);

        $adminAmount    = round($original * $adminPct / 100, 2);
        $transferAmount = round($original * $transferPct / 100, 2);
        $afterDeduct    = round($original - $adminAmount - $transferAmount, 2);
        $finalAmount    = round($afterDeduct * $fxRate, 2);

        FinancialAmountGuard::assertDisbursementInputs($original, $adminPct, $transferPct, $fxRate, $afterDeduct, $finalAmount);

        // Old lines fetched before the account guard so an unchanged historical
        // account may remain inactive; see FinancialAccountGuard::requireActiveOnChange().
        $lines       = $record->transaction?->lines()->with('account')->get();
        $oldSource   = $lines?->firstWhere('notes', ProjectCostBudget::LINE_SOURCE);
        $oldAdmin    = $lines?->firstWhere('notes', ProjectCostBudget::LINE_ADMIN);
        $oldTransfer = $lines?->firstWhere('notes', ProjectCostBudget::LINE_TRANSFER);
        $oldDest     = $lines?->firstWhere('notes', ProjectCostBudget::LINE_DESTINATION);

        $accounts = FinancialAccountGuard::assertAccounts([
            'source' => [
                'account_id'      => $data['source_account_id'] ?? null,
                'account_type_id' => $data['source_account_type_id'] ?? null,
                'bank_type_id'    => $data['source_bank_type_id'] ?? null,
                'currency_id'     => $costCurrencyId,
                'field'           => 'source_account_id',
                'label'           => 'حساب المصدر',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldSource?->account_id, $data['source_account_id'] ?? null),
            ],
            'admin' => [
                'account_id'      => $data['admin_account_id'] ?? null,
                'account_type_id' => $data['admin_account_type_id'] ?? null,
                'bank_type_id'    => $data['admin_bank_type_id'] ?? null,
                'currency_id'     => $costCurrencyId,
                'field'           => 'admin_account_id',
                'label'           => 'حساب النسبة الإدارية',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldAdmin?->account_id, $data['admin_account_id'] ?? null),
            ],
            'transfer' => [
                'account_id'      => $data['transfer_account_id'] ?? null,
                'account_type_id' => $data['transfer_account_type_id'] ?? null,
                'bank_type_id'    => $data['transfer_bank_type_id'] ?? null,
                'currency_id'     => $costCurrencyId,
                'field'           => 'transfer_account_id',
                'label'           => 'حساب التحويل',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldTransfer?->account_id, $data['transfer_account_id'] ?? null),
            ],
            'destination' => [
                'account_id'      => $data['destination_account_id'] ?? null,
                'account_type_id' => $data['destination_account_type_id'] ?? null,
                'bank_type_id'    => $data['destination_bank_type_id'] ?? null,
                'currency_id'     => $data['disbursement_currency_id'] ?? null,
                'field'           => 'destination_account_id',
                'label'           => 'حساب الوجهة',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldDest?->account_id, $data['destination_account_id'] ?? null),
            ],
        ]);

        $lines = $this->buildLines($projectCostId, $costCurrencyId, $data, [
            'original' => $original,
            'admin'    => $adminAmount,
            'transfer' => $transferAmount,
            'final'    => $finalAmount,
            'fx'       => $fxRate,
        ]);

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
            $lines[0], $lines[1], $lines[2], $lines[3],
            (int) $costCurrencyId, (int) $data['disbursement_currency_id']
        );

        return DB::transaction(function () use (
            $record, $data, $projectCost, $projectCostId, $costCurrencyId,
            $original, $adminPct, $transferPct, $adminAmount, $transferAmount, $afterDeduct, $finalAmount, $fxRate,
            $oldSource, $oldAdmin, $oldTransfer, $oldDest, $accounts, $lines
        ) {
            $transaction = $record->transaction;

            // STEP 1 - Reverse all old account balances
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
            // with the validated payload built above, unchanged.
            $transaction?->lines()->forceDelete();

            if ($transaction) {
                foreach ($lines as $line) {
                    TransactionLine::create($line + ['transaction_id' => $transaction->id]);
                }
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
            $accounts['source']->decrement('current_balance', $original);
            $accounts['admin']->increment('current_balance', $adminAmount);
            $accounts['transfer']->increment('current_balance', $transferAmount);
            $accounts['destination']->increment('current_balance', $finalAmount);

            // STEP 5b - Regenerate the Arabic line descriptions and the parent
            // transaction description from the final saved state
            if ($transaction) {
                app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                    $transaction,
                    $this->buildDisbursementLinePurposes()
                );

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
     * Build the exact four-line TransactionLine payload (source, admin,
     * transfer, destination — in this fixed order) in memory, without
     * transaction_id, so it can be validated by
     * FinancialTransactionBalanceGuard before DB::transaction() opens. The
     * transaction_id is merged in at insert time; nothing else is
     * recalculated.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildLines(?int $projectCostId, ?int $costCurrencyId, array $data, array $amounts): array
    {
        $uid = auth()->id();

        return [
            [
                'account_id' => $data['source_account_id'],
                'project_cost_id' => $projectCostId, 'currency_id' => $costCurrencyId,
                'amount_currency' => $amounts['original'], 'fx_rate' => 1,
                'debit_base' => 0, 'credit_base' => $amounts['original'],
                'notes' => ProjectCostBudget::LINE_SOURCE,
                'line_role' => TransactionLineRole::Source->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
            [
                'account_id' => $data['admin_account_id'],
                'project_cost_id' => $projectCostId, 'currency_id' => $costCurrencyId,
                'amount_currency' => $amounts['admin'], 'fx_rate' => 1,
                'debit_base' => $amounts['admin'], 'credit_base' => 0,
                'notes' => ProjectCostBudget::LINE_ADMIN,
                'line_role' => TransactionLineRole::AdministrativeDeduction->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
            [
                'account_id' => $data['transfer_account_id'],
                'project_cost_id' => $projectCostId, 'currency_id' => $costCurrencyId,
                'amount_currency' => $amounts['transfer'], 'fx_rate' => 1,
                'debit_base' => $amounts['transfer'], 'credit_base' => 0,
                'notes' => ProjectCostBudget::LINE_TRANSFER,
                'line_role' => TransactionLineRole::TransferFee->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
            [
                'account_id' => $data['destination_account_id'],
                'project_cost_id' => $projectCostId, 'currency_id' => $data['disbursement_currency_id'],
                'amount_currency' => $amounts['final'], 'fx_rate' => $amounts['fx'],
                'debit_base' => $amounts['final'], 'credit_base' => 0,
                'notes' => ProjectCostBudget::LINE_DESTINATION,
                'line_role' => TransactionLineRole::Destination->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
        ];
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
