<?php

namespace App\Filament\Resources\GeneralExchanges\Pages;

use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use App\Filament\Resources\GeneralExchanges\Schemas\GeneralExchangeForm;
use App\Filament\Resources\GeneralExchanges\Tables\GeneralExchangesTable;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\GeneralExchange;
use App\Models\TransactionLine;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EditGeneralExchange extends EditRecord
{
    protected static string $resource = GeneralExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->action(fn ($record) => GeneralExchangesTable::deleteExchange($record))
                ->successNotificationTitle('تم حذف التحويل بنجاح')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var GeneralExchange $record */
        $record = $this->getRecord();

        // Lines, identified by their role tag.
        $lines        = $record->transaction?->lines()->with('account')->get();
        $sourceLine   = $lines?->firstWhere('notes', GeneralExchange::LINE_SOURCE);
        $adminLine    = $lines?->firstWhere('notes', GeneralExchange::LINE_ADMIN);
        $transferLine = $lines?->firstWhere('notes', GeneralExchange::LINE_TRANSFER);
        $destLine     = $lines?->firstWhere('notes', GeneralExchange::LINE_DESTINATION);

        $sourceCurrencyId = $record->source_currency_id ?? $sourceLine?->currency_id;
        $disbCurrencyId   = $record->disbursement_currency_id ?? $destLine?->currency_id;

        $sourceCurrencyName = GeneralExchangeForm::currencyName($sourceCurrencyId);
        $disbCurrencyName   = GeneralExchangeForm::currencyName($disbCurrencyId);

        // Section 1 - amounts & percentages
        $original    = (float) $record->original_amount;
        $adminPct    = (float) $record->administrative_percentage;
        $transferPct = (float) $record->transfer_percentage;
        $fxRate      = (float) ($record->fx_rate ?: 1);

        [$adminAmount, $transferAmount, $afterDeduct, $finalAmount] = GeneralExchangeForm::deriveAmounts($original, $adminPct, $transferPct, $fxRate);

        $data['original_amount']           = $original;
        $data['source_currency_id']        = $sourceCurrencyId;
        $data['administrative_percentage'] = $adminPct;
        $data['transfer_percentage']       = $transferPct;
        $data['disbursement_currency_id']  = $disbCurrencyId;
        $data['fx_rate']                   = $fxRate;
        $data['administrative_amount']     = number_format($adminAmount, 2, '.', '');
        $data['transfer_amount']           = number_format($transferAmount, 2, '.', '');
        $data['amount_after_deductions']   = number_format($afterDeduct, 2, '.', '');
        $data['final_amount']              = number_format($finalAmount, 2, '.', '');

        // Section 2 - accounts (type + bank_type + currency display + account) from the lines
        $data['source_account_id']      = $sourceLine?->account_id;
        $data['source_account_type_id'] = $sourceLine?->account?->account_type_id;
        $data['source_bank_type_id']    = $sourceLine?->account?->bank_type_id;
        $data['source_currency']        = $sourceCurrencyName;

        $data['admin_account_id']      = $adminLine?->account_id;
        $data['admin_account_type_id'] = $adminLine?->account?->account_type_id;
        $data['admin_bank_type_id']    = $adminLine?->account?->bank_type_id;
        $data['admin_currency']        = $sourceCurrencyName;

        $data['transfer_account_id']      = $transferLine?->account_id;
        $data['transfer_account_type_id'] = $transferLine?->account?->account_type_id;
        $data['transfer_bank_type_id']    = $transferLine?->account?->bank_type_id;
        $data['transfer_currency']        = $sourceCurrencyName;

        $data['destination_account_id']      = $destLine?->account_id;
        $data['destination_account_type_id'] = $destLine?->account?->account_type_id;
        $data['destination_bank_type_id']    = $destLine?->account?->bank_type_id;
        $data['destination_currency']        = $disbCurrencyName;

        // Section 3 - transaction details
        $data['transaction_super_type_id'] = $record->transaction?->transactionType?->transaction_super_type_id;
        $data['transaction_type_id']       = $record->transaction?->transaction_type_id;
        $data['fiscal_year_id']            = $record->transaction?->fiscal_year_id;
        $data['partner_id']                = $record->partner_id ?? $record->transaction?->partner_id;
        $data['date']                      = $record->transaction?->transaction_time
            ? Carbon::parse($record->transaction->transaction_time)->toDateString()
            : ($record->date ? Carbon::parse($record->date)->toDateString() : null);

        // Section 4 - attachment
        $data['exchange_image'] = $record->attachments()->first()?->file_path;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var GeneralExchange $record */
        $original    = (float) $data['original_amount'];
        $adminPct    = (float) ($data['administrative_percentage'] ?? 0);
        $transferPct = (float) ($data['transfer_percentage'] ?? 0);
        $fxRate      = (float) ($data['fx_rate'] ?: 1);

        [$adminAmount, $transferAmount, , $finalAmount] = GeneralExchangeForm::deriveAmounts($original, $adminPct, $transferPct, $fxRate);

        $sourceCurrencyId = (int) $data['source_currency_id'];
        $disbCurrencyId   = (int) $data['disbursement_currency_id'];

        return DB::transaction(function () use (
            $record, $data, $original, $adminPct, $transferPct, $fxRate,
            $adminAmount, $transferAmount, $finalAmount, $sourceCurrencyId, $disbCurrencyId
        ) {
            $transaction = $record->transaction;
            $lines       = $transaction?->lines()->with('account')->get();

            $oldSource   = $lines?->firstWhere('notes', GeneralExchange::LINE_SOURCE);
            $oldAdmin    = $lines?->firstWhere('notes', GeneralExchange::LINE_ADMIN);
            $oldTransfer = $lines?->firstWhere('notes', GeneralExchange::LINE_TRANSFER);
            $oldDest     = $lines?->firstWhere('notes', GeneralExchange::LINE_DESTINATION);

            // STEP 1 - Reverse all old account balances
            $oldSource?->account?->increment('current_balance', (float) $oldSource->credit_base);
            $oldAdmin?->account?->decrement('current_balance', (float) $oldAdmin->debit_base);
            $oldTransfer?->account?->decrement('current_balance', (float) $oldTransfer->debit_base);
            $oldDest?->account?->decrement('current_balance', (float) $oldDest->debit_base);

            // STEP 2 - Update the transaction
            $transaction?->update([
                'fiscal_year_id'      => $data['fiscal_year_id'],
                'transaction_type_id' => $data['transaction_type_id'],
                'transaction_time'    => Carbon::parse($data['date']),
                'partner_id'          => $data['partner_id'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'updated_by'          => auth()->id(),
            ]);

            // STEP 3 - Replace the four transaction lines
            $transaction?->lines()->delete();
            if ($transaction) {
                $this->buildLines($transaction->id, $data, $sourceCurrencyId, $disbCurrencyId, [
                    'original' => $original,
                    'admin'    => $adminAmount,
                    'transfer' => $transferAmount,
                    'final'    => $finalAmount,
                    'fx'       => $fxRate,
                ]);
            }

            // STEP 4 - Update the general exchange row
            $record->update([
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
                'updated_by'                => auth()->id(),
            ]);

            // STEP 5 - Apply new account balances
            Account::find($data['source_account_id'])?->decrement('current_balance', $original);
            Account::find($data['admin_account_id'])?->increment('current_balance', $adminAmount);
            Account::find($data['transfer_account_id'])?->increment('current_balance', $transferAmount);
            Account::find($data['destination_account_id'])?->increment('current_balance', $finalAmount);

            // STEP 6 - Handle file swap
            $existing    = $record->attachments()->first();
            $newFilePath = $data['exchange_image'] ?? null;
            $isNewFile   = $newFilePath && $newFilePath !== $existing?->file_path;

            if ($isNewFile) {
                if ($existing) {
                    Storage::disk('public')->delete($existing->file_path);
                    $existing->forceDelete();
                }
                $this->storeAttachment($record, $newFilePath, $finalAmount);
            } elseif (! $newFilePath && $existing) {
                Storage::disk('public')->delete($existing->file_path);
                $existing->forceDelete();
            }

            // STEP 7 - Success
            Notification::make()
                ->title('تم تعديل التحويل بنجاح')
                ->success()
                ->send();

            return $record;
        });
    }

    /**
     * Four lines: 1 credit (source) + 3 debit (admin, transfer, destination),
     * each tagged via notes for later identification.
     */
    protected function buildLines(int $transactionId, array $data, int $sourceCurrencyId, int $disbCurrencyId, array $amounts): void
    {
        $uid = auth()->id();

        TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['source_account_id'],
            'currency_id' => $sourceCurrencyId, 'amount_currency' => $amounts['original'], 'fx_rate' => 1,
            'debit_base' => 0, 'credit_base' => $amounts['original'],
            'notes' => GeneralExchange::LINE_SOURCE, 'created_by' => $uid, 'updated_by' => $uid,
        ]);

        TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['admin_account_id'],
            'currency_id' => $sourceCurrencyId, 'amount_currency' => $amounts['admin'], 'fx_rate' => 1,
            'debit_base' => $amounts['admin'], 'credit_base' => 0,
            'notes' => GeneralExchange::LINE_ADMIN, 'created_by' => $uid, 'updated_by' => $uid,
        ]);

        TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['transfer_account_id'],
            'currency_id' => $sourceCurrencyId, 'amount_currency' => $amounts['transfer'], 'fx_rate' => 1,
            'debit_base' => $amounts['transfer'], 'credit_base' => 0,
            'notes' => GeneralExchange::LINE_TRANSFER, 'created_by' => $uid, 'updated_by' => $uid,
        ]);

        TransactionLine::create([
            'transaction_id' => $transactionId, 'account_id' => $data['destination_account_id'],
            'currency_id' => $disbCurrencyId, 'amount_currency' => $amounts['final'], 'fx_rate' => $amounts['fx'],
            'debit_base' => $amounts['final'], 'credit_base' => 0,
            'notes' => GeneralExchange::LINE_DESTINATION, 'created_by' => $uid, 'updated_by' => $uid,
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

        $attachment->update(['file_name' => $newName, 'file_path' => $newPath]);
    }
}
