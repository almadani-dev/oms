<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

use RuntimeException;

/**
 * The CSV export's own proof failed: a segment worker did not read what the
 * export's consistent reference read (a financial write landed between the
 * parallel snapshots), or the rows do not add up to the report's summaries.
 * The export is rebuilt, never delivered.
 */
final class ComprehensiveCsvMismatch extends RuntimeException {}
