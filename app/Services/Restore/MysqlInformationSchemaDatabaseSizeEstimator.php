<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\CurrentDatabaseSizeEstimator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production implementation of CurrentDatabaseSizeEstimator — a single,
 * lightweight `information_schema.tables` aggregate query (never a
 * mysqldump, never touches the actual table data) against the same
 * connection DatabaseDumper itself would target
 * (config('oms.backup.database_connection') or config('database.default')).
 *
 * Fails closed to the given conservative fallback — never zero — on any
 * problem: non-MySQL connection, missing database name, a query error, or
 * an empty/NULL aggregate (e.g. a schema with no tables yet).
 */
final class MysqlInformationSchemaDatabaseSizeEstimator implements CurrentDatabaseSizeEstimator
{
    public function estimateBytes(int $conservativeFallbackBytes): int
    {
        $fallback = max(0, $conservativeFallbackBytes);

        try {
            $connectionName = (string) (config('oms.backup.database_connection') ?: config('database.default'));
            $connection = config("database.connections.{$connectionName}");

            if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
                return $fallback;
            }

            $database = (string) ($connection['database'] ?? '');

            if ($database === '') {
                return $fallback;
            }

            $row = DB::connection($connectionName)->selectOne(
                'SELECT SUM(data_length + index_length) AS total_bytes FROM information_schema.tables WHERE table_schema = ?',
                [$database],
            );

            $bytes = $row?->total_bytes;

            if ($bytes === null || (int) $bytes <= 0) {
                return $fallback;
            }

            return (int) $bytes;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
