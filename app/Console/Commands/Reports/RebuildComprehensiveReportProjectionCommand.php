<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionRebuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use Illuminate\Console\Command;

/**
 * (Re)builds the "تقرير الحركات المالية الشامل" projection from the ledger,
 * in place, while the system stays in use. Never writes the ledger.
 *
 * Safe to stop at any moment and run again (--resume continues from the last
 * committed window/month; a plain run redoes everything idempotently).
 *
 * Exit codes: 0 = rebuilt (and verified, with --verify); 1 = verification
 * found differences (the projection is then marked invalid); 2 = failure.
 */
class RebuildComprehensiveReportProjectionCommand extends Command
{
    protected $signature = 'oms:rebuild-comprehensive-report-projection
        {--resume : Continue an interrupted rebuild from its last committed step}
        {--untrusted : Stop the report from reading the projection until the rebuild finishes}
        {--window=500 : Transactions per committed window}
        {--verify : Run the independent drift verifier afterwards}';

    protected $description = 'Rebuild the comprehensive financial transactions report projection from the ledger (never modifies the ledger).';

    public function handle(ComprehensiveReportProjectionRebuilder $rebuilder, ComprehensiveReportProjectionState $state): int
    {
        $this->info('Rebuilding the comprehensive report projection (status before: '.$state->status().')...');

        $last = 0.0;

        try {
            $stats = $rebuilder->rebuild(
                resume: (bool) $this->option('resume'),
                untrusted: (bool) $this->option('untrusted'),
                window: max(1, (int) $this->option('window')),
                progress: function (string $phase, int $done, int $total) use (&$last): void {
                    if (microtime(true) - $last >= 5 || $done === $total) {
                        $last = microtime(true);
                        $this->line(sprintf('  %-10s %s / %s', $phase, number_format($done), number_format($total)));
                    }
                },
            );
        } catch (\Throwable $e) {
            report($e);
            $this->error('Rebuild failed ('.$e::class.'). Progress is kept: run again with --resume. See the logs for details.');

            return 2;
        }

        $this->info(sprintf(
            'Rebuilt in %ss: %s windows, %s transactions corrected, %s months of aggregates. Status: %s.',
            $stats['seconds'],
            number_format($stats['windows']),
            number_format($stats['transactions_changed']),
            number_format($stats['months']),
            $state->status(),
        ));

        if (! $this->option('verify')) {
            return self::SUCCESS;
        }

        return $this->call('oms:verify-comprehensive-report-projection') === self::SUCCESS ? self::SUCCESS : 1;
    }
}
