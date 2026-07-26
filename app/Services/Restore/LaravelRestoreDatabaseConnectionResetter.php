<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\RestoreDatabaseConnectionResetter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Production implementation of RestoreDatabaseConnectionResetter.
 * DB::purge() disconnects and drops Laravel's cached resolved connection
 * for $connectionName, so the very next DB::connection($connectionName)
 * call is forced to build a brand-new PDO against the (just-imported)
 * database rather than reusing whatever PDO existed before the import —
 * the old PDO is never assumed valid. A trivial query on the fresh
 * connection is what actually proves it usable, not merely that
 * ->getPdo() returned an object.
 */
final class LaravelRestoreDatabaseConnectionResetter implements RestoreDatabaseConnectionResetter
{
    public function reset(string $connectionName): void
    {
        DB::purge($connectionName);

        try {
            DB::connection($connectionName)->select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to establish a usable database connection after restore.', 0, $e);
        }
    }
}
