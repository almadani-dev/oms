<?php

namespace App\Console\Commands;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvExport;
use App\Services\Reports\ComprehensiveFinancialTransactions\ExportTemporaryFiles;
use Illuminate\Console\Command;

/**
 * Removes expired "تقرير الحركات المالية الشامل" snapshots, abandoned or
 * failed builds, large-report workbooks prepared but never downloaded, and
 * local temporary files a crashed export left behind — for every user.
 *
 * Runs synchronously (no queue): it only lists and deletes small private
 * files, so it adds no worker dependency. Each build also purges its own
 * owner's expired snapshots first, so storage stays bounded for active users
 * even if the scheduler is not running; this command catches everyone else.
 */
class PurgeReportSnapshotsCommand extends Command
{
    protected $signature = 'oms:purge-report-snapshots';

    protected $description = 'Delete expired report snapshots, abandoned/failed snapshot builds and stale report export files.';

    public function handle(
        ComprehensiveFinancialTransactionsSnapshotRepository $repository,
        ComprehensiveFinancialTransactionsExcelExports $workbooks,
        ExportTemporaryFiles $temporaryFiles,
    ): int {
        $removed = $repository->purge(now());
        // Account statement exports (workbooks never downloaded, CSV
        // packages after their download window) and crashed CSV builds.
        $exports = $workbooks->purge(now()) + app(AccountStatementExcelExports::class)->purge(now());
        $files = $temporaryFiles->purge(now()) + AccountStatementCsvExport::purge(now());

        $this->info("Removed {$removed} report snapshot(s), {$exports} prepared export(s) and {$files} stale temporary file(s).");

        return self::SUCCESS;
    }
}
