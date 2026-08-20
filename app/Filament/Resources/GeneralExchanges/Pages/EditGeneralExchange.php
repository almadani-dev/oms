<?php

namespace App\Filament\Resources\GeneralExchanges\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Concerns\ReportsFinancialValidationFailures;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use App\Filament\Resources\GeneralExchanges\Schemas\GeneralExchangeForm;
use App\Filament\Resources\GeneralExchanges\Tables\GeneralExchangesTable;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\GeneralExchange;
use App\Models\TransactionLine;
use App\Services\Attachments\AttachmentUploadService;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
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
    use ReportsFinancialValidationFailures;

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
        return $this->withVisibleFinancialValidation(function () use ($record, $data): Model {
            /** @var GeneralExchange $record */
            $original    = (float) $data['original_amount'];
            $adminPct    = (float) ($data['administrative_percentage'] ?? 0);
            $transferPct = (float) ($data['transfer_percentage'] ?? 0);
            $fxRate      = (float) ($data['fx_rate'] ?? 1);

            [$adminAmount, $transferAmount, $afterDeduct, $finalAmount] = GeneralExchangeForm::deriveAmounts($original, $adminPct, $transferPct, $fxRate);

            FinancialAmountGuard::assertDisbursementInputs($original, $adminPct, $transferPct, $fxRate, $afterDeduct, $finalAmount);
            FinancialAmountGuard::assertDeductionsAreRecordable($adminPct, $adminAmount, $transferPct, $transferAmount);

            // Which optional deduction roles the SAVED record will have. The
            // old record's own roles are independent of these (see $oldAdmin /
            // $oldTransfer below) - that asymmetry is the whole point: an edit
            // may add a deduction that did not exist, or remove one that did.
            $hasAdmin    = $adminAmount > 0;
            $hasTransfer = $transferAmount > 0;

            $sourceCurrencyId = (int) $data['source_currency_id'];
            $disbCurrencyId   = (int) $data['disbursement_currency_id'];

            // Old lines fetched before the account guard so an unchanged historical
            // account may remain inactive; see FinancialAccountGuard::requireActiveOnChange().
            // Any of them may legitimately be null: a record saved with a 0%
            // deduction never had that line at all.
            $oldLines    = $record->transaction?->lines()->with('account')->get();
            $oldSource   = $oldLines?->firstWhere('notes', GeneralExchange::LINE_SOURCE);
            $oldAdmin    = $oldLines?->firstWhere('notes', GeneralExchange::LINE_ADMIN);
            $oldTransfer = $oldLines?->firstWhere('notes', GeneralExchange::LINE_TRANSFER);
            $oldDest     = $oldLines?->firstWhere('notes', GeneralExchange::LINE_DESTINATION);

            $accountSpecs = [
                'source' => [
                    'account_id'      => $data['source_account_id'] ?? null,
                    'account_type_id' => $data['source_account_type_id'] ?? null,
                    'bank_type_id'    => $data['source_bank_type_id'] ?? null,
                    'currency_id'     => $sourceCurrencyId,
                    'field'           => 'source_account_id',
                    'label'           => 'حساب المصدر',
                    'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldSource?->account_id, $data['source_account_id'] ?? null),
                ],
            ];

            if ($hasAdmin) {
                $accountSpecs['admin'] = [
                    'account_id'      => $data['admin_account_id'] ?? null,
                    'account_type_id' => $data['admin_account_type_id'] ?? null,
                    'bank_type_id'    => $data['admin_bank_type_id'] ?? null,
                    'currency_id'     => $sourceCurrencyId,
                    'field'           => 'admin_account_id',
                    'label'           => 'حساب النسبة الإدارية',
                    'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldAdmin?->account_id, $data['admin_account_id'] ?? null),
                ];
            }

            if ($hasTransfer) {
                $accountSpecs['transfer'] = [
                    'account_id'      => $data['transfer_account_id'] ?? null,
                    'account_type_id' => $data['transfer_account_type_id'] ?? null,
                    'bank_type_id'    => $data['transfer_bank_type_id'] ?? null,
                    'currency_id'     => $sourceCurrencyId,
                    'field'           => 'transfer_account_id',
                    'label'           => 'حساب التحويل',
                    'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldTransfer?->account_id, $data['transfer_account_id'] ?? null),
                ];
            }

            $accountSpecs['destination'] = [
                'account_id'      => $data['destination_account_id'] ?? null,
                'account_type_id' => $data['destination_account_type_id'] ?? null,
                'bank_type_id'    => $data['destination_bank_type_id'] ?? null,
                'currency_id'     => $disbCurrencyId,
                'field'           => 'destination_account_id',
                'label'           => 'حساب الوجهة',
                'require_active'  => FinancialAccountGuard::requireActiveOnChange($oldDest?->account_id, $data['destination_account_id'] ?? null),
            ];

            $accounts = FinancialAccountGuard::assertAccounts($accountSpecs);

            $lines = $this->buildLines($data, $sourceCurrencyId, $disbCurrencyId, [
                'original' => $original,
                'admin'    => $adminAmount,
                'transfer' => $transferAmount,
                'final'    => $finalAmount,
                'fx'       => $fxRate,
            ]);

            FinancialTransactionBalanceGuard::assertValidLinePayload(array_values($lines));
            FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
                $lines['source'],
                $lines['admin'] ?? null,
                $lines['transfer'] ?? null,
                $lines['destination'],
                $sourceCurrencyId,
                $disbCurrencyId
            );

            // Pre-change snapshot: taken after every guard has passed but before
            // the transaction opens, while the exchange row, its transaction and
            // its old lines are all still pristine. The old accounts are read
            // from the OLD lines, never from the submitted data, and a role
            // whose old line does not exist is omitted entirely.
            $audit = app(FinancialAuditRecorder::class);

            $before = $audit->snapshots()->generalExchange(
                $record,
                $this->buildAuditAccountRolesFromLines($oldSource, $oldAdmin, $oldTransfer, $oldDest),
            );

            return DB::transaction(function () use (
                $record, $data, $original, $adminPct, $transferPct, $fxRate,
                $adminAmount, $transferAmount, $finalAmount, $sourceCurrencyId, $disbCurrencyId,
                $oldSource, $oldAdmin, $oldTransfer, $oldDest, $accounts, $lines, $audit, $before, $hasAdmin, $hasTransfer
            ) {
                $transaction = $record->transaction;

                // STEP 1 - Reverse all OLD account balances. Every reversal is
                // null-safe because the record being edited may have been saved
                // with a 0% deduction and so never had that line or account.
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

                // STEP 3 - Replace the transaction lines (hard delete: these are being
                // immediately recreated, so no soft-deleted duplicates should accumulate)
                // with the validated payload built above, unchanged. Because the
                // whole set is dropped and rebuilt, a deduction line that no
                // longer applies simply is not recreated - there is no stale row
                // to clean up separately.
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

                // STEP 5 - Apply the NEW account balances. A deduction account is
                // touched only when that deduction survives into the saved
                // record, so an edit down to 0% reverses the old movement in
                // STEP 1 and applies nothing here.
                $accounts['source']->decrement('current_balance', $original);

                if ($hasAdmin) {
                    $accounts['admin']->increment('current_balance', $adminAmount);
                }

                if ($hasTransfer) {
                    $accounts['transfer']->increment('current_balance', $transferAmount);
                }

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
                    // active attachment once the new one has succeeded. Passing
                    // $existing makes this ONE attachment.replaced event carrying
                    // both files' metadata - never an uploaded plus a deleted -
                    // so the soft delete below adds no second event.
                    $this->storeAttachment($record, $newTempPath, $finalAmount, $existing);
                    $existing?->delete();
                } elseif ($removeRequested && $existing) {
                    // Recorded BEFORE the soft delete, while the metadata being
                    // preserved is still the metadata of an active attachment.
                    app(AttachmentAuditRecorder::class)->deleted($existing);
                    $existing->delete();
                }

                // STEP 7 - One financial AuditEvent for this whole logical edit,
                // recording only the financial/business fields that actually
                // changed, with the old and new labels of any reassigned
                // account/currency/partner preserved. Dropping a deduction to 0%
                // omits its role from the new snapshot, which is exactly what
                // makes FinancialAuditDiff report that account as removed.
                $audit->updated(
                    FinancialAuditSubject::GeneralExchange,
                    $record,
                    $before,
                    $audit->snapshots()->generalExchange(
                        $record,
                        $this->buildAuditAccountRoles($data, $hasAdmin, $hasTransfer),
                    ),
                );

                // STEP 8 - Success
                Notification::make()
                    ->title('تم تعديل التحويل بنجاح')
                    ->success()
                    ->send();

                return $record;
            });
        });
    }

    /**
     * The account role => account id map handed to the audit snapshot.
     *
     * A deduction whose percentage is 0 has no account at all, so its role is
     * OMITTED rather than passed as null: the snapshot of an exchange that
     * never had an administrative account must not carry an
     * `admin_account_id` key. On an edit from a positive percentage down to
     * 0 that omission is exactly what makes FinancialAuditDiff report the
     * account as removed, with its old label preserved on the old side.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function buildAuditAccountRoles(array $data, bool $hasAdmin, bool $hasTransfer): array
    {
        $roles = [
            FinancialAccountRole::SOURCE      => $data['source_account_id'] ?? null,
            FinancialAccountRole::DESTINATION => $data['destination_account_id'] ?? null,
        ];

        if ($hasAdmin) {
            $roles[FinancialAccountRole::ADMIN] = $data['admin_account_id'] ?? null;
        }

        if ($hasTransfer) {
            $roles[FinancialAccountRole::TRANSFER] = $data['transfer_account_id'] ?? null;
        }

        return $roles;
    }

    /**
     * The same map for the PRE-edit state, read from the record's own old
     * transaction lines rather than from anything submitted. A deduction line
     * that does not exist contributes no role at all, so a record saved at 0%
     * carries no `admin_account_id` on the old side either.
     *
     * @return array<string, mixed>
     */
    protected function buildAuditAccountRolesFromLines(
        ?TransactionLine $source,
        ?TransactionLine $admin,
        ?TransactionLine $transfer,
        ?TransactionLine $destination,
    ): array {
        $roles = [
            FinancialAccountRole::SOURCE      => $source?->account_id,
            FinancialAccountRole::DESTINATION => $destination?->account_id,
        ];

        if ($admin) {
            $roles[FinancialAccountRole::ADMIN] = $admin->account_id;
        }

        if ($transfer) {
            $roles[FinancialAccountRole::TRANSFER] = $transfer->account_id;
        }

        return $roles;
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
     * Build the TransactionLine payload in memory, KEYED BY ROLE
     * ('source' / 'admin' / 'transfer' / 'destination') and without
     * transaction_id, so it can be validated by
     * FinancialTransactionBalanceGuard before DB::transaction() opens. The
     * transaction_id is merged in at insert time; nothing else is
     * recalculated. Each line is tagged via notes for later identification
     * on edit / view / delete.
     *
     * The two deduction lines are OPTIONAL. A 0% administrative or transfer
     * percentage yields a 0.00 amount, and a line carrying
     * debit_base = credit_base = amount_currency = 0 is meaningless
     * accounting that assertValidLinePayload() rejects outright and must keep
     * rejecting. So the line is simply not built, giving 4, 3 or 2 lines:
     *
     *   admin > 0, transfer > 0  ->  source + admin + transfer + destination
     *   admin = 0, transfer > 0  ->  source + transfer + destination
     *   admin > 0, transfer = 0  ->  source + admin + destination
     *   admin = 0, transfer = 0  ->  source + destination
     *
     * Keying by role rather than by position is what makes a variable length
     * safe: every consumer asks for $lines['source'] or $lines['admin'] ?? null,
     * so a shorter payload can never silently shift the destination line into
     * the administrative slot the way $lines[1] once could.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function buildLines(array $data, int $sourceCurrencyId, int $disbCurrencyId, array $amounts): array
    {
        $uid = auth()->id();

        $lines = [];

        // دائن - المصدر (بعملة المصدر) - always present
        $lines['source'] = [
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
        ];

        // مدين - النسبة الإدارية (بعملة المصدر) - only when the deduction exists
        if ($amounts['admin'] > 0) {
            $lines['admin'] = [
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
            ];
        }

        // مدين - التحويل (بعملة المصدر) - only when the deduction exists
        if ($amounts['transfer'] > 0) {
            $lines['transfer'] = [
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
            ];
        }

        // مدين - الوجهة (بعملة الصرف) - always present
        $lines['destination'] = [
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
        ];

        return $lines;
    }

    protected function storeAttachment(GeneralExchange $exchange, string $tempPath, float $finalAmount, ?Attachment $replacing = null): void
    {
        app(AttachmentUploadService::class)->store(
            parent: $exchange,
            tempPath: $tempPath,
            directory: 'general-exchanges',
            prefix: 'ext',
            date: $exchange->date ?? now(),
            amount: $finalAmount,
            replacing: $replacing,
        );
    }
}
