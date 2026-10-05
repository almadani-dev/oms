<?php

namespace App\Console\Commands\Stress;

use App\Console\Commands\Stress\Concerns\ResolvesStressTarget;
use App\Services\Stress\StressDatabaseInstaller;
use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StressResetCommand extends Command
{
    use ResolvesStressTarget;

    protected $signature = 'oms:stress:reset
        {--database= : The approved stress database name (oms_stress)}
        {--confirm= : Repeat the database name to confirm}
        {--confirm-reset= : Repeat the database name AGAIN to confirm the destruction}';

    protected $description = 'STRESS ONLY: drop the marked oms_stress database and delete the stress storage root.';

    protected $hidden = true;

    public function handle(StressEnvironmentGuard $guard, StressDatabaseInstaller $installer): int
    {
        try {
            $target = $this->resolveStressTarget($guard, requireMarker: true, beforeConnect: function ($target) use ($guard): void {
                $guard->assertResetConfirmed($target, $this->option('confirm-reset'));
                $guard->assertStorageIsolated($target);
            });

            $this->warn('The following will be DESTROYED:');
            $this->table(['Table', 'Rows'], collect(DB::select(
                'SELECT TABLE_NAME AS t, TABLE_ROWS AS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_ROWS DESC',
                [$target->database]
            ))->map(fn ($row) => [$row->t, '~'.$row->r])->all());
            $this->warn('Storage directory: '.$target->storageRoot);

            $installer->drop($target);
            $installer->wipeStorage($target);
            // An empty skeleton, so the stress profile can still boot (views/cache paths).
            $installer->ensureStorageSkeleton($target);
        } catch (StressGuardException $e) {
            $this->error('ABORTED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Stress database and stress storage removed.');

        return self::SUCCESS;
    }
}
