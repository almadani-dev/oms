<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\AccountStatement\AccountStatementProjectionState;
use App\Services\Reports\AccountStatement\AccountStatementProjectionVerifier;
use Illuminate\Console\Command;

/**
 * Independent drift check of the "تقرير كشف الحساب" projection against the
 * ledger (see AccountStatementProjectionVerifier). Never modifies the ledger
 * or the projection rows; on a difference it marks the projection invalid,
 * so the page stops reading it until it is rebuilt.
 *
 * Exit codes: 0 = no differences; 1 = differences found.
 */
class VerifyAccountStatementProjectionCommand extends Command
{
    protected $signature = 'oms:verify-account-statement-projection
        {--window=2000 : Transactions per verified window}
        {--balance-samples=200 : Random balances checked against direct ledger sums}';

    protected $description = 'Verify the account statement projection against the ledger (read-only).';

    public function handle(AccountStatementProjectionVerifier $verifier, AccountStatementProjectionState $state): int
    {
        $started = microtime(true);
        $last = 0.0;

        $result = $verifier->verify(
            window: max(1, (int) $this->option('window')),
            balanceSamples: max(0, (int) $this->option('balance-samples')),
            progress: function (string $phase, int $done, int $total) use (&$last): void {
                if (microtime(true) - $last >= 5 || $done === $total) {
                    $last = microtime(true);
                    $this->line(sprintf('  %-7s %s / %s', $phase, number_format($done), number_format($total)));
                }
            },
        );

        $this->table(['Check', 'Value'], [
            ['lines checked', number_format($result['lines_checked'])],
            ['line differences', $result['line_differences']],
            ['daily total differences', $result['daily_differences']],
            ['monthly total differences', $result['monthly_differences']],
            ['all-time total differences', $result['account_total_differences']],
            ['balances checked', number_format($result['balances_checked'])],
            ['balance differences', $result['balance_differences']],
            ['TOTAL differences', $result['differences']],
            ['seconds', round(microtime(true) - $started, 1)],
        ]);

        if ($result['differences'] === 0) {
            $state->markVerified();
            $this->info('Projection matches the ledger: 0 differences.');

            return self::SUCCESS;
        }

        foreach ($result['samples'] as $sample) {
            $this->line('  '.$sample);
        }

        $state->markInvalid($result['differences'].' differences found by the verifier.');
        $this->error($result['differences'].' differences — the projection is marked invalid. Rebuild it.');

        return 1;
    }
}
