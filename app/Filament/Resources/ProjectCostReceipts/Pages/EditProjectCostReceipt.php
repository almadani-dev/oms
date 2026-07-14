<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Transactions\TransactionDescriptionBuilder;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EditProjectCostReceipt extends EditRecord
{
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

        $attachment            = $record->attachments()->first();
        $data['receipt_image'] = $attachment?->file_path;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            // STEP 1 - Get old lines
            $oldDebitLine  = $record->transaction?->lines()->with('account')->where('debit_base', '>', 0)->first();
            $oldCreditLine = $record->transaction?->lines()->with('account')->where('credit_base', '>', 0)->first();
            $oldAmount     = $record->amount;

            // STEP 2 - Reverse old balances
            $oldDebitLine?->account?->decrement('current_balance', $oldAmount);
            $oldCreditLine?->account?->increment('current_balance', $oldAmount);

            // STEP 3 - Update transaction
            $record->transaction?->update([
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'],
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'notes'               => $data['notes'] ?? null,
                'updated_by'          => auth()->id(),
            ]);

            $projectCost = ProjectCost::find($data['project_cost_id']);

            // STEP 4 - Update debit transaction_line
            $oldDebitLine?->update([
                'account_id'      => $data['debit_account_id'],
                'currency_id'     => $projectCost?->currency_id,
                'amount_currency' => $data['amount'],
                'debit_base'      => $data['amount'],
                'updated_by'      => auth()->id(),
            ]);

            // STEP 5 - Update credit transaction_line
            $oldCreditLine?->update([
                'account_id'      => $data['credit_account_id'],
                'currency_id'     => $projectCost?->currency_id,
                'amount_currency' => $data['amount'],
                'credit_base'     => $data['amount'],
                'updated_by'      => auth()->id(),
            ]);

            // STEP 6 - Update project_cost_receipts
            $record->update([
                'project_cost_id' => $data['project_cost_id'],
                'amount'          => $data['amount'],
                'currency_id'     => $projectCost?->currency_id,
                'date'            => $data['date'],
                'notes'           => $data['notes'] ?? null,
                'updated_by'      => auth()->id(),
            ]);

            // STEP 7 - Apply new balances
            Account::find($data['debit_account_id'])?->increment('current_balance', $data['amount']);
            Account::find($data['credit_account_id'])?->decrement('current_balance', $data['amount']);

            // STEP 7b - Regenerate the Arabic transaction description from the final saved state
            if ($record->transaction) {
                app(TransactionDescriptionBuilder::class)->buildAndSave(
                    $record->transaction,
                    $this->buildReceiptSummary($record->transaction, $projectCost)
                );
            }

            // STEP 8 - Handle file upload
            $existingAttachment = $record->attachments()->first();
            $newFilePath        = $data['receipt_image'] ?? null;
            $isNewFile          = $newFilePath && $newFilePath !== $existingAttachment?->file_path;

            if ($isNewFile) {
                if ($existingAttachment) {
                    $existingAttachment->delete();
                }

                $tempPath = $newFilePath;
                $ext      = pathinfo($tempPath, PATHINFO_EXTENSION);
                $mimeType = Storage::disk('public')->mimeType($tempPath);
                $fileSize = Storage::disk('public')->size($tempPath);

                $attachment = Attachment::create([
                    'attachable_type' => ProjectCostReceipt::class,
                    'attachable_id'   => $record->id,
                    'file_name'       => basename($tempPath),
                    'file_path'       => $tempPath,
                    'file_type'       => $mimeType,
                    'file_size'       => $fileSize,
                    'created_by'      => auth()->id(),
                    'updated_by'      => auth()->id(),
                ]);

                $newName = 'receive_' . $attachment->id
                    . '_' . Carbon::parse($record->date)->format('Ymd')
                    . '_' . (int) $record->amount
                    . '.' . $ext;

                $newPath = 'receipts/' . $newName;

                Storage::disk('public')->move($tempPath, $newPath);

                $attachment->update([
                    'file_name' => $newName,
                    'file_path' => $newPath,
                ]);
            } elseif (!$newFilePath && $existingAttachment) {
                $existingAttachment->delete();
            }

            // STEP 9 - Success notification
            Notification::make()
                ->title('تم تعديل الاستلام بنجاح')
                ->success()
                ->send();

            return $record;
        });
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
}
