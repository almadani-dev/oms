<?php

namespace App\Services\Transactions;

use App\Enums\TransactionLineRole;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Transactions\Support\FormatsTransactionText;
use RuntimeException;

/**
 * Builds and saves the one-line Arabic transaction_lines.description value:
 * "{مدين|دائن}: حساب {name} ({line currency code}) — {posted amount} | الغرض: {purpose}."
 *
 * Reads only the final, active (non-soft-deleted) lines of the supplied
 * transaction, so it must run after a flow's lines are in their final saved
 * state, inside the flow's existing DB::transaction() (any validation failure
 * throws and rolls the whole financial operation back — no partial output).
 * Purposes are supplied per-call by the owning flow, keyed by the line_role
 * machine value, because the same role carries different wording in different
 * flows. Display/search/audit only — never parsed back for accounting logic.
 */
class TransactionLineDescriptionBuilder
{
    use FormatsTransactionText;

    /**
     * @param  array<string, string>  $purposesByRole  TransactionLineRole values => Arabic purpose text
     */
    public function buildAndSaveForTransaction(Transaction $transaction, array $purposesByRole): void
    {
        $lines = $transaction->lines()
            ->with([
                'account' => fn ($query) => $query->withTrashed(),
                'currency',
            ])
            ->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            $description = $this->describeLine($line, $purposesByRole);

            // Zero-amount lines are legitimate placeholders (e.g. a 0% admin
            // or transfer deduction line) — they are omitted from the parent
            // transactions.description too, so their description stays null.
            if ($description === null) {
                continue;
            }

            $line->description = $description;
            $line->save();
        }
    }

    /**
     * Compute the canonical description for a single active line without
     * persisting it. Returns null for a legitimate zero-amount placeholder
     * line; throws the same validation exceptions as buildAndSaveForTransaction
     * otherwise. Exposed publicly so callers that need to compute-then-diff
     * (e.g. an idempotent historical backfill) can reuse the exact same
     * generation/validation logic instead of re-deriving it.
     *
     * @param  array<string, string>  $purposesByRole
     */
    public function describeLine(TransactionLine $line, array $purposesByRole): ?string
    {
        if ((float) $line->debit_base === 0.0 && (float) $line->credit_base === 0.0) {
            return null;
        }

        return $this->buildForLine($line, $purposesByRole);
    }

    /**
     * @param  array<string, string>  $purposesByRole
     */
    protected function buildForLine(TransactionLine $line, array $purposesByRole): string
    {
        $role = $this->resolveRole($line);

        [$sideLabel, $amount] = $this->resolvePostedSide($line);

        if (! $line->account) {
            throw new RuntimeException("Transaction line #{$line->id}: cannot build description, line has no account.");
        }

        if (! $line->currency) {
            throw new RuntimeException("Transaction line #{$line->id}: cannot build description, line has no currency.");
        }

        if (! array_key_exists($role->value, $purposesByRole)) {
            throw new RuntimeException("Transaction line #{$line->id}: no approved purpose supplied for role '{$role->value}'.");
        }

        $label   = $this->formatAccountLabel((string) $line->account->name);
        $code    = (string) $line->currency->code;
        $purpose = $this->normalizeArabicText($purposesByRole[$role->value]);

        return "{$sideLabel}: {$label} ({$code}) — {$this->formatAmount($amount)} | الغرض: {$purpose}";
    }

    protected function resolveRole(TransactionLine $line): TransactionLineRole
    {
        if ($line->line_role === null || $line->line_role === '') {
            throw new RuntimeException("Transaction line #{$line->id}: cannot build description, line_role is missing.");
        }

        $role = TransactionLineRole::tryFrom($line->line_role);

        if (! $role) {
            throw new RuntimeException("Transaction line #{$line->id}: unknown line_role '{$line->line_role}'.");
        }

        return $role;
    }

    /**
     * Exactly one accounting side must be positive: debit uses debit_base,
     * credit uses credit_base. Anything else is an invalid posting.
     *
     * @return array{0: string, 1: float}
     */
    protected function resolvePostedSide(TransactionLine $line): array
    {
        $debit  = (float) $line->debit_base;
        $credit = (float) $line->credit_base;

        if ($debit < 0 || $credit < 0) {
            throw new RuntimeException("Transaction line #{$line->id}: invalid posting, negative debit/credit amount.");
        }

        if ($debit > 0 && $credit > 0) {
            throw new RuntimeException("Transaction line #{$line->id}: invalid posting, both debit and credit are positive.");
        }

        if ($debit === 0.0 && $credit === 0.0) {
            throw new RuntimeException("Transaction line #{$line->id}: invalid posting, both debit and credit are zero.");
        }

        return $debit > 0 ? ['مدين', $debit] : ['دائن', $credit];
    }
}
