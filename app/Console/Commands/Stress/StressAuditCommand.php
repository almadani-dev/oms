<?php

namespace App\Console\Commands\Stress;

use App\Services\Stress\Generator\StressScale;
use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Read-only pre-flight: prints the resolved environment, the hard limits,
 * whether each guard would pass, and the projected volumes. Connects to
 * nothing and writes nothing; allowed under APP_ENV=local.
 */
class StressAuditCommand extends Command
{
    protected $signature = 'oms:stress:audit
        {--scale=small : small | medium | large}
        {--lines= : Target transaction_lines instead of a preset}';

    protected $description = 'STRESS ONLY (read-only): show environment, stress guard status and projected dataset volumes.';

    protected $hidden = true;

    public function handle(StressEnvironmentGuard $guard): int
    {
        $default = config('database.default');

        $this->table(['Item', 'Value'], [
            ['APP_ENV (resolved)', app()->environment()],
            ['Config cached', app()->configurationIsCached() ? 'YES (stress commands will refuse)' : 'no'],
            ['Default connection', $default],
            ['Default database', (string) config("database.connections.{$default}.database")],
            ['Default host', (string) config("database.connections.{$default}.host")],
            ['storage_path()', storage_path()],
            ['Approved stress databases', implode(', ', (array) config('oms_stress.approved_databases'))],
            ['Forbidden databases', implode(', ', (array) config('oms_stress.forbidden_databases'))],
            ['Allowed hosts', implode(', ', (array) config('oms_stress.allowed_hosts'))],
            ['Allowed / writing environments', implode(', ', (array) config('oms_stress.allowed_environments')).' / '.implode(', ', (array) config('oms_stress.write_environments'))],
            ['Stress storage root', (string) config('oms_stress.storage_root')],
        ]);

        foreach (['read-only commands' => false, 'writing commands' => true] as $label => $writes) {
            try {
                $guard->assertEnvironment($writes);
                $guard->assertConfigNotCached();
                $this->info("Environment guard for {$label}: PASS");
            } catch (StressGuardException $e) {
                $this->warn("Environment guard for {$label}: REFUSED — ".$e->getMessage());
            }
        }

        try {
            $scale = StressScale::resolve($this->option('scale'), $this->option('lines'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Projected targets for scale [{$scale->name}]:");
        $this->table(['Item', 'Target'], collect($scale->projected())->map(fn ($v, $k) => [$k, number_format($v)])->values()->all());

        return self::SUCCESS;
    }
}
