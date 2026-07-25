<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.3 — injectable seam around "how large is the CURRENT live
 * database right now," used by RestoreDiskSpaceEstimator to size the
 * mandatory pre-restore safety backup (which is always full, regardless of
 * the selected restore scope — see RestoreDiskSpaceEstimator's class
 * docblock). Bound to MysqlInformationSchemaDatabaseSizeEstimator in
 * production; tests inject a fake that returns a fixed value so the
 * estimate never depends on a real MySQL connection.
 */
interface CurrentDatabaseSizeEstimator
{
    /**
     * Returns the best available current-database-size estimate in bytes.
     * $conservativeFallbackBytes is returned as-is whenever the real size
     * cannot be determined (no MySQL connection configured, the query
     * fails, or it returns nothing usable) — implementations must never
     * return 0 to mean "unknown."
     */
    public function estimateBytes(int $conservativeFallbackBytes): int;
}
