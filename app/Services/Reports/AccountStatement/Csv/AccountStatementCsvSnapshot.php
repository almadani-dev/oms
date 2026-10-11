<?php

namespace App\Services\Reports\AccountStatement\Csv;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a read-only callback inside ONE consistent InnoDB snapshot, so the
 * opening balances and the streamed rows of an export (or of one segment)
 * describe the same ledger state even while financial writes continue.
 *
 * MySQL only; elsewhere (SQLite tests) and inside an already open
 * transaction the callback simply runs.
 */
final class AccountStatementCsvSnapshot
{
    private static bool $active = false;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        if (self::$active || DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() > 0) {
            return $callback();
        }

        DB::unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = false;

            try {
                DB::unprepared('COMMIT');
            } catch (Throwable) {
                // Read-only: nothing to keep; the connection resets itself.
            }
        }
    }
}
