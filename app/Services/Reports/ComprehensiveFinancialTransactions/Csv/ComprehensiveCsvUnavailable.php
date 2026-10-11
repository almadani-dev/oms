<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

use RuntimeException;

/**
 * The report projection is not READY (missing, rebuilding or invalid): the
 * CSV export reads only the projection and never falls back to another
 * source, so it is refused rather than built differently.
 */
final class ComprehensiveCsvUnavailable extends RuntimeException {}
