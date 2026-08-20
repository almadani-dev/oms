<?php

namespace App\Services\Integrity;

use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Read-only, historical replay of FinancialTransactionBalanceGuard — the
 * single authoritative source of truth for what "balanced" means in this
 * codebase — against every existing transaction's non-deleted lines.
 *
 * CRITICAL: debit_base/credit_base are each line's OWN-currency amount, never
 * a company-base-currency conversion (confirmed by the OMS Task 8 audit
 * against TrialBalanceReportService/ComprehensiveFinancialTransactionsPage's
 * own docblocks and every real write path). A 4-line multi-currency exchange
 * is therefore NEVER checked via a raw SUM(debit_base)=SUM(credit_base)
 * across the whole transaction (that would silently sum two different
 * currencies) — it is checked via the exact FX equation
 * FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines() already
 * uses at write time.
 *
 * A transaction's line_role set determines which known valid structure it is
 * (a 2-line single-currency shape, or one of the four multi-currency
 * exchange/disbursement shapes — 4, 3 or 2 lines, since both deduction roles
 * are optional; see MULTI_CURRENCY_ROLE_SETS). A transaction whose role set
 * matches none of these — including every pre-2026-07-14 historical row where
 * line_role is NULL — cannot be safely classified as balanced or unbalanced
 * without guessing, so it is reported as a separate, bounded WARNING
 * ("unclassified structure") rather than a false-positive violation or a
 * silently-skipped gap.
 *
 * Bounded: transactions are processed via chunkById; only the current
 * chunk's own lines are ever loaded (one extra query per chunk, no N+1).
 */
class JournalBalanceIntegrityChecker
{
    private const CHUNK_SIZE = 500;

    /** @var array<string, array<int, string>> */
    private const SINGLE_CURRENCY_ROLE_SETS = [
        ['funding_source', 'receipt_destination'],
        ['expense', 'source'],
        ['beneficiary', 'execution_source'],
        ['opening_balance_counterpart', 'opening_balance_target'],
    ];

    /**
     * Every valid multi-currency (disbursement / general exchange) role set,
     * each stored ALREADY SORTED because $roles below is sorted before
     * comparison.
     *
     * Both workflows carry two OPTIONAL deduction lines: a 0% administrative
     * or transfer percentage writes no line at all, because a
     * debit_base = credit_base = 0 row is meaningless accounting that
     * assertValidLinePayload() rejects. So the same logical transaction is
     * legitimately 4, 3 or 2 lines. Source and destination are always
     * required - there is no transfer without money leaving one account and
     * arriving in another.
     *
     * ['destination', 'source'] is deliberately listed HERE and not in
     * SINGLE_CURRENCY_ROLE_SETS even though it is two lines: a two-line
     * disbursement still converts between currencies and may carry
     * fx_rate != 1, so routing it through assertBalancedSingleCurrencyLines()
     * (which demands fx_rate == 1 and one shared currency) would raise false
     * `unbalanced_transaction` violations on perfectly correct rows.
     */
    private const MULTI_CURRENCY_ROLE_SETS = [
        ['destination', 'source'],
        ['administrative_deduction', 'destination', 'source'],
        ['destination', 'source', 'transfer_fee'],
        ['administrative_deduction', 'destination', 'source', 'transfer_fee'],
    ];

    public function check(IntegrityCheckReport $report): void
    {
        $checked = 0;
        $unbalanced = [];
        $invalidFxBase = [];
        $unclassified = [];

        Transaction::query()
            ->select(['id'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $transactions) use ($report, &$checked, &$unbalanced, &$invalidFxBase, &$unclassified) {
                $transactionIds = $transactions->pluck('id');

                $linesByTransaction = TransactionLine::query()
                    ->whereIn('transaction_id', $transactionIds)
                    ->whereNull('deleted_at')
                    ->get()
                    ->groupBy('transaction_id');

                foreach ($transactionIds as $transactionId) {
                    $checked++;
                    $lines = $linesByTransaction->get($transactionId, collect());

                    if ($lines->isEmpty()) {
                        // No non-deleted lines at all: nothing to balance-check here —
                        // DatabaseRelationshipIntegrityChecker's own coverage is for
                        // physically orphaned lines, not transactions with zero lines.
                        continue;
                    }

                    $this->classifyAndValidate($transactionId, $lines, $unbalanced, $invalidFxBase, $unclassified);
                }
            });

        $report->setStat('journal_transactions_checked', $checked);

        if ($unbalanced !== []) {
            $report->addViolation(IntegrityViolation::fromIds(
                'unbalanced_transaction',
                'transaction lines do not satisfy FinancialTransactionBalanceGuard for their structure',
                $unbalanced,
            ));
        }

        if ($invalidFxBase !== []) {
            $report->addViolation(IntegrityViolation::fromIds(
                'invalid_fx_base_conversion',
                'multi-currency transaction fails the destination FX/base conversion equation',
                $invalidFxBase,
            ));
        }

        if ($unclassified !== []) {
            $report->addWarning(IntegrityViolation::fromIds(
                'unclassified_transaction_structure',
                'line_role set does not match any known valid transaction structure (often a pre-2026-07-14 row with NULL line_role) — requires manual review, not auto-flagged as unbalanced',
                $unclassified,
            ));
        }
    }

    /**
     * @param  Collection<int, TransactionLine>  $lines
     * @param  array<int, int>  $unbalanced
     * @param  array<int, int>  $invalidFxBase
     * @param  array<int, int>  $unclassified
     */
    private function classifyAndValidate(
        int $transactionId,
        Collection $lines,
        array &$unbalanced,
        array &$invalidFxBase,
        array &$unclassified,
    ): void {
        $roles = $lines->pluck('line_role')->filter()->sort()->values()->all();

        // $roles has already dropped NULL line_roles, so requiring
        // count($roles) === $lines->count() is what keeps this strict: a
        // transaction mixing three recognised roles with one unclassified
        // line, or carrying the same role twice, matches no set and stays
        // unclassified rather than being validated against the wrong shape.
        $isMultiCurrency = count($roles) === $lines->count()
            && in_array($roles, self::MULTI_CURRENCY_ROLE_SETS, true);

        $isSingleCurrency = $lines->count() === 2
            && in_array($roles, self::SINGLE_CURRENCY_ROLE_SETS, true);

        if (! $isMultiCurrency && ! $isSingleCurrency) {
            $unclassified[] = $transactionId;

            return;
        }

        $payload = $lines->map(fn (TransactionLine $line) => [
            'account_id' => $line->account_id,
            'currency_id' => $line->currency_id,
            'amount_currency' => $line->amount_currency,
            'fx_rate' => $line->fx_rate,
            'debit_base' => $line->debit_base,
            'credit_base' => $line->credit_base,
            'line_role' => $line->line_role,
        ])->all();

        try {
            FinancialTransactionBalanceGuard::assertValidLinePayload($payload);

            if ($isSingleCurrency) {
                $expectedCurrencyId = (int) $lines->first()->currency_id;
                FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($payload, $expectedCurrencyId);
            } else {
                // Source and destination are guaranteed present by every set in
                // MULTI_CURRENCY_ROLE_SETS; the two deductions are optional and
                // a missing one is passed as null, contributing 0 to the
                // amount_after_deductions equation.
                $byRole = $lines->keyBy('line_role');

                FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
                    $this->linePayload($byRole['source']),
                    isset($byRole['administrative_deduction']) ? $this->linePayload($byRole['administrative_deduction']) : null,
                    isset($byRole['transfer_fee']) ? $this->linePayload($byRole['transfer_fee']) : null,
                    $this->linePayload($byRole['destination']),
                    (int) $byRole['source']->currency_id,
                    (int) $byRole['destination']->currency_id,
                );
            }
        } catch (ValidationException) {
            if ($isMultiCurrency) {
                $invalidFxBase[] = $transactionId;
            } else {
                $unbalanced[] = $transactionId;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(TransactionLine $line): array
    {
        return [
            'account_id' => $line->account_id,
            'currency_id' => $line->currency_id,
            'amount_currency' => $line->amount_currency,
            'fx_rate' => $line->fx_rate,
            'debit_base' => $line->debit_base,
            'credit_base' => $line->credit_base,
            'line_role' => $line->line_role,
        ];
    }
}
