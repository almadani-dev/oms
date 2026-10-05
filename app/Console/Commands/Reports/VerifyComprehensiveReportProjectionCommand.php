<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportParityChecker;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionVerifier;
use Illuminate\Console\Command;

/**
 * Read-only drift check of the "تقرير الحركات المالية الشامل" projection
 * against the ledger, with independent SQL (see
 * ComprehensiveReportProjectionVerifier). Never modifies the ledger or the
 * projection's data; only the projection's health flag changes: differences
 * mark it INVALID (the report then falls back to the ledger snapshot path
 * until a rebuild), a clean run records verified_at.
 *
 * --parity additionally runs the old-vs-new report oracle for one period.
 *
 * Exit codes: 0 = zero differences; 1 = differences found; 2 = failure.
 */
class VerifyComprehensiveReportProjectionCommand extends Command
{
    protected $signature = 'oms:verify-comprehensive-report-projection
        {--no-mark : Report only; never change the projection status}
        {--samples : Print a few differing values (may include amounts)}
        {--parity : Also compare the old ledger report with the projection report}
        {--from= : Parity period start (Y-m-d)}
        {--to= : Parity period end (Y-m-d)}';

    protected $description = 'Verify the comprehensive financial transactions report projection against the ledger (read-only).';

    public function handle(ComprehensiveReportProjectionVerifier $verifier, ComprehensiveReportProjectionState $state): int
    {
        $started = microtime(true);
        $last = 0.0;

        try {
            $result = $verifier->verify(progress: function (int $done, int $total) use (&$last): void {
                if (microtime(true) - $last >= 5 || $done === $total) {
                    $last = microtime(true);
                    $this->line(sprintf('  verified %s / %s transactions', number_format($done), number_format($total)));
                }
            });
        } catch (\Throwable $e) {
            report($e);
            $this->error('Verification failed ('.$e::class.'). See the logs for details.');

            return 2;
        }

        $this->line(sprintf(
            'Lines checked: %s | line differences: %d | monthly total differences: %d | transaction count differences: %d | %ss',
            number_format($result['lines_checked']),
            $result['line_differences'],
            $result['total_differences'],
            $result['transaction_count_differences'],
            round(microtime(true) - $started, 1),
        ));

        if ($this->option('samples')) {
            foreach ($result['samples'] as $sample) {
                $this->line('  '.$sample);
            }
        }

        $failed = $result['differences'] > 0;

        if (! $this->option('no-mark')) {
            if ($failed) {
                $state->markInvalid('Drift verifier found '.$result['differences'].' difference(s) at '.now()->toIso8601String().'.');
            } elseif ($state->isReady()) {
                $state->markVerified();
            }
        }

        if ($this->option('parity')) {
            $failed = $this->parity() || $failed;
        }

        $failed
            ? $this->error('DIFFERENCES FOUND. Projection status: '.$state->status().'.')
            : $this->info('Zero differences. Projection status: '.$state->status().'.');

        return $failed ? 1 : self::SUCCESS;
    }

    /**
     * @return bool true when differences were found
     */
    private function parity(): bool
    {
        $from = (string) ($this->option('from') ?: now()->startOfMonth()->toDateString());
        $to = (string) ($this->option('to') ?: now()->toDateString());
        $started = microtime(true);

        $result = app(ComprehensiveReportParityChecker::class)->check(ComprehensiveFinancialTransactionsFilters::make($from, $to));

        $this->line(sprintf(
            'Parity %s -> %s: rows old %s / new %s | row differences: %d | summary differences: %d | float residue: %s | %ss',
            $from,
            $to,
            number_format($result['rows_old']),
            number_format($result['rows_new']),
            $result['row_differences'],
            $result['summary_differences'],
            $result['float_residue'],
            round(microtime(true) - $started, 1),
        ));

        if ($this->option('samples')) {
            foreach ($result['samples'] as $sample) {
                $this->line('  '.$sample);
            }
        }

        return $result['row_differences'] > 0 || $result['summary_differences'] > 0;
    }
}
