<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

use RuntimeException;

/**
 * A projection row and its ledger line disagree inside ONE consistent read —
 * not a concurrent write but a projection that is not current. Never
 * retried, never delivered: the drift verifier must look at it.
 */
final class ComprehensiveCsvDrift extends RuntimeException {}
