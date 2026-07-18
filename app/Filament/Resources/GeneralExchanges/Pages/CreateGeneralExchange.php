<?php

namespace App\Filament\Resources\GeneralExchanges\Pages;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use App\Filament\Resources\GeneralExchanges\Schemas\GeneralExchangeForm;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\GeneralExchange;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use App\Services\Validation\FinancialAccountGuard;
use App\Services\Validation\FinancialAmountGuard;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateGeneralExchange extends CreateRecord
{
    protected static string $resource = GeneralExchangeResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        // Derive every amount on the server from the trusted inputs.
        $original    = (float) $data['original_amount'];
        $adminPct    = (float) ($data['administrative_percentage'] ?? 0);
        $transferPct = (float) ($data['transfer_percentage'] ?? 0);
        $fxRate      = (float) ($data['fx_rate'] ?? 1);

        [$adminAmount, $transferAmount, $afterDeduct, $finalAmount] = GeneralExchangeForm::deriveAmounts($original, $adminPct, $transferPct, $fxRate);

        FinancialAmountGuard::assertDisbursementInputs($original, $adminPct, $transferPct, $fxRate, $afterDeduct, $finalAmount);

        $sourceCurrencyId = (int) $data['source_currency_id'];
        $disbCurrencyId   = (int) $data['disbursement_currency_id'];

        $accounts = FinancialAccountGuard::assertAccounts([
            'source' => [
                'account_id'      => $data['source_account_id'] ?? null,
                'account_type_id' => $data['source_account_type_id'] ?? null,
                'bank_type_id'    => $data['source_bank_type_id'] ?? null,
                'currency_id'     => $sourceCurrencyId,
                'field'           => 'source_account_id',
                'label'           => 'حساب المصدر',
            ],
            'admin' => [
                'account_id'      => $data['admin_account_id'] ?? null,
                'account_type_id' => $data['admin_account_type_id'] ?? null,
                'bank_type_id'    => $data['admin_bank_type_id'] ?? null,
                'currency_id'     => $sourceCurrencyId,
                'field'           => 'admin_account_id',
                'label'           => 'حساب النسبة الإدارية',
            ],
            'transfer' => [
                'account_id'      => $data['transfer_account_id'] ?? null,
                'account_type_id' => $data['transfer_account_type_id'] ?? null,
                'bank_type_id'    => $data['transfer_bank_type_id'] ?? null,
                'currency_id'     => $sourceCurrencyId,
                'field'           => 'transfer_account_id',
                'label'           => 'حساب التحويل',
            ],
            'destination' => [
                'account_id'      => $data['destination_account_id'] ?? null,
                'account_type_id' => $data['destination_account_type_id'] ?? null,
                'bank_type_id'    => $data['destination_bank_type_id'] ?? null,
                'currency_id'     => $disbCurrencyId,
                'field'           => 'destination_account_id',
                'label'           => 'حساب الوجهة',
            ],
        ]);

        return DB::transaction(function () use (
            $data, $original, $adminPct, $transferPct, $fxRate,
            $adminAmount, $transferAmount, $finalAmount, $sourceCurrencyId, $disbCurrencyId, $accounts
        ) {
            // STEP 1 - Create transaction (EXT-YYYY-XXXX)
            $year              = Carbon::parse($data['date'])->format('Y');
            $transactionNumber = $this->generateTransactionNumber('EXT-' . $year . '-');

            $transaction = Transaction::create([
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_number'  => $transactionNumber,
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'created_by'          => auth()->id(),
                'updated_by'          => auth()->id(),
            ]);

            // STEP 2 - Create the four transaction lines
            $this->buildLines($transaction->id, $data, $sourceCurrencyId, $disbCurrencyId, [
                'original' => $original,
                'admin'    => $adminAmount,
                'transfer' => $transferAmount,
                'final'    => $finalAmount,
                'fx'       => $fxRate,
            ]);

            // STEP 3 - Create the general exchange row
            $exchange = GeneralExchange::create([
                'transaction_id'            => $transaction->id,
                'original_amount'           => $original,
                'administrative_percentage' => $adminPct,
                'transfer_percentage'       => $transferPct,
                'fx_rate'                   => $fxRate,
                'final_amount'              => $finalAmount,
                'source_currency_id'        => $sourceCurrencyId,
                'disbursement_currency_id'  => $disbCurrencyId,
                'partner_id'                => $data['partner_id'] ?? null,
                'notes'                     => $data['notes'] ?? null,
                'date'                      => Carbon::parse($data['date']),
                'created_by'                => auth()->id(),
                'updated_by'                => auth()->id(),
            ]);

            // STEP 4 - Update account balances
            $accounts['source']->decrement('current_balance', $original);
            $accounts['admin']->increment('current_balance', $adminAmount);
            $accounts['transfer']->increment('current_balance', $transferAmount);
            $accounts['destination']->increment('current_balance', $finalAmount);

            // STEP 4b - Generate & save the Arabic line descriptions, then the
            // parent transaction description (both from the final saved lines)
            app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                $transaction,
                $this->buildGeneralExchangeLinePurposes()
            );

            app(TransactionDescriptionBuilder::class)->buildAndSave(
                $transaction,
                $this->buildGeneralExchangeSummary($data['source_account_id'], $data['destination_account_id'])
            );

            // STEP 5 - Store the attachment if provided
            if (! empty($data['exchange_image'])) {
                $this->storeAttachment($exchange, $data['exchange_image'], $finalAmount);
            }

            // STEP 6 - Success notification
            Notification::make()
                ->title('تم التحويل بنجاح')
                ->body('رقم المعاملة: ' . $transactionNumber)
                ->success()
                ->send();

            return $exchange;
        });
    }

    /**
     * Build the next transaction number for the given prefix (e.g. "EXT-2026-").
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
     * "تحويل مبلغ من {source} إلى {destination}" using the final saved source
     * and destination accounts, with a fallback if either is unavailable.
     */
    protected function buildGeneralExchangeSummary($sourceAccountId, $destinationAccountId): string
    {
        $sourceName      = Account::find($sourceAccountId)?->name;
        $destinationName = Account::find($destinationAccountId)?->name;

        return ($sourceName && $destinationName)
            ? "تحويل مبلغ من {$sourceName} إلى {$destinationName}"
            : 'تسجيل تحويل عام';
    }

    /**
     * Per-line purposes keyed by line_role (fixed wording for this flow).
     *
     * @return array<string, string>
     */
    protected function buildGeneralExchangeLinePurposes(): array
    {
        return [
            TransactionLineRole::Source->value                  => 'إخراج مبلغ التحويل من حساب المصدر',
            TransactionLineRole::AdministrativeDeduction->value => 'إثبات الخصم الإداري على التحويل العام',
            TransactionLineRole::TransferFee->value             => 'إثبات عمولة التحويل العام',
            TransactionLineRole::Destination->value             => 'إثبات صافي المبلغ المحول إلى حساب الوجهة',
        ];
    }

    /**
     * Four lines: 1 credit (source) + 3 debit (admin, transfer, destination),
     * each tagged via notes for later identification on edit / view / delete.
     */
    protected function buildLines(int $transactionId, array $data, int $sourceCurrencyId, int $disbCurrencyId, array $amounts): void
    {
        $uid = auth()->id();

        // Line 1 - دائن - المصدر (بعملة المصدر)
        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $data['source_account_id'],
            'currency_id'     => $sourceCurrencyId,
            'amount_currency' => $amounts['original'],
            'fx_rate'         => 1,
            'debit_base'      => 0,
            'credit_base'     => $amounts['original'],
            'notes'           => GeneralExchange::LINE_SOURCE,
            'line_role'       => TransactionLineRole::Source->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);

        // Line 2 - مدين - النسبة الإدارية (بعملة المصدر)
        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $data['admin_account_id'],
            'currency_id'     => $sourceCurrencyId,
            'amount_currency' => $amounts['admin'],
            'fx_rate'         => 1,
            'debit_base'      => $amounts['admin'],
            'credit_base'     => 0,
            'notes'           => GeneralExchange::LINE_ADMIN,
            'line_role'       => TransactionLineRole::AdministrativeDeduction->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);

        // Line 3 - مدين - التحويل (بعملة المصدر)
        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $data['transfer_account_id'],
            'currency_id'     => $sourceCurrencyId,
            'amount_currency' => $amounts['transfer'],
            'fx_rate'         => 1,
            'debit_base'      => $amounts['transfer'],
            'credit_base'     => 0,
            'notes'           => GeneralExchange::LINE_TRANSFER,
            'line_role'       => TransactionLineRole::TransferFee->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);

        // Line 4 - مدين - الوجهة (بعملة الصرف)
        TransactionLine::create([
            'transaction_id'  => $transactionId,
            'account_id'      => $data['destination_account_id'],
            'currency_id'     => $disbCurrencyId,
            'amount_currency' => $amounts['final'],
            'fx_rate'         => $amounts['fx'],
            'debit_base'      => $amounts['final'],
            'credit_base'     => 0,
            'notes'           => GeneralExchange::LINE_DESTINATION,
            'line_role'       => TransactionLineRole::Destination->value,
            'created_by'      => $uid,
            'updated_by'      => $uid,
        ]);
    }

    protected function storeAttachment(GeneralExchange $exchange, string $tempPath, float $finalAmount): void
    {
        $ext      = pathinfo($tempPath, PATHINFO_EXTENSION);
        $mimeType = Storage::disk('public')->mimeType($tempPath);
        $fileSize = Storage::disk('public')->size($tempPath);

        $attachment = Attachment::create([
            'attachable_type' => GeneralExchange::class,
            'attachable_id'   => $exchange->id,
            'file_name'       => basename($tempPath),
            'file_path'       => $tempPath,
            'file_type'       => $mimeType,
            'file_size'       => $fileSize,
            'created_by'      => auth()->id(),
            'updated_by'      => auth()->id(),
        ]);

        $newName = 'ext_' . $attachment->id
            . '_' . Carbon::parse($exchange->date ?? now())->format('Ymd')
            . '_' . (int) $finalAmount
            . '.' . $ext;

        $newPath = 'general-exchanges/' . $newName;
        Storage::disk('public')->move($tempPath, $newPath);

        $attachment->update([
            'file_name' => $newName,
            'file_path' => $newPath,
        ]);
    }
}
