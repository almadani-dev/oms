<?php

namespace App\Filament\Resources\GeneralExchanges\Pages;

use App\Filament\Concerns\GeneratesSequentialTransactionNumbers;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Concerns\ReportsFinancialValidationFailures;
use App\Enums\TransactionLineRole;
use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use App\Filament\Resources\GeneralExchanges\Schemas\GeneralExchangeForm;
use App\Models\Account;
use App\Models\GeneralExchange;
use App\Models\Transaction;
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
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateGeneralExchange extends CreateRecord
{
    use GeneratesSequentialTransactionNumbers;
    use RedirectsToResourceView;
    use ReportsFinancialValidationFailures;

    protected static string $resource = GeneralExchangeResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withVisibleFinancialValidation(function () use ($data): Model {
            // Derive every amount on the server from the trusted inputs.
            $original    = (float) $data['original_amount'];
            $adminPct    = (float) ($data['administrative_percentage'] ?? 0);
            $transferPct = (float) ($data['transfer_percentage'] ?? 0);
            $fxRate      = (float) ($data['fx_rate'] ?? 1);

            [$adminAmount, $transferAmount, $afterDeduct, $finalAmount] = GeneralExchangeForm::deriveAmounts($original, $adminPct, $transferPct, $fxRate);

            FinancialAmountGuard::assertDisbursementInputs($original, $adminPct, $transferPct, $fxRate, $afterDeduct, $finalAmount);
            FinancialAmountGuard::assertDeductionsAreRecordable($adminPct, $adminAmount, $transferPct, $transferAmount);

            // A 0% deduction is a valid business case: it has no account, no
            // transaction line, no balance movement and no audit role. After
            // assertDeductionsAreRecordable() above, "amount > 0" and
            // "percentage > 0" are equivalent for both deductions, so this
            // single pair of flags drives account validation, the line
            // payload, the balance mutations and the audit role map
            // consistently - they can never disagree about which optional
            // roles exist.
            $hasAdmin    = $adminAmount > 0;
            $hasTransfer = $transferAmount > 0;

            $sourceCurrencyId = (int) $data['source_currency_id'];
            $disbCurrencyId   = (int) $data['disbursement_currency_id'];

            $accountSpecs = [
                'source' => [
                    'account_id'      => $data['source_account_id'] ?? null,
                    'account_type_id' => $data['source_account_type_id'] ?? null,
                    'bank_type_id'    => $data['source_bank_type_id'] ?? null,
                    'currency_id'     => $sourceCurrencyId,
                    'field'           => 'source_account_id',
                    'label'           => 'حساب المصدر',
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
                ];
            }

            $accountSpecs['destination'] = [
                'account_id'      => $data['destination_account_id'] ?? null,
                'account_type_id' => $data['destination_account_type_id'] ?? null,
                'bank_type_id'    => $data['destination_bank_type_id'] ?? null,
                'currency_id'     => $disbCurrencyId,
                'field'           => 'destination_account_id',
                'label'           => 'حساب الوجهة',
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

            return $this->retryOnTransactionNumberCollision(fn () => DB::transaction(function () use (
                $data, $original, $adminPct, $transferPct, $fxRate,
                $adminAmount, $transferAmount, $finalAmount, $sourceCurrencyId, $disbCurrencyId,
                $accounts, $lines, $hasAdmin, $hasTransfer
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

                // STEP 2 - Insert the two-to-four validated transaction lines
                // unchanged, exactly as built and validated above. The payload
                // is keyed by role, never by position - a deduction line is
                // ABSENT, not empty, when its percentage is 0.
                foreach ($lines as $line) {
                    TransactionLine::create($line + ['transaction_id' => $transaction->id]);
                }

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

                // STEP 4 - Update account balances. Source and destination always
                // move; a deduction account moves only when that deduction
                // exists, so a 0% role never receives a no-op increment.
                $accounts['source']->decrement('current_balance', $original);

                if ($hasAdmin) {
                    $accounts['admin']->increment('current_balance', $adminAmount);
                }

                if ($hasTransfer) {
                    $accounts['transfer']->increment('current_balance', $transferAmount);
                }

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

                // STEP 6 - One financial AuditEvent for this whole logical
                // exchange (exchange row + transaction + two-to-four lines +
                // the matching balance changes), inside this same transaction
                // and REQUIRED. Recorded before the notification so a rollback
                // can never be reported to the user as a success.
                $exchange->setRelation('transaction', $transaction);

                $audit = app(FinancialAuditRecorder::class);

                $audit->created(
                    FinancialAuditSubject::GeneralExchange,
                    $exchange,
                    $audit->snapshots()->generalExchange(
                        $exchange,
                        $this->buildAuditAccountRoles($data, $hasAdmin, $hasTransfer),
                    ),
                );

                // STEP 7 - Success notification
                Notification::make()
                    ->title('تم التحويل بنجاح')
                    ->body('رقم المعاملة: ' . $transactionNumber)
                    ->success()
                    ->send();

                return $exchange;
            }));
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
