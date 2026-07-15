<?php

namespace App\Services\Transactions;

use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Transactions\Support\FormatsTransactionText;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds and saves the one-line Arabic transactions.description value:
 * "دائن: {credit entries} | مدين: {debit entries} | ملخص العملية: {summary}."
 *
 * Reads only the final, active (non-soft-deleted) transaction lines at call
 * time, so it must run after a flow's lines are in their final saved state.
 * Display/search/audit only — never parsed back for accounting logic.
 */
class TransactionDescriptionBuilder
{
    use FormatsTransactionText;

    public function buildAndSave(Transaction $transaction, string $summary): Transaction
    {
        $transaction->description = $this->build($transaction, $summary);
        $transaction->save();

        return $transaction;
    }

    public function build(Transaction $transaction, string $summary): string
    {
        $lines = $transaction->lines()
            ->with([
                'account' => fn ($query) => $query->withTrashed(),
                'currency',
            ])
            ->orderBy('id')
            ->get();

        $creditEntries = $this->formatSide($lines, 'credit_base');
        $debitEntries  = $this->formatSide($lines, 'debit_base');

        if ($creditEntries->isEmpty()) {
            throw new RuntimeException("Transaction #{$transaction->id}: cannot build description, no credit lines found.");
        }

        if ($debitEntries->isEmpty()) {
            throw new RuntimeException("Transaction #{$transaction->id}: cannot build description, no debit lines found.");
        }

        $creditText  = $creditEntries->implode('؛ ');
        $debitText   = $debitEntries->implode('؛ ');
        $summaryText = $this->normalizeSummary($summary);

        return "دائن: {$creditText} | مدين: {$debitText} | ملخص العملية: {$summaryText}";
    }

    /**
     * @param  Collection<int, TransactionLine>  $lines
     * @return Collection<int, string>
     */
    protected function formatSide(Collection $lines, string $amountColumn): Collection
    {
        return $lines
            ->filter(fn (TransactionLine $line) => (float) $line->{$amountColumn} > 0)
            ->map(fn (TransactionLine $line) => $this->formatEntry($line, (float) $line->{$amountColumn}))
            ->values();
    }

    protected function formatEntry(TransactionLine $line, float $amount): string
    {
        $label        = $this->formatAccountLabel((string) ($line->account?->name ?? ''));
        $currencyCode = (string) ($line->currency?->code ?? '');

        return "{$label} ({$currencyCode}) — {$this->formatAmount($amount)}";
    }

    protected function normalizeSummary(string $summary): string
    {
        return $this->normalizeArabicText($summary);
    }
}
