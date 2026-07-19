<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use App\Models\Attachment;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
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

        return DB::transaction(function () use ($data, $projectCost, $accounts, $lines) {
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
