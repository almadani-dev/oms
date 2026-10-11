<?php

namespace App\Services\Reports\AccountStatement\Csv;

use RuntimeException;

/**
 * The CSV export's own proof failed: segments did not chain, or did not
 * match the account summary (a financial write landed between the parallel
 * snapshots). The export is rebuilt, never delivered.
 */
final class AccountStatementCsvMismatch extends RuntimeException {}
