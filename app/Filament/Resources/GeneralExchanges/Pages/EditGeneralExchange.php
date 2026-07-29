<?php

namespace App\Filament\Resources\GeneralExchanges\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use App\Filament\Resources\GeneralExchanges\Schemas\GeneralExchangeForm;
use App\Filament\Resources\GeneralExchanges\Tables\GeneralExchangesTable;
use App\Models\Account;
use App\Models\GeneralExchange;
use App\Models\TransactionLine;
use App\Services\Attachments\AttachmentUploadService;
use App\Services\Audit\Financial\FinancialAccountRole;
use App\Services\Audit\Financial\FinancialAuditRecorder;
use App\Services\Audit\Financial\FinancialAuditSubject;
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

class EditGeneralExchange extends EditRecord
{
    use RedirectsToResourceView;

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

        // Section 4 - attachment: the replacement upload field is
        // intentionally left empty - the current attachment is shown
        // separately via the secure preview component, never prefilled
        // into FileUpload with a private path.

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var GeneralExchange $record */
        $original    = (float) $data['original_amount'];
        $adminPct    = (float) ($data['administrative_percentage'] ?? 0);
        $transferPct = (float) ($data['transfer_percentage'] ?? 0);
        $fxRate      = (float) ($data['fx_rate'] ?? 1);

        [$adminAmount, $transferAmount, $afterDeduct, $finalAmount] = GeneralExchangeForm::deriveAmounts($original, $adminPct, $transferPct, $fxRate);

        FinancialAmountGuard::assertDisbursementInputs($original, $adminPct, $transferPct, $fxRate, $afterDeduct, $finalAmount);

        $sourceCurrencyId = (int) $data['source_currency_id'];
        $disbCurrencyId   = (int) $data['disbursement_currency_id'];

        // Old lines fetched before the account guard so an unchanged historical
        // account may remain inactive; see FinancialAccountGuard::requireActiveOnChange().
        $lines       = $record->transaction?->lines()->with('account')->get();
        $oldSource   = $lines?->firstWhere('notes', GeneralExchange::LINE_SOURCE);
        $oldAdmin    = $lines?->firstWhere('notes', GeneralExchange::LINE_ADMIN);
        $oldTransfer = $lines?->firstWhere('notes', GeneralExchange::LINE_TRANSFER);
        $oldDest     = $lines?->firstWhere('notes', GeneralExchange::LINE_DESTINATION);

        $accounts = FinancialAccountGuard::assertAccounts([
            'source' => [
                'account_id'      => $data['source_account_id'] ?? null,
                'account_type_id' => $data['source_account_type_id'] ?? null,
                'bank_type_id'    => $data['source_bank_type_id'] ?? null,
                'currency_id'     => $sourceCurrencyId,
                'field'           => 'source_account_id',
                'label'           => 'حساب المصدر',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldSource?->account_id, $data['source_account_id'] ?? null),
            ],
            'admin' => [
                'account_id'      => $data['admin_account_id'] ?? null,
                'account_type_id' => $data['admin_account_type_id'] ?? null,
                'bank_type_id'    => $data['admin_bank_type_id'] ?? null,
                'currency_id'     => $sourceCurrencyId,
                'field'           => 'admin_account_id',
                'label'           => 'حساب النسبة الإدارية',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldAdmin?->account_id, $data['admin_account_id'] ?? null),
            ],
            'transfer' => [
                'account_id'      => $data['transfer_account_id'] ?? null,
                'account_type_id' => $data['transfer_account_type_id'] ?? null,
                'bank_type_id'    => $data['transfer_bank_type_id'] ?? null,
                'currency_id'     => $sourceCurrencyId,
                'field'           => 'transfer_account_id',
                'label'           => 'حساب التحويل',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldTransfer?->account_id, $data['transfer_account_id'] ?? null),
            ],
            'destination' => [
                'account_id'      => $data['destination_account_id'] ?? null,
                'account_type_id' => $data['destination_account_type_id'] ?? null,
                'bank_type_id'    => $data['destination_bank_type_id'] ?? null,
                'currency_id'     => $disbCurrencyId,
                'field'           => 'destination_account_id',
                'label'           => 'حساب الوجهة',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldDest?->account_id, $data['destination_account_id'] ?? null),
            ],
        ]);

        $lines = $this->buildLines($data, $sourceCurrencyId, $disbCurrencyId, [
            'original' => $original,
            'admin'    => $adminAmount,
            'transfer' => $transferAmount,
            'final'    => $finalAmount,
            'fx'       => $fxRate,
        ]);

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
            $lines[0], $lines[1], $lines[2], $lines[3],
            $sourceCurrencyId, $disbCurrencyId
        );

        // Pre-change snapshot: taken after every guard has passed but before
        // the transaction opens, while the exchange row, its transaction and
        // its four old lines are all still pristine. The four old accounts
        // are read from the OLD lines, never from the submitted data.
        $audit = app(FinancialAuditRecorder::class);

        $before = $audit->snapshots()->generalExchange($record, [
            FinancialAccountRole::SOURCE => $oldSource?->account_id,
            FinancialAccountRole::ADMIN => $oldAdmin?->account_id,
            FinancialAccountRole::TRANSFER => $oldTransfer?->account_id,
            FinancialAccountRole::DESTINATION => $oldDest?->account_id,
        ]);

        return DB::transaction(function () use (
            $record, $data, $original, $adminPct, $transferPct, $fxRate,
            $adminAmount, $transferAmount, $finalAmount, $sourceCurrencyId, $disbCurrencyId,
            $oldSource, $oldAdmin, $oldTransfer, $oldDest, $accounts, $lines, $audit, $before
        ) {
            $transaction = $record->transaction;

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

            // STEP 3 - Replace the four transaction lines (hard delete: these are being
            // immediately recreated, so no soft-deleted duplicates should accumulate)
            // with the validated payload built above, unchanged.
            $transaction?->lines()->forceDelete();
            if ($transaction) {
                foreach ($lines as $line) {
                    TransactionLine::create($line + ['transaction_id' => $transaction->id]);
                }
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
            $accounts['source']->decrement('current_balance', $original);
            $accounts['admin']->increment('current_balance', $adminAmount);
            $accounts['transfer']->increment('current_balance', $transferAmount);
            $accounts['destination']->increment('current_balance', $finalAmount);

            // STEP 5b - Regenerate the Arabic line descriptions and the parent
            // transaction description from the final saved state
            if ($transaction) {
                app(TransactionLineDescriptionBuilder::class)->buildAndSaveForTransaction(
                    $transaction,
                    $this->buildGeneralExchangeLinePurposes()
                );

                app(TransactionDescriptionBuilder::class)->buildAndSave(
                    $transaction,
                    $this->buildGeneralExchangeSummary($data['source_account_id'], $data['destination_account_id'])
                );
            }

            // STEP 6 - Handle file replacement/removal
            $existing        = $record->attachments()->latest('id')->first();
            $newTempPath     = $data['exchange_image'] ?? null;
            $removeRequested = (bool) ($data['remove_current_attachment'] ?? false);

            if ($newTempPath) {
                // Store the replacement first; only soft-delete the previous
                // active attachment once the new one has succeeded.
                $this->storeAttachment($record, $newTempPath, $finalAmount);
                $existing?->delete();
            } elseif ($removeRequested && $existing) {
                $existing->delete();
            }

            // STEP 7 - One financial AuditEvent for this whole logical edit,
            // recording only the financial/business fields that actually
            // changed, with the old and new labels of any reassigned
            // account/currency/partner preserved.
            $audit->updated(
                FinancialAuditSubject::GeneralExchange,
                $record,
                $before,
                $audit->snapshots()->generalExchange($record, [
                    FinancialAccountRole::SOURCE => $data['source_account_id'],
                    FinancialAccountRole::ADMIN => $data['admin_account_id'],
                    FinancialAccountRole::TRANSFER => $data['transfer_account_id'],
                    FinancialAccountRole::DESTINATION => $data['destination_account_id'],
                ]),
            );

            // STEP 8 - Success
            Notification::make()
                ->title('تم تعديل التحويل بنجاح')
                ->success()
                ->send();

            return $record;
        });
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
     * Build the exact four-line TransactionLine payload — 1 credit (source) +
     * 3 debit (admin, transfer, destination), in this fixed order — in
     * memory, without transaction_id, so it can be validated by
     * FinancialTransactionBalanceGuard before DB::transaction() opens. The
     * transaction_id is merged in at insert time; nothing else is
     * recalculated. Each line is tagged via notes for later identification.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildLines(array $data, int $sourceCurrencyId, int $disbCurrencyId, array $amounts): array
    {
        $uid = auth()->id();

        return [
            [
                'account_id' => $data['source_account_id'],
                'currency_id' => $sourceCurrencyId, 'amount_currency' => $amounts['original'], 'fx_rate' => 1,
                'debit_base' => 0, 'credit_base' => $amounts['original'],
                'notes' => GeneralExchange::LINE_SOURCE,
                'line_role' => TransactionLineRole::Source->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
            [
                'account_id' => $data['admin_account_id'],
                'currency_id' => $sourceCurrencyId, 'amount_currency' => $amounts['admin'], 'fx_rate' => 1,
                'debit_base' => $amounts['admin'], 'credit_base' => 0,
                'notes' => GeneralExchange::LINE_ADMIN,
                'line_role' => TransactionLineRole::AdministrativeDeduction->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
            [
                'account_id' => $data['transfer_account_id'],
                'currency_id' => $sourceCurrencyId, 'amount_currency' => $amounts['transfer'], 'fx_rate' => 1,
                'debit_base' => $amounts['transfer'], 'credit_base' => 0,
                'notes' => GeneralExchange::LINE_TRANSFER,
                'line_role' => TransactionLineRole::TransferFee->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
            [
                'account_id' => $data['destination_account_id'],
                'currency_id' => $disbCurrencyId, 'amount_currency' => $amounts['final'], 'fx_rate' => $amounts['fx'],
                'debit_base' => $amounts['final'], 'credit_base' => 0,
                'notes' => GeneralExchange::LINE_DESTINATION,
                'line_role' => TransactionLineRole::Destination->value,
                'created_by' => $uid, 'updated_by' => $uid,
            ],
        ];
    }

    protected function storeAttachment(GeneralExchange $exchange, string $tempPath, float $finalAmount): void
    {
        app(AttachmentUploadService::class)->store(
            parent: $exchange,
            tempPath: $tempPath,
            directory: 'general-exchanges',
            prefix: 'ext',
            date: $exchange->date ?? now(),
            amount: $finalAmount,
        );
    }
}
