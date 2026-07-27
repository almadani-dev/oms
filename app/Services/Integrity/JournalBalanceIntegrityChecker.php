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
 * A transaction's line_role set determines which of the six known valid
 * structures it is (2-line single-currency, or the 4-line multi-currency
 * exchange/disbursement shape). A transaction whose role set matches none of
 * these — including every pre-2026-07-14 historical row where line_role is
 * NULL — cannot be safely classified as balanced or unbalanced without
 * guessing, so it is reported as a separate, bounded WARNING ("unclassified
 * structure") rather than a false-positive violation or a silently-skipped
 * gap.
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

    private const MULTI_CURRENCY_ROLE_SET = ['administrative_deduction', 'destination', 'source', 'transfer_fee'];

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

        $isMultiCurrency = $roles === self::MULTI_CURRENCY_ROLE_SET && $lines->count() === 4;
        $isSingleCurrency = $lines->count() === 2 && in_array($roles, self::SINGLE_CURRENCY_ROLE_SETS, true);

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
                $byRole = $lines->keyBy('line_role');
                $sourceLine = $this->linePayload($byRole['source']);
                $adminLine = $this->linePayload($byRole['administrative_deduction']);
                $transferLine = $this->linePayload($byRole['transfer_fee']);
                $destinationLine = $this->linePayload($byRole['destination']);

                FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
                    $sourceLine,
                    $adminLine,
                    $transferLine,
                    $destinationLine,
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
