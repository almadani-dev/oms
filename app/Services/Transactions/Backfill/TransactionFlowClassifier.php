<?php

namespace App\Services\Transactions\Backfill;

use App\Enums\TransactionLineRole;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Collection;

/**
 * Deterministically classifies a historical Transaction into one of the six
 * approved financial flows and resolves the canonical role/purpose/summary
 * data for it, mirroring exactly the same role and wording rules the six
 * live write flows apply at creation time.
 *
 * Classification priority (never guessed):
 *   1. A direct transaction_id foreign key on the flow's authoritative
 *      domain record (receipt, disbursement, execution payment, general
 *      expense, general exchange).
 *   2. The transaction_type name for the opening-balance flow, which has no
 *      domain record of its own.
 * Existing transaction_lines.notes LINE_* tags are only ever used as
 * supporting evidence to map lines to roles once the flow is already known
 * from (1) or (2) above — never to identify the flow itself.
 */
class TransactionFlowClassifier
{
    public function __construct(private readonly ?int $openingBalanceTransactionTypeId)
    {
    }

    public function classify(
        Transaction $transaction,
        ?ProjectCostReceipt $receipt,
        ?ProjectCostBudget $disbursement,
        ?ProjectCostBudgetsPayment $executionPayment,
        ?GeneralExpense $generalExpense,
        ?GeneralExchange $generalExchange,
    ): ClassifiedTransaction {
        $linked = array_filter([
            'receipt'            => $receipt,
            'disbursement'       => $disbursement,
            'execution_payment'  => $executionPayment,
            'general_expense'    => $generalExpense,
            'general_exchange'   => $generalExchange,
        ]);

        if (count($linked) > 1) {
            throw new TransactionClassificationException(
                'transaction is linked to more than one domain flow: ' . implode(', ', array_keys($linked))
            );
        }

        if (count($linked) === 1) {
            return match (array_key_first($linked)) {
                'receipt'           => $this->classifyReceipt($transaction, $receipt),
                'disbursement'      => $this->classifyDisbursement($transaction, $disbursement),
                'execution_payment' => $this->classifyExecutionPayment($transaction, $executionPayment),
                'general_expense'   => $this->classifyGeneralExpense($transaction, $generalExpense),
                'general_exchange'  => $this->classifyGeneralExchange($transaction, $generalExchange),
            };
        }

        if (
            $this->openingBalanceTransactionTypeId !== null
            && $transaction->transaction_type_id === $this->openingBalanceTransactionTypeId
        ) {
            return $this->classifyOpeningBalance($transaction);
        }

        throw new TransactionClassificationException('no deterministic classification found for this transaction');
    }

    protected function classifyReceipt(Transaction $transaction, ProjectCostReceipt $receipt): ClassifiedTransaction
    {
        [$debitLine, $creditLine] = $this->resolveSingleDebitCreditPair($transaction->lines, 'receipt');

        $projectName = $receipt->projectCost?->project?->name;

        $purposes = [
            TransactionLineRole::FundingSource->value => $projectName
                ? "إثبات تمويل مشروع {$projectName}"
                : 'إثبات تمويل المشروع',
            TransactionLineRole::ReceiptDestination->value => $projectName
                ? "إيداع المبلغ المستلم لمشروع {$projectName}"
                : 'إيداع المبلغ المستلم للمشروع',
        ];

        $partnerName = $transaction->partner?->name;
        $summary     = match (true) {
            $partnerName && $projectName   => "استلام مبلغ من {$partnerName} لتمويل مشروع {$projectName}",
            ! $partnerName && $projectName => "استلام مبلغ لتمويل مشروع {$projectName}",
            $partnerName && ! $projectName => "استلام مبلغ من {$partnerName}",
            default                        => 'تسجيل مبلغ مستلم',
        };

        return new ClassifiedTransaction('receipt', [
            $debitLine->id  => TransactionLineRole::ReceiptDestination,
            $creditLine->id => TransactionLineRole::FundingSource,
        ], $purposes, $summary);
    }

    protected function classifyExecutionPayment(Transaction $transaction, ProjectCostBudgetsPayment $payment): ClassifiedTransaction
    {
        [$debitLine, $creditLine] = $this->resolveSingleDebitCreditPair($transaction->lines, 'execution_payment');

        $projectName = $payment->projectCostBudget?->projectCost?->project?->name;

        $purposes = [
            TransactionLineRole::Beneficiary->value => $projectName
                ? "إثبات مبلغ التنفيذ ضمن مشروع {$projectName}"
                : 'إثبات مبلغ التنفيذ ضمن المشروع',
            TransactionLineRole::ExecutionSource->value => 'تخفيض رصيد مبلغ المشروع المتاح للتنفيذ',
        ];

        $summary = $projectName
            ? "صرف مبلغ تنفيذ ضمن مشروع {$projectName}"
            : 'صرف مبلغ تنفيذ مشروع';

        return new ClassifiedTransaction('execution_payment', [
            $debitLine->id  => TransactionLineRole::Beneficiary,
            $creditLine->id => TransactionLineRole::ExecutionSource,
        ], $purposes, $summary);
    }

    protected function classifyGeneralExpense(Transaction $transaction, GeneralExpense $expense): ClassifiedTransaction
    {
        [$debitLine, $creditLine] = $this->resolveSingleDebitCreditPair($transaction->lines, 'general_expense');

        $purpose = trim((string) ($expense->description ?? ''));

        $purposes = [
            TransactionLineRole::Source->value => $purpose !== ''
                ? "دفع مصروف {$purpose}"
                : 'دفع مصروف عام',
            TransactionLineRole::Expense->value => $purpose !== ''
                ? "إثبات مصروف {$purpose}"
                : 'إثبات مصروف عام',
        ];

        $summary = $purpose !== ''
            ? "تسجيل مصروف عام: {$purpose}"
            : 'تسجيل مصروف عام';

        return new ClassifiedTransaction('general_expense', [
            $debitLine->id  => TransactionLineRole::Expense,
            $creditLine->id => TransactionLineRole::Source,
        ], $purposes, $summary);
    }

    protected function classifyDisbursement(Transaction $transaction, ProjectCostBudget $budget): ClassifiedTransaction
    {
        $roles = $this->resolveRolesByNotesTag($transaction->lines, 'disbursement', [
            ProjectCostBudget::LINE_SOURCE      => TransactionLineRole::Source,
            ProjectCostBudget::LINE_ADMIN       => TransactionLineRole::AdministrativeDeduction,
            ProjectCostBudget::LINE_TRANSFER    => TransactionLineRole::TransferFee,
            ProjectCostBudget::LINE_DESTINATION => TransactionLineRole::Destination,
        ]);

        $projectName = $budget->projectCost?->project?->name;
        $summary     = $projectName
            ? "صرف مبلغ لمشروع {$projectName} بعد الخصومات والتحويل"
            : 'صرف مبلغ مشروع بعد الخصومات والتحويل';

        return new ClassifiedTransaction('disbursement', $roles, [
            TransactionLineRole::Source->value                  => 'إخراج مبلغ الصرف من حساب مصدر المشروع',
            TransactionLineRole::AdministrativeDeduction->value => 'إثبات الخصم الإداري على مبلغ المشروع',
            TransactionLineRole::TransferFee->value             => 'إثبات عمولة تحويل مبلغ المشروع',
            TransactionLineRole::Destination->value             => 'إثبات صافي مبلغ المشروع في حساب التنفيذ',
        ], $summary);
    }

    protected function classifyGeneralExchange(Transaction $transaction, GeneralExchange $exchange): ClassifiedTransaction
    {
        $roles = $this->resolveRolesByNotesTag($transaction->lines, 'general_exchange', [
            GeneralExchange::LINE_SOURCE      => TransactionLineRole::Source,
            GeneralExchange::LINE_ADMIN       => TransactionLineRole::AdministrativeDeduction,
            GeneralExchange::LINE_TRANSFER    => TransactionLineRole::TransferFee,
            GeneralExchange::LINE_DESTINATION => TransactionLineRole::Destination,
        ]);

        $sourceLine      = $transaction->lines->first(fn (TransactionLine $l) => $roles[$l->id] === TransactionLineRole::Source);
        $destinationLine = $transaction->lines->first(fn (TransactionLine $l) => $roles[$l->id] === TransactionLineRole::Destination);

        $sourceName      = $sourceLine?->account?->name;
        $destinationName = $destinationLine?->account?->name;

        $summary = ($sourceName && $destinationName)
            ? "تحويل مبلغ من {$sourceName} إلى {$destinationName}"
            : 'تسجيل تحويل عام';

        return new ClassifiedTransaction('general_exchange', $roles, [
            TransactionLineRole::Source->value                  => 'إخراج مبلغ التحويل من حساب المصدر',
            TransactionLineRole::AdministrativeDeduction->value => 'إثبات الخصم الإداري على التحويل العام',
            TransactionLineRole::TransferFee->value             => 'إثبات عمولة التحويل العام',
            TransactionLineRole::Destination->value             => 'إثبات صافي المبلغ المحول إلى حساب الوجهة',
        ], $summary);
    }

    protected function classifyOpeningBalance(Transaction $transaction): ClassifiedTransaction
    {
        [$debitLine, $creditLine] = $this->resolveSingleDebitCreditPair($transaction->lines, 'opening_balance');

        $targetAccountName = $debitLine->account?->name;

        $summary = $targetAccountName
            ? "تسجيل الرصيد الافتتاحي لحساب {$targetAccountName}"
            : 'تسجيل الرصيد الافتتاحي للحساب';

        return new ClassifiedTransaction('opening_balance', [
            $debitLine->id  => TransactionLineRole::OpeningBalanceTarget,
            $creditLine->id => TransactionLineRole::OpeningBalanceCounterpart,
        ], [
            TransactionLineRole::OpeningBalanceTarget->value      => 'إثبات الرصيد الافتتاحي للحساب',
            TransactionLineRole::OpeningBalanceCounterpart->value => 'الطرف المقابل للقيد الافتتاحي',
        ], $summary);
    }

    /**
     * Two-line flows (receipt, execution payment, general expense, opening
     * balance) always post exactly one non-zero debit line and one non-zero
     * credit line; role assignment is purely positional (debit vs credit),
     * never by account name or notes.
     *
     * @param  Collection<int, TransactionLine>  $lines
     * @return array{0: TransactionLine, 1: TransactionLine}
     */
    protected function resolveSingleDebitCreditPair(Collection $lines, string $flowName): array
    {
        if ($lines->count() !== 2) {
            throw new TransactionClassificationException(
                "{$flowName} transaction has {$lines->count()} active line(s), expected exactly 2"
            );
        }

        $debitLine  = $lines->first(fn (TransactionLine $l) => (float) $l->debit_base > 0 && (float) $l->credit_base === 0.0);
        $creditLine = $lines->first(fn (TransactionLine $l) => (float) $l->credit_base > 0 && (float) $l->debit_base === 0.0);

        if (! $debitLine || ! $creditLine || $debitLine->id === $creditLine->id) {
            throw new TransactionClassificationException(
                "{$flowName} transaction lines are not a single debit + single credit pair"
            );
        }

        return [$debitLine, $creditLine];
    }

    /**
     * Four-line flows (disbursement, general exchange) share the same shape:
     * one credit line + three debit lines (admin/transfer allowed to be
     * zero-amount placeholders), each tagged with a stable LINE_* notes
     * constant written by the flow at creation time. The flow itself was
     * already identified via the domain record's transaction_id FK — these
     * notes tags are only used here to map each line to its role.
     *
     * @param  Collection<int, TransactionLine>  $lines
     * @param  array<string, TransactionLineRole>  $noteToRole
     * @return array<int, TransactionLineRole>
     */
    protected function resolveRolesByNotesTag(Collection $lines, string $flowName, array $noteToRole): array
    {
        if ($lines->count() !== count($noteToRole)) {
            throw new TransactionClassificationException(
                "{$flowName} transaction has {$lines->count()} active line(s), expected exactly " . count($noteToRole)
            );
        }

        $roles = [];
        $seen  = [];

        foreach ($lines as $line) {
            $role = $noteToRole[(string) $line->notes] ?? null;

            if (! $role) {
                throw new TransactionClassificationException(
                    "{$flowName} transaction line #{$line->id} has an unrecognized notes tag, cannot assign a role deterministically"
                );
            }

            if (isset($seen[$role->value])) {
                throw new TransactionClassificationException(
                    "{$flowName} transaction has more than one line tagged for role '{$role->value}'"
                );
            }

            $seen[$role->value] = true;
            $roles[$line->id]   = $role;
        }

        return $roles;
    }
}
