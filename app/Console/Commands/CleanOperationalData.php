<?php

namespace App\Console\Commands;

use App\Services\Maintenance\OperationalCleanupReport;
use App\Services\Maintenance\OperationalDataCleanupService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Console entry point for OperationalDataCleanupService. All decision logic
 * (environment guard, confirmation token, backup verification, deletion
 * order, attachment safety, verification) lives in the service — this class
 * only wires CLI options to it and renders the report.
 */
class CleanOperationalData extends Command
{
    protected $signature = 'oms:clean-operational-data
        {--dry-run : Audit only. Reports counts and plan. Performs zero database or file writes.}
        {--apply : Execute the cleanup. Requires --confirmation and --backup-file.}
        {--confirmation= : Must exactly equal ' . OperationalDataCleanupService::CONFIRMATION_TOKEN . '}
        {--backup-file= : Path to a verified, non-empty database backup file. Required for --apply.}
        {--skip-files : Skip physical attachment file deletion during --apply (database cleanup still runs).}';

    protected $description = 'Audit or permanently wipe operational/transactional data from the OMS '
        . 'development database while preserving system configuration, donors, accounts, currencies, '
        . 'exchange-rate history, and users/roles/permissions.';

    public function handle(OperationalDataCleanupService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun && $apply) {
            $this->error('Pass either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $apply) {
            $this->line($this->description);
            $this->newLine();
            $this->line('Usage:');
            $this->line('  php artisan oms:clean-operational-data --dry-run');
            $this->line('  php artisan oms:clean-operational-data --apply --confirmation=' . OperationalDataCleanupService::CONFIRMATION_TOKEN . ' --backup-file=/path/to/backup.sql');
            $this->newLine();
            $this->line('No changes were made.');

            return self::INVALID;
        }

        try {
            if ($dryRun) {
                $report = $service->audit();
            } else {
                $report = $service->apply(
                    backupFilePath: (string) $this->option('backup-file'),
                    confirmationToken: (string) $this->option('confirmation'),
                    skipFiles: (bool) $this->option('skip-files'),
                );
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unexpected failure: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->render($report);

        if (! $report->dryRun && ! $report->isClean()) {
            $this->error('Post-apply verification failed. See "Verification failures" above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function render(OperationalCleanupReport $report): void
    {
        $this->newLine();
        $this->info('Mode: ' . ($report->dryRun ? 'DRY RUN (zero writes)' : 'APPLY'));
        $this->line("Environment: {$report->environment}");
        $this->line("Database:    {$report->databaseName}");
        $this->line('Performed:   ' . $report->performedAt->toDateTimeString());

        $this->newLine();
        $this->line('Preserved tables (active / trashed):');
        foreach ($report->preservedCounts as $table => $counts) {
            $this->line("  {$table}: {$counts['active']} / {$counts['trashed']}");
        }

        $this->newLine();
        $this->line('Operational tables — proposed for deletion (active / trashed):');
        foreach ($report->operationalCounts as $table => $counts) {
            $this->line("  {$table}: {$counts['active']} / {$counts['trashed']}");
        }

        $this->newLine();
        $this->line('Donor/entity classification:');
        foreach ($report->entityClassification as $key => $value) {
            if (is_array($value)) {
                $this->line("  {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE));
            } else {
                $this->line("  {$key}: {$value}");
            }
        }

        $this->newLine();
        $this->line('Account balances:');
        $this->line("  total accounts: {$report->accountBalances['total_accounts']}");
        $this->line("  non-zero balances: {$report->accountBalances['non_zero_balance_count']}");

        $this->newLine();
        $this->line('Attachment plan:');
        $this->line("  linked (DB) count: {$report->attachmentPlan['linked_db_count']}");
        $this->line("  linked existing files: {$report->attachmentPlan['linked_existing_file_count']} ({$report->attachmentPlan['linked_existing_file_bytes']} bytes)");
        $this->line('  linked missing files: ' . count($report->attachmentPlan['linked_missing_files']));
        $this->line("  orphan files (report only, never deleted): {$report->attachmentPlan['orphan_file_count']} ({$report->attachmentPlan['orphan_file_bytes']} bytes)");
        if (isset($report->attachmentPlan['fileDeletion'])) {
            $fd = $report->attachmentPlan['fileDeletion'];
            if ($fd['skipped'] ?? false) {
                $this->line('  file deletion: skipped (--skip-files)');
            } else {
                $this->line('  files deleted: ' . count($fd['deleted'] ?? []));
                $this->line('  files failed to delete: ' . count($fd['failed'] ?? []));
            }
        }

        $this->newLine();
        $this->line('Proposed deletion order:');
        foreach ($report->deletionOrder as $i => $step) {
            $this->line('  ' . ($i + 1) . ". {$step}");
        }

        $this->newLine();
        $this->line('Risks / notes:');
        foreach ($report->risks as $risk) {
            $this->line("  - {$risk}");
        }

        if ($report->unexpectedOperationalTables !== []) {
            $this->newLine();
            $this->warn('Unexpected tables found outside the known preserved/operational list (untouched):');
            foreach ($report->unexpectedOperationalTables as $table) {
                $this->line("  - {$table}");
            }
        }

        $this->newLine();
        $this->line('Numbering/identity:');
        foreach ($report->numbering as $key => $value) {
            $this->line("  {$key}: " . (is_bool($value) ? ($value ? 'true' : 'false') : $value));
        }

        if (! $report->dryRun) {
            $this->newLine();
            if ($report->isClean()) {
                $this->info('Post-apply verification: PASSED');
            } else {
                $this->error('Post-apply verification failures:');
                foreach ($report->verificationFailures as $failure) {
                    $this->line("  - {$failure}");
                }
            }
        } else {
            $this->newLine();
            $this->info('Dry run performed zero database writes and zero file deletions.');
        }
    }
}
