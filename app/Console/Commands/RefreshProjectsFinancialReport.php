<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Services\Reports\ProjectsGeneralFinancialReportService;
use Illuminate\Console\Command;

class RefreshProjectsFinancialReport extends Command
{
    protected $signature = 'reports:refresh-projects-financial
        {--project_id= : Refresh only this project}
        {--force : Refresh all projects, ignoring the is_dirty flag}
        {--chunk=100 : Number of projects to load per chunk}';

    protected $description = 'Recalculate the Projects General Financial Report snapshots + currency totals';

    public function handle(ProjectsGeneralFinancialReportService $service): int
    {
        $start = microtime(true);

        $refreshed = 0;
        $skipped   = 0;

        // Single project mode.
        if ($projectId = $this->option('project_id')) {
            $result = $service->calculateAndStore((int) $projectId);
            $this->line("Project {$projectId}: {$result}");
            $this->summary($result === 'skipped' ? 0 : 1, 0, $start);

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $chunk = max(1, (int) $this->option('chunk'));

        // Currently-dirty / missing project ids (only needed for the non-force path).
        $dirtyIds = $force ? collect() : $this->dirtyOrMissingProjectIds();

        Project::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById($chunk, function ($projects) use ($service, $force, $dirtyIds, &$refreshed, &$skipped) {
                foreach ($projects as $project) {
                    if (! $force && ! $dirtyIds->has($project->id)) {
                        $skipped++;
                        continue;
                    }

                    $service->calculateAndStore($project->id);
                    $refreshed++;
                }
            });

        $this->summary($refreshed, $skipped, $start);

        return self::SUCCESS;
    }

    /**
     * Ids of non-deleted projects whose snapshot is missing or marked dirty.
     */
    private function dirtyOrMissingProjectIds(): \Illuminate\Support\Collection
    {
        $cleanIds = ProjectFinancialSnapshot::query()
            ->where('is_dirty', false)
            ->pluck('project_id');

        // A project needs refreshing unless it has a clean (is_dirty=false) snapshot.
        return Project::query()
            ->whereNotIn('id', $cleanIds)
            ->pluck('id')
            ->flip();
    }

    private function summary(int $refreshed, int $skipped, float $start): void
    {
        $seconds = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->info("Refreshed: {$refreshed}");
        $this->info("Skipped:   {$skipped}");
        $this->info("Time:      {$seconds}s");
    }
}
