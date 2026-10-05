<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

/**
 * Where one background snapshot build stands — read from small status files
 * only, never from the ledger, so polling it is O(1).
 *
 * Progress is counted in TRANSACTIONS of the period that passed the
 * transaction-level filters (the builder's own page unit), against an exact
 * count taken once when processing starts. It is an honest fraction of the
 * work done, not an estimate of report lines; processedRows is the exact
 * number of report lines written so far.
 */
final class ComprehensiveFinancialTransactionsBuildStatus
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public function __construct(
        public readonly string $state,
        public readonly int $processedTransactions = 0,
        public readonly ?int $totalTransactions = null,
        public readonly int $processedRows = 0,
        public readonly ?string $errorCode = null,
    ) {}

    public function isFinished(): bool
    {
        return in_array($this->state, [self::COMPLETED, self::FAILED, self::EXPIRED], true);
    }

    /**
     * Whole percent of candidate transactions processed, or null when the
     * total is not known yet (still pending) or is zero.
     */
    public function percent(): ?int
    {
        if ($this->totalTransactions === null || $this->totalTransactions === 0) {
            return null;
        }

        return (int) min(100, floor($this->processedTransactions * 100 / $this->totalTransactions));
    }
}
