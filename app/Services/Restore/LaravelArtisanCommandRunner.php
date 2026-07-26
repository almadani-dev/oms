<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\ArtisanCommandRunner;
use Illuminate\Support\Facades\Artisan;

/**
 * Production implementation of ArtisanCommandRunner — a thin pass-through
 * to the Artisan facade, isolated behind an interface purely so
 * RestoreReconciler's tests never need to actually run `migrate`/
 * `oms:sync-permissions`/`permission:cache-reset`/`queue:restart` to prove
 * the order they're called in or how a failure short-circuits later steps.
 */
final class LaravelArtisanCommandRunner implements ArtisanCommandRunner
{
    public function run(string $command, array $parameters = []): int
    {
        return Artisan::call($command, $parameters);
    }
}
