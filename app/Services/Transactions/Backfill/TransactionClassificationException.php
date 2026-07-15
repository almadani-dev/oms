<?php

namespace App\Services\Transactions\Backfill;

use RuntimeException;

/**
 * Thrown when a historical Transaction cannot be deterministically mapped to
 * one of the six approved flows, or its lines don't match that flow's
 * expected shape. Callers must leave the transaction untouched and report it
 * as unclassified — never guess.
 */
class TransactionClassificationException extends RuntimeException
{
}
