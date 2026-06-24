<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateProjectCostReceipt extends CreateRecord
{
    protected static string $resource = ProjectCostReceiptResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
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

            $projectCost = ProjectCost::find($data['project_cost_id']);

            // STEP 2 - Create debit transaction_line (مدين)
            TransactionLine::create([
                'transaction_id'  => $transaction->id,
                'account_id'      => $data['debit_account_id'],
                'currency_id'     => $projectCost?->currency_id,
                'amount_currency' => $data['amount'],
                'fx_rate'         => 1,
                'debit_base'      => $data['amount'],
                'credit_base'     => 0,
                'created_by'      => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

            // STEP 3 - Create credit transaction_line (دائن)
            TransactionLine::create([
                'transaction_id'  => $transaction->id,
                'account_id'      => $data['credit_account_id'],
                'currency_id'     => $projectCost?->currency_id,
                'amount_currency' => $data['amount'],
                'fx_rate'         => 1,
                'debit_base'      => 0,
                'credit_base'     => $data['amount'],
                'created_by'      => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

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
            Account::find($data['debit_account_id'])?->increment('current_balance', $data['amount']);
            Account::find($data['credit_account_id'])?->decrement('current_balance', $data['amount']);

            // STEP 6 - If file uploaded
            if (!empty($data['receipt_image'])) {
                $tempPath = $data['receipt_image'];
                $ext      = pathinfo($tempPath, PATHINFO_EXTENSION);
                $mimeType = Storage::disk('public')->mimeType($tempPath);
                $fileSize = Storage::disk('public')->size($tempPath);

                $attachment = Attachment::create([
                    'attachable_type' => ProjectCostReceipt::class,
                    'attachable_id'   => $receipt->id,
                    'file_name'       => basename($tempPath),
                    'file_path'       => $tempPath,
                    'file_type'       => $mimeType,
                    'file_size'       => $fileSize,
                    'created_by'      => auth()->id(),
                    'updated_by'      => auth()->id(),
                ]);

                $newName = 'receive_' . $attachment->id
                    . '_' . Carbon::parse($receipt->date)->format('Ymd')
                    . '_' . (int) $receipt->amount
                    . '.' . $ext;

                $newPath = 'receipts/' . $newName;

                Storage::disk('public')->move($tempPath, $newPath);

                $attachment->update([
                    'file_name' => $newName,
                    'file_path' => $newPath,
                ]);
            }

            // STEP 7 - Success notification
            Notification::make()
                ->title('تم تسجيل الاستلام بنجاح')
                ->body('رقم المعاملة: ' . $transactionNumber)
                ->success()
                ->send();

            return $receipt;
        });
    }

    /**
     * Build the next transaction number for the given prefix (e.g. "REC-2026-").
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
}
