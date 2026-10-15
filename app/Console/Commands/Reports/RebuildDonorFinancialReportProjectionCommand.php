<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionRebuilder;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionState;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * (Re)builds the "تقرير الجهات المانحة" projection from the authoritative
 * tables (receipts, disbursements, execution payments, the ledger), in
 * place, while the system stays in use. Never writes those tables.
 *
 * Safe to stop at any moment and run again (--resume continues from the
 * last committed window; a plain run redoes everything idempotently).
 * --donor=ID only re-derives that donor's cost items (a scoped repair; the
 * health flag is left as it is).
 *
 * Exit codes: 0 = rebuilt (and verified, with --verify); 1 = verification
 * found differences (the projection is then marked invalid); 2 = failure.
 */
class RebuildDonorFinancialReportProjectionCommand extends Command
{
    protected $signature = 'oms:rebuild-donor-financial-report-projection
        {--resume : Continue an interrupted rebuild from its last committed step}
        {--untrusted : Stop the report from reading the projection until the rebuild finishes}
        {--fresh : Empty the projection tables first (first build, or an untrustworthy projection)}
        {--window=500 : Sources (receipts / disbursements / payments) per committed window}
        {--donor= : Only re-derive this donor\'s cost items (scoped repair)}
        {--verify : Run the independent verifier afterwards}';

    protected $description = 'Rebuild the donor financial report projection from the authoritative tables (never modifies them).';

    public function handle(DonorReportProjectionRebuilder $rebuilder, DonorReportProjectionState $state): int
    {
        if (filled($this->option('donor'))) {
            return $this->rebuildDonor($rebuilder, (int) $this->option('donor'));
        }

        $this->info('Rebuilding the donor financial report projection (status before: '.$state->status().')...');
        $last = 0.0;

        try {
            $stats = $rebuilder->rebuild(
                resume: (bool) $this->option('resume'),
                untrusted: (bool) $this->option('untrusted'),
                window: max(1, min(DonorReportProjector::CHUNK * 10, (int) $this->option('window'))),
                progress: function (string $phase, int $done, int $total) use (&$last): void {
                    if (microtime(true) - $last >= 5 || $done === $total) {
                        $last = microtime(true);
                        $this->line(sprintf('  %-8s %s / %s  (%s MB)', $phase, number_format($done), number_format($total), round(memory_get_usage(true) / 1048576)));
                    }
                },
                fresh: (bool) $this->option('fresh'),
            );
        } catch (\Throwable $e) {
            report($e);
            $this->error('Rebuild failed ('.$e::class.'). Progress is kept: run again with --resume. See the logs for details.');

            return 2;
        }

        $this->info(sprintf(
            'Rebuilt in %ss: %s windows, %s sources corrected. Status: %s.',
            $stats['seconds'],
            number_format($stats['windows']),
            number_format($stats['sources_changed']),
            $state->status(),
        ));

        if (! $this->option('verify')) {
            return self::SUCCESS;
        }

        return $this->call('oms:verify-donor-financial-report-projection') === self::SUCCESS ? self::SUCCESS : 1;
    }

    private function rebuildDonor(DonorReportProjectionRebuilder $rebuilder, int $donorId): int
    {
        $costIds = DB::table('projects_costs as c')
            ->join('projects as p', 'p.id', '=', 'c.project_id')
            ->where('p.donor_id', $donorId)
            ->pluck('c.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $started = microtime(true);

        try {
            $changed = $rebuilder->rebuildCostItems($costIds);
        } catch (\Throwable $e) {
            report($e);
            $this->error('Rebuild failed ('.$e::class.'). See the logs for details.');

            return 2;
        }

        $this->info(sprintf('Donor %d: %s cost items re-derived in %.1fs, %s sources corrected.', $donorId, number_format(count($costIds)), microtime(true) - $started, number_format($changed)));

        if (! $this->option('verify')) {
            return self::SUCCESS;
        }

        return $this->call('oms:verify-donor-financial-report-projection', ['--donor' => $donorId]) === self::SUCCESS ? self::SUCCESS : 1;
    }
}
