<?php

namespace App\Services\Transactions\Backfill;

use App\Enums\TransactionLineRole;

/**
 * Result of deterministically classifying one historical Transaction into
 * one of the six legitimate financial flows: which line gets which role,
 * the approved per-role purpose text, and the approved parent summary text.
 */
class ClassifiedTransaction
{
    /**
     * @param  string  $flow  one of: receipt, disbursement, execution_payment, general_expense, general_exchange, opening_balance
     * @param  array<int, TransactionLineRole>  $rolesByLineId
     * @param  array<string, string>  $purposesByRole
     */
    public function __construct(
        public readonly string $flow,
        public readonly array $rolesByLineId,
        public readonly array $purposesByRole,
        public readonly string $summary,
    ) {
    }
}
