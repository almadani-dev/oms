<?php

namespace App\Services\Stress;

use Illuminate\Support\Facades\DB;

/**
 * Asks the live server which database a connection is really using and
 * which machine it runs on. Bound separately so the guard's decision logic
 * can be tested without a MySQL server.
 */
class StressServerProbe
{
    /**
     * @return array{database: ?string, server_hostname: ?string}
     */
    public function identify(string $connection): array
    {
        $row = DB::connection($connection)->selectOne('SELECT DATABASE() AS db, @@hostname AS host');

        return [
            'database' => $row?->db,
            'server_hostname' => $row?->host,
        ];
    }

    public function databaseExists(string $baseConnection, string $database): bool
    {
        return DB::connection($baseConnection)
            ->selectOne('SELECT COUNT(*) AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database])
            ->c > 0;
    }

    public function localHostname(): string
    {
        return (string) gethostname();
    }
}
