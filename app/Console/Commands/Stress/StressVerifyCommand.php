<?php

namespace App\Console\Commands\Stress;

use App\Console\Commands\Stress\Concerns\ResolvesStressTarget;
use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use App\Services\Stress\StressMetaRepository;
use App\Services\Stress\Verification\StressVerifier;
use Illuminate\Console\Command;

class StressVerifyCommand extends Command
{
    use ResolvesStressTarget;

    protected $signature = 'oms:stress:verify
        {--database= : The approved stress database name (oms_stress)}
        {--confirm= : Repeat the database name to confirm}
        {--json : Print the full result as JSON}';

    protected $description = 'STRESS ONLY: read-only integrity, ledger, balance and coverage verification of oms_stress, plus the scale report.';

    protected $hidden = true;

    public function handle(StressEnvironmentGuard $guard): int
    {
        try {
            $target = $this->resolveStressTarget($guard, requireMarker: true);
        } catch (StressGuardException $e) {
            $this->error('ABORTED: '.$e->getMessage());

            return self::FAILURE;
        }

        ini_set('memory_limit', '2048M');
        $start = microtime(true);
        $verifier = new StressVerifier($target->connection);
        $result = $verifier->run(fn (string $line) => $this->line('  '.$line));
        $result['seconds'] = round(microtime(true) - $start, 1);
        $result['build'] = json_decode((string) (new StressMetaRepository($target->connection))->get('build_summary'), true);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Check', 'Violations'], collect($result['checks'])->map(fn ($c, $name) => [
                $name,
                $c['violations'] === 0 ? '0' : $c['violations'].'  ✗ '.json_encode($c['detail'] ?? null, JSON_UNESCAPED_UNICODE),
            ])->values()->all());
            $this->table(['Table', 'Total', 'Active'], collect($result['scale']['counts'])->map(fn ($c, $t) => [$t, number_format($c['total']), isset($c['active']) ? number_format($c['active']) : '—'])->values()->all());
            $this->line(json_encode(collect($result['scale'])->except('counts')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if (! $verifier->passed()) {
            $this->error('VERIFICATION FAILED — do not proceed to a larger build.');

            return self::FAILURE;
        }

        $this->info(sprintf('All checks passed in %.1fs.', $result['seconds']));

        return self::SUCCESS;
    }
}
