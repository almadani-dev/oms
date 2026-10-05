<?php

namespace App\Console\Commands\Stress\Concerns;

use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use App\Services\Stress\StressTarget;
use Closure;

/**
 * Shared front door of every writing `oms:stress:*` command: validate
 * (environment, names, host, confirmation), print the resolved target
 * BEFORE anything runs, connect, and re-verify the live server/database.
 */
trait ResolvesStressTarget
{
    /**
     * @param  null|Closure(StressTarget): void  $beforeConnect  extra offline checks (e.g. reset's second confirmation)
     */
    protected function resolveStressTarget(StressEnvironmentGuard $guard, bool $requireMarker = true, ?Closure $beforeConnect = null): StressTarget
    {
        $target = $guard->resolveTarget(
            $this->option('database'),
            $this->option('confirm'),
            writes: true,
        );

        if ($beforeConnect !== null) {
            $beforeConnect($target);
        }

        $this->printTarget($target);

        $guard->registerConnections($target);
        $guard->assertLiveTarget($target, $target->connection.'_server');

        if ($requireMarker) {
            if (! $guard->databaseExists($target)) {
                throw new StressGuardException("[{$target->database}] does not exist. Run oms:stress:create-database first.");
            }

            $guard->useAsDefault($target);
            $guard->assertMarker($target);
        }

        return $target;
    }

    protected function printTarget(StressTarget $target): void
    {
        $this->warn('STRESS ENVIRONMENT — synthetic data only. Resolved target:');
        $this->table(['Item', 'Value'], $target->describe());
    }
}
