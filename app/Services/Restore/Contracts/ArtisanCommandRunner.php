<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.5 — injectable seam around invoking an Artisan command by
 * name, so RestoreReconciler's exact command sequence (`migrate --force`,
 * `oms:sync-permissions`, `permission:cache-reset`, `queue:restart`) can be
 * tested for exact order and per-command failure without coupling tests to
 * the global Artisan facade. $parameters is always a fixed, hardcoded
 * option/argument array supplied by the caller — never user input, and
 * never a secret.
 */
interface ArtisanCommandRunner
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return int the command's exit code (0 == success)
     */
    public function run(string $command, array $parameters = []): int;
}
