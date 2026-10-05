<?php

namespace App\Services\Stress\Generator;

use App\Models\TransactionLine;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds transactions.description and transaction_lines.description from
 * IN-MEMORY lines, with the real builders' own formatting.
 *
 * Line descriptions call the real public
 * TransactionLineDescriptionBuilder::describeLine(). The transaction
 * description reuses the real TransactionDescriptionBuilder's protected
 * formatSide()/normalizeSummary(); only build()'s three-line assembly is
 * restated here because the real build() re-reads the lines from the
 * database. A parity test compares both against the real database path.
 */
final class StressTransactionDescriber extends TransactionDescriptionBuilder
{
    public function __construct(private readonly TransactionLineDescriptionBuilder $lineBuilder) {}

    /**
     * @param  Collection<int, TransactionLine>  $lines  ordered as inserted, with account+currency relations
     */
    public function describeTransaction(Collection $lines, string $summary): string
    {
        $creditEntries = $this->formatSide($lines, 'credit_base');
        $debitEntries = $this->formatSide($lines, 'debit_base');

        if ($creditEntries->isEmpty() || $debitEntries->isEmpty()) {
            throw new RuntimeException('Cannot describe a transaction without both a debit and a credit line.');
        }

        return 'دائن: '.$creditEntries->implode('؛ ')
            .' | مدين: '.$debitEntries->implode('؛ ')
            .' | ملخص العملية: '.$this->normalizeSummary($summary);
    }

    /**
     * @param  array<string, string>  $purposesByRole
     */
    public function describeLine(TransactionLine $line, array $purposesByRole): ?string
    {
        return $this->lineBuilder->describeLine($line, $purposesByRole);
    }
}
