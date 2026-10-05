<?php

namespace App\Console\Commands\Stress;

use App\Console\Commands\Stress\Concerns\ResolvesStressTarget;
use App\Services\Stress\Generator\StressDatasetBuilder;
use App\Services\Stress\Generator\StressScale;
use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use App\Services\Stress\StressMetaRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class StressBuildCommand extends Command
{
    use ResolvesStressTarget;

    protected $signature = 'oms:stress:build
        {--database= : The approved stress database name (oms_stress)}
        {--confirm= : Repeat the database name to confirm}
        {--scale=small : small | medium | large}
        {--lines= : Target transaction_lines instead of a preset (2000..5000000)}
        {--seed=20261005 : Deterministic seed}
        {--chunk=1000 : Rows per multi-row INSERT}
        {--generate-password : Generate a random stress-only password instead of prompting; shown once at the end}';

    protected $description = 'STRESS ONLY: generate the synthetic dataset into a freshly created, marked oms_stress database.';

    protected $hidden = true;

    public function handle(StressEnvironmentGuard $guard): int
    {
        try {
            $target = $this->resolveStressTarget($guard, requireMarker: true, beforeConnect: fn ($target) => $guard->assertStorageIsolated($target));
            $scale = StressScale::resolve($this->option('scale'), $this->option('lines'));
        } catch (StressGuardException|InvalidArgumentException $e) {
            $this->error('ABORTED: '.$e->getMessage());

            return self::FAILURE;
        }

        $seed = (int) $this->option('seed');
        $chunk = max(100, min(10_000, (int) $this->option('chunk')));

        $this->info(sprintf('Scale: %s (target %s transaction_lines), seed %d, chunk %d.', $scale->name, number_format($scale->targetLines), $seed, $chunk));

        // Stress-only credential: either generated here from a CSPRNG or typed
        // at a hidden prompt; only its hash is stored, never the clear text.
        $generated = null;

        if ($this->option('generate-password')) {
            $generated = 'Stress-'.rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'kQ'), '=');
            $password = $generated;
        } else {
            $password = (string) $this->secret('Password for the synthetic stress users (min 12 chars, stress-only, never a real password)');

            if (mb_strlen($password) < 12 || $password !== (string) $this->secret('Repeat the password')) {
                $this->error('ABORTED: passwords empty, too short or not matching.');

                return self::FAILURE;
            }
        }

        $hash = Hash::make($password);
        unset($password);

        ini_set('memory_limit', '2048M');
        $meta = new StressMetaRepository($target->connection);
        $meta->put('build_started_at', now()->toIso8601String());
        $meta->put('build_parameters', ['scale' => $scale->name, 'target_lines' => $scale->targetLines, 'seed' => $seed, 'chunk' => $chunk]);
        $start = microtime(true);

        try {
            $summary = (new StressDatasetBuilder($target->connection))
                ->build($scale, $seed, $hash, $chunk, fn (string $line) => $this->line($line));
        } catch (\Throwable $e) {
            $meta->put('build_failed_at', now()->toIso8601String());
            $this->error('BUILD FAILED: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            $this->warn('The stress database is now partial. Recover with oms:stress:reset, then create-database and build again.');

            return self::FAILURE;
        }

        $summary['seconds'] = round(microtime(true) - $start, 1);
        $summary['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576);
        $meta->put('build_finished_at', now()->toIso8601String());
        $meta->put('build_summary', $summary);

        $this->table(['Table', 'Rows written'], collect($summary['written'])->map(fn ($n, $t) => [$t, number_format($n)])->values()->all());
        $this->info(sprintf('Build finished in %.1fs, peak memory %d MB. Next: oms:stress:verify.', $summary['seconds'], $summary['peak_memory_mb']));

        if ($generated !== null) {
            // Shown exactly once; not stored anywhere in clear text.
            $this->warn('STRESS-ONLY password for every synthetic user (stress.<role>@stress-oms.invalid): '.$generated);
            $this->warn('Valid only inside oms_stress. Never reuse it anywhere else.');
        }

        return self::SUCCESS;
    }
}
