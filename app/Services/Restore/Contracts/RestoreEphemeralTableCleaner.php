<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.5 — injectable seam around the approved selective ephemeral-
 * table cleanup policy (backups-queue pending jobs, cache/cache_locks,
 * sessions) run after metadata reconstruction and before `queue:restart`.
 * Isolated behind an interface purely so RestoreReconciler's order/failure
 * tests never need a real database write to prove sequencing.
 */
interface RestoreEphemeralTableCleaner
{
    /**
     * @throws \Throwable
     */
    public function clean(): void;
}
