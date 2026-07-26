<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.5 — injectable seam around discarding Laravel's existing PDO
 * for the restored database connection and establishing a fresh one. After a
 * real `mysql` import replaces the schema/data underneath an already-open
 * PDO, that PDO must never be assumed usable — reset() must purge it,
 * reconnect, and prove the new connection actually works before returning.
 *
 * Bound to LaravelRestoreDatabaseConnectionResetter in production. Tests use
 * a fake so the test process's own database connection (often an in-memory
 * SQLite connection that would lose its entire schema if genuinely purged)
 * is never touched by a reconciliation-order test.
 */
interface RestoreDatabaseConnectionResetter
{
    /**
     * @throws \Throwable if a fresh, usable connection cannot be established
     */
    public function reset(string $connectionName): void;
}
