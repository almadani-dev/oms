<?php

namespace App\Services\Audit\Financial;

use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Builds the bounded accountability snapshot for one financial workflow
 * record (OMS Task 9B.3).
 *
 * What a snapshot is: a flat map of small scalars — identifiers, decimal
 * strings and bounded labels. What it is NEVER: a TransactionLine array, an
 * Eloquent model, a relation, a collection, a file, or a floating-point
 * money value. The double-entry detail already lives in `transactions` /
 * `transaction_lines`; the snapshot carries the transaction IDENTIFIERS so
 * the ledger can be followed from the event without duplicating it.
 *
 * Accounts are addressed by the ROLE they play in the workflow — debit,
 * credit, source, destination, admin, transfer, beneficiary — which is what
 * the `<role>_account_id` / `<role>_account_label` key pairs encode. The
 * caller supplies the role => account id map because only the caller knows
 * which side it is describing: on an edit, the pre-change snapshot must be
 * labelled from the accounts the OLD transaction lines pointed at, never
 * from the newly submitted ones.
 *
 * Every builder is side-effect free and safe to call twice (before and
 * after a mutation) for the same record.
 */
final class FinancialAuditSnapshotter
{
    public function __construct(private readonly FinancialAuditLabeller $labeller) {}

    public function labeller(): FinancialAuditLabeller
    {
        return $this->labeller;
    }

    /**
     * المبالغ المستلمة — ProjectCostReceiptResource / App\Models\ProjectCostReceipt.
     *
     * @param  array{debit?: mixed, credit?: mixed}  $accounts
     * @return array<string, mixed>
     */
    public function projectCostReceipt(ProjectCostReceipt $receipt, array $accounts): array
    {
        $projectCostId = FinancialAuditValue::id($receipt->project_cost_id);

        return $this->finalize(array_merge(
            $this->context(FinancialAuditSubject::ProjectCostReceipt, $receipt->transaction),
            [
                'project_id' => $this->projectIdOfCost($projectCostId),
                'project_cost_id' => $projectCostId,
                'currency_id' => FinancialAuditValue::id($receipt->currency_id),
                'amount' => FinancialAuditValue::money($receipt->amount),
                'date' => FinancialAuditValue::date($receipt->date),
                'notes' => FinancialAuditValue::text($receipt->notes),
            ],
            $this->transactionMeta($receipt->transaction),
            $this->accountFields($accounts),
        ));
    }

    /**
     * صرف مبلغ المشروع — ProjectCostBudgetsPaymentResource (slug
     * project-cost-budgets-disbursements) / App\Models\ProjectCostBudget.
     *
     * Has no `date` column of its own: the disbursement's business date is
     * its transaction's `transaction_time`.
     *
     * @param  array{source?: mixed, admin?: mixed, transfer?: mixed, destination?: mixed}  $accounts
     * @return array<string, mixed>
     */
    public function projectDisbursement(ProjectCostBudget $budget, array $accounts): array
    {
        $projectCostId = FinancialAuditValue::id($budget->project_cost_id);

        return $this->finalize(array_merge(
            $this->context(FinancialAuditSubject::ProjectDisbursement, $budget->transaction),
            [
                'project_id' => $this->projectIdOfCost($projectCostId),
                'project_cost_id' => $projectCostId,
                'source_currency_id' => FinancialAuditValue::id($budget->source_currency_id),
                'disbursement_currency_id' => FinancialAuditValue::id($budget->disbursement_currency_id),
                'original_amount' => FinancialAuditValue::money($budget->original_amount),
                'administrative_percentage' => FinancialAuditValue::percentage($budget->administrative_percentage),
                'transfer_percentage' => FinancialAuditValue::percentage($budget->transfer_percentage),
                'amount_after_deductions' => FinancialAuditValue::money($budget->amount_after_deductions),
                'fx_rate' => FinancialAuditValue::rate($budget->fx_rate),
                'final_amount' => FinancialAuditValue::money($budget->final_amount),
                'date' => FinancialAuditValue::date($budget->transaction?->transaction_time),
                'notes' => FinancialAuditValue::text($budget->notes),
            ],
            $this->transactionMeta($budget->transaction),
            $this->accountFields($accounts),
        ));
    }

    /**
     * صرف مبالغ التنفيذ — ExecutionPaymentResource (slug execution-payments)
     * / App\Models\ProjectCostBudgetsPayment.
     *
     * @param  array{beneficiary?: mixed, credit?: mixed}  $accounts
     * @return array<string, mixed>
     */
    public function executionPayment(ProjectCostBudgetsPayment $payment, array $accounts): array
    {
        $budgetId = FinancialAuditValue::id($payment->project_cost_budget_id);
        $projectCostId = $this->projectCostIdOfBudget($budgetId);

        return $this->finalize(array_merge(
            $this->context(FinancialAuditSubject::ExecutionPayment, $payment->transaction),
            [
                'project_cost_budget_id' => $budgetId,
                'project_id' => $this->projectIdOfCost($projectCostId),
                'project_cost_id' => $projectCostId,
                'currency_id' => FinancialAuditValue::id($payment->currency_id),
                'amount' => FinancialAuditValue::money($payment->amount),
                'date' => FinancialAuditValue::date($payment->date),
                'notes' => FinancialAuditValue::text($payment->notes),
            ],
            $this->transactionMeta($payment->transaction),
            $this->accountFields($accounts),
        ));
    }

    /**
     * المصروفات العامة — GeneralExpenseResource / App\Models\GeneralExpense.
     *
     * @param  array{debit?: mixed, credit?: mixed}  $accounts
     * @return array<string, mixed>
     */
    public function generalExpense(GeneralExpense $expense, array $accounts): array
    {
        return $this->finalize(array_merge(
            $this->context(FinancialAuditSubject::GeneralExpense, $expense->transaction),
            [
                'currency_id' => FinancialAuditValue::id($expense->currency_id),
                'amount' => FinancialAuditValue::money($expense->amount),
                'date' => FinancialAuditValue::date($expense->date),
                'description' => FinancialAuditValue::text($expense->description),
                'notes' => FinancialAuditValue::text($expense->notes),
            ],
            $this->transactionMeta($expense->transaction, $expense->partner_id),
            $this->accountFields($accounts),
        ));
    }

    /**
     * التحويلات العامة — GeneralExchangeResource / App\Models\GeneralExchange.
     *
     * @param  array{source?: mixed, admin?: mixed, transfer?: mixed, destination?: mixed}  $accounts
     * @return array<string, mixed>
     */
    public function generalExchange(GeneralExchange $exchange, array $accounts): array
    {
        return $this->finalize(array_merge(
            $this->context(FinancialAuditSubject::GeneralExchange, $exchange->transaction),
            [
                'source_currency_id' => FinancialAuditValue::id($exchange->source_currency_id),
                'disbursement_currency_id' => FinancialAuditValue::id($exchange->disbursement_currency_id),
                'original_amount' => FinancialAuditValue::money($exchange->original_amount),
                'administrative_percentage' => FinancialAuditValue::percentage($exchange->administrative_percentage),
                'transfer_percentage' => FinancialAuditValue::percentage($exchange->transfer_percentage),
                'fx_rate' => FinancialAuditValue::rate($exchange->fx_rate),
                'final_amount' => FinancialAuditValue::money($exchange->final_amount),
                'date' => FinancialAuditValue::date($exchange->date),
                'notes' => FinancialAuditValue::text($exchange->notes),
            ],
            $this->transactionMeta($exchange->transaction, $exchange->partner_id),
            $this->accountFields($accounts),
        ));
    }

    /**
     * The identity/context every financial event carries regardless of what
     * changed: which workflow this was, and which ledger transaction it
     * produced. FinancialAuditDiff treats these as context rather than as
     * business fields, so they appear on both sides of an update without
     * ever being reported as "changed".
     *
     * @return array<string, mixed>
     */
    private function context(FinancialAuditSubject $subject, ?Transaction $transaction): array
    {
        return [
            'operation_type' => $subject->value,
            'transaction_id' => FinancialAuditValue::id($transaction?->getKey()),
            'transaction_number' => FinancialAuditValue::text($transaction?->transaction_number, 64),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionMeta(?Transaction $transaction, mixed $partnerIdOverride = null): array
    {
        return [
            'fiscal_year_id' => FinancialAuditValue::id($transaction?->fiscal_year_id),
            'transaction_type_id' => FinancialAuditValue::id($transaction?->transaction_type_id),
            'partner_id' => FinancialAuditValue::id($partnerIdOverride ?? $transaction?->partner_id),
        ];
    }

    /**
     * Turns the caller's role => account id map into `<role>_account_id`
     * key/value pairs. Roles are the fixed workflow vocabulary — debit,
     * credit, source, destination, admin, transfer, beneficiary — never a
     * free-form string, so an unknown role fails closed rather than silently
     * inventing a payload key.
     *
     * @param  array<string, mixed>  $accounts
     * @return array<string, mixed>
     */
    private function accountFields(array $accounts): array
    {
        $fields = [];

        foreach ($accounts as $role => $accountId) {
            if (! in_array($role, FinancialAccountRole::ALL, true)) {
                throw new InvalidArgumentException(sprintf('Unknown financial account role [%s].', (string) $role));
            }

            $fields[$role.'_account_id'] = FinancialAuditValue::id($accountId);
        }

        return $fields;
    }

    /**
     * Attaches one bounded label per labelled foreign key present in the
     * row, then drops keys that resolved to nothing at all. The foreign key
     * scalar itself always stays — a label is an addition, never a
     * replacement.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finalize(array $row): array
    {
        $labelled = [];

        foreach ($row as $key => $value) {
            $labelled[$key] = $value;

            $label = $this->labelFor($key, $value);

            if ($label !== null) {
                $labelled[FinancialAuditFieldNames::satelliteKey($key)] = $label;
            }
        }

        return array_filter($labelled, static fn (mixed $value): bool => $value !== null);
    }

    private function labelFor(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_ends_with($key, '_account_id')) {
            return $this->labeller->account($value);
        }

        return match ($key) {
            'project_id' => $this->labeller->project($value),
            'project_cost_id' => $this->labeller->projectCost($value),
            'project_cost_budget_id' => $this->labeller->projectCostBudget($value),
            'partner_id' => $this->labeller->partner($value),
            'currency_id', 'source_currency_id', 'disbursement_currency_id' => $this->labeller->currencyCode($value),
            default => null,
        };
    }

    private function projectIdOfCost(?int $projectCostId): ?int
    {
        if ($projectCostId === null) {
            return null;
        }

        return FinancialAuditValue::id(
            ProjectCost::withTrashed()->select(['id', 'project_id'])->find($projectCostId)?->project_id
        );
    }

    private function projectCostIdOfBudget(?int $budgetId): ?int
    {
        if ($budgetId === null) {
            return null;
        }

        return FinancialAuditValue::id(
            ProjectCostBudget::withTrashed()->select(['id', 'project_cost_id'])->find($budgetId)?->project_cost_id
        );
    }

    /**
     * Only used by the recorder to derive a subject label; kept here so the
     * payload shape and the label stay defined in one place.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function subjectLabel(array $snapshot, Model $record): ?string
    {
        $number = $snapshot['transaction_number'] ?? null;

        if (is_string($number) && $number !== '') {
            return mb_substr($number, 0, FinancialAuditLabeller::MAX_LABEL_LENGTH);
        }

        $key = $record->getKey();

        return $key === null ? null : '#'.$key;
    }
}
