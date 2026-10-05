<?php

namespace App\Console\Commands\Stress;

use App\Console\Commands\Stress\Concerns\ResolvesStressTarget;
use App\Services\Stress\StressDatabaseInstaller;
use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use Illuminate\Console\Command;

class StressCreateDatabaseCommand extends Command
{
    use ResolvesStressTarget;

    protected $signature = 'oms:stress:create-database
        {--database= : The approved stress database name (oms_stress)}
        {--confirm= : Repeat the database name to confirm}';

    protected $description = 'STRESS ONLY: create the empty, marked oms_stress database (schema, lookups, roles). No operational data.';

    protected $hidden = true;

    public function handle(StressEnvironmentGuard $guard, StressDatabaseInstaller $installer): int
    {
        try {
            $target = $this->resolveStressTarget($guard, requireMarker: false, beforeConnect: fn ($target) => $guard->assertStorageIsolated($target));

            $installer->install($target, fn (string $line) => $this->line($line));
        } catch (StressGuardException $e) {
            $this->error('ABORTED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Stress database created. Next: php artisan --env=stress oms:stress:build …');

        return self::SUCCESS;
    }
}
