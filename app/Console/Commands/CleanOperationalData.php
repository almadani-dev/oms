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
 * order, attachment safety, special cleanup, verification) lives in the
 * service — this class only wires CLI options to it and renders the report.
 *
 * NON-INTERACTIVE COMPATIBILITY. The interactive prompt added below runs only
 * when the console input is genuinely interactive (a real TTY) and --force was
 * not passed. Artisan::call() and any queued/scheduled invocation are
 * non-interactive, so they skip the prompt entirely and are unaffected — for
 * them the pre-existing guards (allowed environment + exact confirmation token
 * + verified non-empty backup file) remain the only gate, exactly as before.
 * --force exists so an operator can opt out of the prompt from a real terminal
 * without weakening any of those three checks.
 */
class CleanOperationalData extends Command
{
    protected $signature = 'oms:clean-operational-data
        {--dry-run : Audit only. Reports counts and plan. Performs zero database or file writes.}
        {--apply : Execute the cleanup. Requires --confirmation and --backup-file.}
        {--confirmation= : Must exactly equal ' . OperationalDataCleanupService::CONFIRMATION_TOKEN . '}
        {--backup-file= : Path to a verified, non-empty database backup file. Required for --apply.}
        {--skip-files : Skip physical attachment file deletion during --apply (database cleanup still runs).}
        {--force : Skip the interactive confirmation prompt. Does not bypass the environment, token or backup checks.}
        {--allow-production : Explicitly approve ONE run against a production database. Useless on its own — --apply, the exact --confirmation token and a freshly completed, verified full --backup-file are all still required.}';

    protected $description = 'Audit or permanently wipe ALL OMS operational data — projects, finance, Muwakha, '
        . 'partners, accounts, generated reports and operational attachments — while preserving settings/lookups, '
        . 'users, roles/permissions, migration history, audit history and backup history.';

    public function handle(OperationalDataCleanupService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun && $apply) {
            $this->error('Pass either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $apply) {
            $this->renderUsage();

            return self::INVALID;
        }

        if ($apply && ! $this->confirmDestruction()) {
            $this->warn('Aborted. No changes were made.');

            return self::INVALID;
        }

        try {
            $report = $dryRun
                ? $service->audit(allowProduction: (bool) $this->option('allow-production'))
                : $service->apply(
                    backupFilePath: (string) $this->option('backup-file'),
                    confirmationToken: (string) $this->option('confirmation'),
                    skipFiles: (bool) $this->option('skip-files'),
                    allowProduction: (bool) $this->option('allow-production'),
                );
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

    private function renderUsage(): void
    {
        $this->line($this->description);
        $this->newLine();
        $this->line('Usage:');
        $this->line('  php artisan oms:clean-operational-data --dry-run');
        $this->line('  php artisan oms:clean-operational-data --apply --confirmation='
            . OperationalDataCleanupService::CONFIRMATION_TOKEN . ' --backup-file=/path/to/backup');
        $this->newLine();
        $this->line('No changes were made.');
    }

    /**
     * Returns true when the run may proceed. Only ever prompts on a real
     * interactive terminal — see the class docblock on non-interactive
     * compatibility.
     */
    private function confirmDestruction(): bool
    {
        if ((bool) $this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        $this->newLine();
        $this->error('  WARNING — PERMANENT, IRREVERSIBLE DATA LOSS  ');
        $this->newLine();
        $this->line('ALL OMS operational data will be permanently removed from database "'
            . config('database.connections.' . config('database.default') . '.database') . '":');
        $this->newLine();
        $this->line('  DELETED: projects, projects_costs, project_cost_budgets, project_cost_budgets_payments,');
        $this->line('           project_cost_receipts, transactions, transaction_lines, general_expenses,');
        $this->line('           general_exchanges, partners (donors included), accounts, muwakha_families,');
        $this->line('           muwakha_family_accounts, muwakha_family_projects, project_financial_snapshots,');
        $this->line('           project_financial_snapshot_currency_totals, project_financial_alerts,');
        $this->line('           operational attachments (rows, and their files unless --skip-files).');
        $this->newLine();
        $this->line('  KEPT:    settings/lookups, users, roles, permissions, migrations, audit_events,');
        $this->line('           backup_operations, notifications.');
        $this->newLine();
        if (config('app.env') === OperationalDataCleanupService::PRODUCTION_ENVIRONMENT) {
            $this->error('  THIS IS THE PRODUCTION DATABASE — approved via --allow-production.  ');
            $this->newLine();
        }

        $this->line('This cannot be undone except by restoring a backup.');
        $this->newLine();

        return $this->confirm('Type yes to permanently delete all OMS operational data', false);
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
        $this->line('Operational tables — emptied by this reset (active / trashed):');
        foreach ($report->operationalCounts as $table => $counts) {
            $this->line("  {$table}: {$counts['active']} / {$counts['trashed']}");
        }

        $this->newLine();
        $this->line('Partner census (all of these are deleted):');
        $this->line("  total: {$report->partnerCensus['total']}");
        $this->line("  donors: {$report->partnerCensus['donors']}");
        $this->line("  non-donors: {$report->partnerCensus['non_donors']}");
        $this->line('  by partner type: ' . json_encode($report->partnerCensus['by_partner_type'], JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->line('Account census (all of these are deleted):');
        $this->line("  total accounts: {$report->accountCensus['total_accounts']}");
        $this->line("  non-zero balances: {$report->accountCensus['non_zero_balance_count']}");

        $this->renderAttachments($report);
        $this->renderSpecialCleanup($report);

        $this->newLine();
        $this->line('Deletion order (tables, child before parent):');
        foreach ($report->deletionOrder as $i => $table) {
            $this->line('  ' . ($i + 1) . ". {$table}");
        }
        $this->line('  ' . (count($report->deletionOrder) + 1) . '. attachments (operational attachable_type rows only)');
        $this->line('  ' . (count($report->deletionOrder) + 2) . '. settings (restore_e2e_* markers only)');
        $this->line('  ' . (count($report->deletionOrder) + 3) . '. model_has_roles / model_has_permissions (orphaned user rows only)');

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

        $this->newLine();
        if ($report->dryRun) {
            $this->info('Dry run performed zero database writes and zero file deletions.');
        } elseif ($report->isClean()) {
            $this->info('Post-apply verification: PASSED');
        } else {
            $this->error('Post-apply verification failures:');
            foreach ($report->verificationFailures as $failure) {
                $this->line("  - {$failure}");
            }
        }
    }

    private function renderAttachments(OperationalCleanupReport $report): void
    {
        $plan = $report->attachmentPlan;

        $this->newLine();
        $this->line('Attachment plan:');
        $this->line("  linked (DB) rows: {$plan['linked_db_count']}");
        $this->line('  linked by disk: ' . json_encode($plan['linked_by_disk']));
        $this->line("  linked existing files: {$plan['linked_existing_file_count']} ({$plan['linked_existing_file_bytes']} bytes)");
        $this->line('  linked missing files: ' . count($plan['linked_missing_files']));
        $this->line('  rows with an unapproved disk value: ' . count($plan['linked_invalid_disk_files']));
        $this->line('  disks covered by backup: ' . implode(', ', $plan['backup_covered_disks']));
        $this->line("  orphan files (report only, never deleted): {$plan['orphan_files']['count']} ({$plan['orphan_files']['bytes']} bytes)");

        if ($plan['not_covered_by_backup'] !== []) {
            $this->warn('  ' . count($plan['not_covered_by_backup']) . ' linked file(s) are NOT in the backup archive — '
                . 'apply() will refuse unless --skip-files is passed.');
        }

        if (isset($plan['fileDeletion'])) {
            $fd = $plan['fileDeletion'];
            if ($fd['skipped'] ?? false) {
                $this->line('  file deletion: skipped (--skip-files) — every file left on disk');
            } else {
                $this->line('  files deleted: ' . count($fd['deleted'] ?? []));
                $this->line('  files failed to delete: ' . count($fd['failed'] ?? []));
            }
        }
    }

    private function renderSpecialCleanup(OperationalCleanupReport $report): void
    {
        $plan = $report->specialCleanupPlan;
        $result = $report->specialCleanupResult;

        $this->newLine();
        $this->line('Special cleanup:');
        $this->line("  test settings prefix: {$plan['test_setting_key_prefix']}*");

        if ($report->dryRun || $result === []) {
            $this->line('  test settings to remove: ' . (count($plan['test_settings_to_remove']) ?: '0')
                . (count($plan['test_settings_to_remove']) ? ' — ' . implode(', ', $plan['test_settings_to_remove']) : ''));
            $this->line('  orphan model_has_roles to remove: ' . count($plan['orphan_model_has_roles_to_remove']));
            $this->line('  orphan model_has_permissions to remove: ' . count($plan['orphan_model_has_permissions_to_remove']));

            return;
        }

        $removedSettings = $result['removed_test_settings'] ?? [];
        $this->line('  test settings removed: ' . count($removedSettings)
            . (count($removedSettings) ? ' — ' . implode(', ', $removedSettings) : ''));
        $this->line('  orphan model_has_roles removed: ' . count($result['removed_orphan_model_has_roles'] ?? []));
        $this->line('  orphan model_has_permissions removed: ' . count($result['removed_orphan_model_has_permissions'] ?? []));
        $this->line('  remaining test settings: ' . count($plan['test_settings_to_remove']));
        $this->line('  remaining orphan assignments: '
            . (count($plan['orphan_model_has_roles_to_remove']) + count($plan['orphan_model_has_permissions_to_remove'])));
    }
}
