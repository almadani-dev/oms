<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use RuntimeException;

/**
 * A stored report snapshot file cannot be trusted: missing, truncated,
 * corrupt, not in the encoding its manifest declares, or not valid JSON.
 *
 * Thrown instead of returning partial or guessed rows. The page treats it
 * exactly like an expired snapshot (the existing notice, nothing exported,
 * nothing audited); a queued export marks itself failed.
 */
final class ComprehensiveFinancialTransactionsSnapshotUnreadable extends RuntimeException
{
}
