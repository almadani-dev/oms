<?php

namespace App\Services\Maintenance;

use App\Models\Attachment;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * THE complete definition of an OMS operational reset.
 *
 * Wipes every operational/business record — projects, finance, Muwakha,
 * partners, accounts, generated report data and operational attachments —
 * while preserving settings/lookups, users, roles/permissions, Laravel
 * migration history, audit history, backup history and backup notifications.
 *
 * audit() is fully read-only and safe to call anywhere the environment guard
 * allows. apply() performs real, irreversible deletion and must only ever be
 * invoked from the console command after its own confirmation-token, backup
 * and environment checks pass.
 *
 * CONTRACT CHANGE (this revision). `accounts` and `partners` were previously
 * on the preserve list, with apply() only zeroing accounts.current_balance.
 * They are now operational data and are deleted outright. The deletion order
 * below is what makes that safe: accounts is the target of three RESTRICT
 * foreign keys (transaction_lines.account_id, muwakha_families.account_id,
 * muwakha_family_accounts.account_id), so all three referencing tables are
 * emptied strictly before it. No row id is ever hard-coded — every step is
 * either a whole-table delete or a relationship/pattern predicate.
 *
 * Bulk deletion below uses DB::table()->delete() instead of Eloquent
 * forceDelete() for two reasons: (1) the query builder ignores the
 * SoftDeletes global scope, so a single statement removes both active and
 * trashed rows without a separate withTrashed()/forceDelete() pass, and
 * (2) it never fires the Project/ProjectCost/ProjectCostBudget/
 * ProjectCostBudgetsPayment/ProjectCostReceipt Eloquent observers, which
 * exist only to flip project_financial_snapshots.is_dirty — a no-op churn
 * here since those snapshot rows are deleted in the same operation anyway.
 */
class OperationalDataCleanupService
{
    public const ALLOWED_ENVIRONMENTS = ['local', 'development', 'testing'];

    public const CONFIRMATION_TOKEN = 'DELETE-OMS-OPERATIONAL-DATA';

    /**
     * The one environment name the narrow production approval can unlock. It is
     * deliberately NOT added to ALLOWED_ENVIRONMENTS: the global guard stays
     * exactly as it was, and this constant only names the single environment
     * that an explicit, per-invocation approval flag may override.
     */
    public const PRODUCTION_ENVIRONMENT = 'production';

    /**
     * How recently the backup must have completed for a production-approved
     * run. A production reset may only ride on a backup taken for this
     * operation, not on last week's scheduled archive.
     */
    public const PRODUCTION_BACKUP_MAX_AGE_MINUTES = 120;

    /** Fallback disk for legacy attachment rows written before attachments.disk existed. */
    public const ATTACHMENT_DISK = 'public';

    /**
     * Settings planted by the restore end-to-end harness. Matched as a
     * literal prefix against settings.key — never by id, and never broad
     * enough to reach a legitimate key (organization_name, base_currency,
     * fiscal_year_start, timezone, date_format).
     */
    public const TEST_SETTING_KEY_PREFIX = 'restore_e2e_20260727_';

    /**
     * Disks whose contents a `full`-scope OMS backup archive actually
     * contains. AttachmentCollector enumerates the private 'attachments'
     * disk only — storage/app/public is explicitly excluded from the archive
     * (see AttachmentCollector's docblock). Physical files on any disk NOT
     * listed here are unrecoverable once deleted, so apply() refuses to
     * delete them rather than silently losing them.
     */
    public const BACKUP_COVERED_DISKS = [Attachment::DISK_ATTACHMENTS];

    /** Directories scanned for orphan files on the public disk. Report-only; never deleted. */
    private const KNOWN_ATTACHMENT_DIRECTORIES = [
        'payments',
        'general-expenses',
        'receipts',
        'general-exchanges',
        'execution-payments',
    ];

    /** Model classes that own polymorphic Attachment rows — all of them operational. */
    private const OPERATIONAL_ATTACHABLE_TYPES = [
        ProjectCostBudget::class,
        ProjectCostBudgetsPayment::class,
        ProjectCostReceipt::class,
        GeneralExpense::class,
        GeneralExchange::class,
    ];

    /**
     * Child-before-parent execution order, derived from the real foreign keys
     * in the live schema rather than from the migrations' intent.
     *
     * `attachments` is deliberately absent: only rows whose attachable_type
     * is operational may be deleted, so it gets its own explicit step inside
     * the same transaction (see apply()).
     *
     * @var array<int, string>
     */
    private const DELETION_ORDER = [
        // Generated report data — CASCADE children of projects.
        'project_financial_alerts',
        'project_financial_snapshot_currency_totals',
        'project_financial_snapshots',
        // Muwakha link tables before their parent, and all three before accounts.
        'muwakha_family_projects',
        'muwakha_family_accounts',
        'muwakha_families',
        // Finance: lines before transactions, operation records before transactions.
        'transaction_lines',
        'project_cost_budgets_payments',
        'project_cost_receipts',
        'project_cost_budgets',
        'general_expenses',
        'general_exchanges',
        'transactions',
        // Project tree.
        'projects_costs',
        'projects',
        // Only safe once every SET NULL / RESTRICT referrer above is gone.
        'partners',
        'accounts',
    ];

    /**
     * Preserved tables: table => has a deleted_at column.
     *
     * `settings`, `model_has_roles` and `model_has_permissions` are preserved
     * tables from which specific rows are still removed (test markers and
     * orphaned assignments). verify() accounts for those expected deltas
     * explicitly instead of demanding an unchanged row count.
     */
    private const PRESERVED_TABLES = [
        // Authentication / authorization.
        'users' => true,
        'roles' => false,
        'permissions' => false,
        'model_has_roles' => false,
        'model_has_permissions' => false,
        'role_has_permissions' => false,
        // Settings / lookup data.
        'settings' => true,
        'currencies' => true,
        'exchange_rate_histories' => true,
        'fiscal_years' => true,
        'accounts_type' => true,
        'bank_types' => true,
        'partners_types' => true,
        'projects_status' => true,
        'projects_super' => true,
        'transaction_super_types' => true,
        'transactions_types' => true,
        // Laravel / system history.
        'migrations' => false,
        // History tables.
        'audit_events' => false,
        'backup_operations' => true,
        // Backup notifications — backup history, not business data.
        'notifications' => false,
    ];

    /** Operational tables emptied by apply(): table => has a deleted_at column. */
    private const OPERATIONAL_TABLES = [
        'projects' => true,
        'projects_costs' => true,
        'project_cost_budgets' => true,
        'project_cost_budgets_payments' => true,
        'project_cost_receipts' => true,
        'transactions' => true,
        'transaction_lines' => true,
        'general_expenses' => true,
        'general_exchanges' => true,
        'partners' => true,
        'accounts' => true,
        'muwakha_families' => true,
        'muwakha_family_accounts' => false,
        'muwakha_family_projects' => false,
        'project_financial_snapshots' => false,
        'project_financial_snapshot_currency_totals' => false,
        'project_financial_alerts' => false,
    ];

    /**
     * Cleaned selectively, never emptied wholesale — so it belongs to neither
     * the preserved nor the operational list, but is still a known table.
     */
    private const PARTIALLY_CLEANED_TABLES = ['attachments'];

    /** Laravel/queue/session internals present in the schema but outside this reset. */
    private const FRAMEWORK_TABLES = [
        'cache', 'cache_locks', 'sessions', 'failed_jobs', 'job_batches',
        'jobs', 'password_reset_tokens',
    ];

    public function audit(bool $allowProduction = false): OperationalCleanupReport
    {
        $this->assertEnvironmentAllowed($allowProduction);

        return $this->buildReport(dryRun: true, writesPerformed: false);
    }

    /**
     * Executes the destructive cleanup. Must never be called against a
     * non-local/development/testing environment, without the exact
     * confirmation token, or without a verified, non-empty backup file.
     */
    public function apply(
        string $backupFilePath,
        string $confirmationToken,
        bool $skipFiles = false,
        bool $allowProduction = false,
    ): OperationalCleanupReport {
        // Every one of these must pass. In production all four are required:
        // the approval flag (here), the exact token, a non-empty backup file,
        // and that file being a freshly completed + verified full backup.
        $this->assertEnvironmentAllowed($allowProduction);
        $this->assertConfirmationToken($confirmationToken);
        $this->assertBackupFile($backupFilePath);

        if (config('app.env') === self::PRODUCTION_ENVIRONMENT) {
            $this->assertProductionBackupIsFreshAndVerified($backupFilePath);
        }

        $before = $this->buildReport(dryRun: false, writesPerformed: false);

        $linkedFiles = $this->collectLinkedAttachmentFiles();

        if (! $skipFiles) {
            $this->assertLinkedFilesAreRecoverable($linkedFiles);
        }

        // Filesystem deletion is deliberately OUTSIDE this transaction: a DB
        // rollback cannot restore a deleted file, so wrapping both together
        // would imply an atomicity that does not exist. The database half is
        // atomic; the file half runs only after it has committed.
        $specialCleanup = DB::transaction(function (): array {
            foreach (self::DELETION_ORDER as $table) {
                DB::table($table)->delete();
            }

            DB::table('attachments')
                ->whereIn('attachable_type', self::OPERATIONAL_ATTACHABLE_TYPES)
                ->delete();

            return [
                'removed_test_settings' => $this->deleteTestSettings(),
                'removed_orphan_model_has_roles' => $this->deleteOrphanedUserAssignments('model_has_roles'),
                'removed_orphan_model_has_permissions' => $this->deleteOrphanedUserAssignments('model_has_permissions'),
            ];
        });

        $fileReport = $skipFiles
            ? ['skipped' => true, 'deleted' => [], 'failed' => []]
            : $this->deleteLinkedFiles($linkedFiles);

        $after = $this->buildReport(dryRun: false, writesPerformed: true);

        return new OperationalCleanupReport(
            environment: $after->environment,
            databaseName: $after->databaseName,
            performedAt: $after->performedAt,
            dryRun: false,
            writesPerformed: true,
            preservedCounts: $after->preservedCounts,
            operationalCounts: $after->operationalCounts,
            partnerCensus: $after->partnerCensus,
            accountCensus: $after->accountCensus,
            attachmentPlan: array_merge($after->attachmentPlan, ['fileDeletion' => $fileReport]),
            deletionOrder: $after->deletionOrder,
            specialCleanupPlan: $after->specialCleanupPlan,
            specialCleanupResult: $specialCleanup,
            risks: $after->risks,
            unexpectedOperationalTables: $after->unexpectedOperationalTables,
            fingerprints: $after->fingerprints,
            numbering: $after->numbering,
            verificationFailures: $this->verify($before, $after, $specialCleanup),
        );
    }

    /**
     * The global environment guard, unchanged: ALLOWED_ENVIRONMENTS still lists
     * only local/development/testing, and nothing has been removed from it.
     *
     * $allowProduction is the narrowest possible escape hatch — a single
     * boolean, passed per invocation, that unlocks exactly one environment
     * ('production') for exactly this service. It is not read from config, not
     * cached, not an env var, and has no effect on any other environment name:
     * a 'staging' database is still refused even with the flag set. Callers
     * reach it only through the --allow-production option on
     * oms:clean-operational-data, and in production it is worthless on its own
     * (see apply(), which additionally demands the exact confirmation token and
     * a freshly completed, verified full backup).
     */
    private function assertEnvironmentAllowed(bool $allowProduction = false): void
    {
        $env = config('app.env');

        if (in_array($env, self::ALLOWED_ENVIRONMENTS, true)) {
            return;
        }

        if ($allowProduction && $env === self::PRODUCTION_ENVIRONMENT) {
            return;
        }

        $hint = $env === self::PRODUCTION_ENVIRONMENT
            ? " Pass --allow-production to explicitly approve this one production run."
            : '';

        throw new RuntimeException(
            "Refusing to run: environment '{$env}' is not one of ["
            . implode(', ', self::ALLOWED_ENVIRONMENTS) . '].' . $hint
        );
    }

    /**
     * Production-only precondition: the --backup-file must be a real
     * backup_operations archive that completed, was verified, covers the whole
     * system (scope=full) and was taken for this operation rather than being a
     * stale scheduled archive.
     */
    private function assertProductionBackupIsFreshAndVerified(string $path): void
    {
        $storedPath = basename($path);

        $operation = DB::table('backup_operations')
            ->where('stored_path', $storedPath)
            ->orderByDesc('id')
            ->first();

        if ($operation === null) {
            throw new RuntimeException(
                "Refusing to run: no backup_operations row matches '{$storedPath}'. A production reset must "
                . 'ride on a backup this system created and recorded.'
            );
        }

        if ($operation->status !== 'completed') {
            throw new RuntimeException("Refusing to run: backup {$operation->uuid} has status '{$operation->status}', expected 'completed'.");
        }

        if ($operation->verified_at === null) {
            throw new RuntimeException("Refusing to run: backup {$operation->uuid} has no verified_at timestamp.");
        }

        if ($operation->scope !== 'full') {
            throw new RuntimeException("Refusing to run: backup {$operation->uuid} has scope '{$operation->scope}', expected 'full'.");
        }

        if ((int) $operation->size_bytes <= 0) {
            throw new RuntimeException("Refusing to run: backup {$operation->uuid} records a size of {$operation->size_bytes} bytes.");
        }

        $completedAt = $operation->completed_at ?? $operation->verified_at;
        $ageMinutes = CarbonImmutable::parse($completedAt)->diffInMinutes(CarbonImmutable::now());

        if ($ageMinutes > self::PRODUCTION_BACKUP_MAX_AGE_MINUTES) {
            throw new RuntimeException(
                "Refusing to run: backup {$operation->uuid} completed {$ageMinutes} minutes ago, which exceeds the "
                . self::PRODUCTION_BACKUP_MAX_AGE_MINUTES . '-minute freshness limit for a production reset. '
                . 'Create a new backup with: php artisan oms:backup --type=manual --scope=full --sync'
            );
        }
    }

    private function assertConfirmationToken(string $token): void
    {
        if (! hash_equals(self::CONFIRMATION_TOKEN, $token)) {
            throw new RuntimeException('Refusing to run: confirmation token does not match.');
        }
    }

    private function assertBackupFile(string $path): void
    {
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException("Refusing to run: backup file '{$path}' does not exist.");
        }

        if (filesize($path) <= 0) {
            throw new RuntimeException("Refusing to run: backup file '{$path}' is empty.");
        }
    }

    /**
     * Fails closed when a physical file slated for deletion lives on a disk
     * the backup archive does not contain. Deleting it would be unrecoverable
     * data loss, so the operator must either extend backup coverage or pass
     * --skip-files (database-only cleanup, files left untouched on disk).
     *
     * @param  array<int, array{id: int, file_path: string, disk: string}>  $files
     */
    private function assertLinkedFilesAreRecoverable(array $files): void
    {
        $unrecoverable = [];

        foreach ($files as $file) {
            if (in_array($file['disk'], self::BACKUP_COVERED_DISKS, true)) {
                continue;
            }

            if (! $this->fileExists($file['disk'], $file['file_path'])) {
                continue;
            }

            $unrecoverable[$file['disk']] = ($unrecoverable[$file['disk']] ?? 0) + 1;
        }

        if ($unrecoverable === []) {
            return;
        }

        $summary = [];

        foreach ($unrecoverable as $disk => $count) {
            $summary[] = "{$disk} ({$count} file(s))";
        }

        throw new RuntimeException(
            'Refusing to run: ' . implode(', ', $summary) . ' hold attachment files that the backup archive '
            . 'does not contain (covered disks: ' . implode(', ', self::BACKUP_COVERED_DISKS) . '). '
            . 'Deleting them would be unrecoverable. Re-run with --skip-files to clean the database only, '
            . 'or extend backup coverage to those disks first.'
        );
    }

    /**
     * Hard-deletes the restore E2E marker settings by key prefix. Uses the
     * query builder so a soft-deleted marker is removed too.
     *
     * @return array<int, string> the keys actually removed
     */
    private function deleteTestSettings(): array
    {
        $keys = $this->findTestSettingKeys();

        if ($keys !== []) {
            DB::table('settings')->whereIn('key', $keys)->delete();
        }

        return $keys;
    }

    /**
     * @return array<int, string>
     */
    private function findTestSettingKeys(): array
    {
        return DB::table('settings')
            ->where('key', 'like', self::TEST_SETTING_KEY_PREFIX . '%')
            ->orderBy('key')
            ->pluck('key')
            ->all();
    }

    /**
     * Removes Spatie assignment rows pointing at a User id that no longer
     * exists. Relationship-based: the predicate is "model_type is the User
     * morph class AND no users row has that id" — never a literal id list.
     * users has SoftDeletes, but a soft-deleted user still occupies its row,
     * so it is correctly NOT treated as an orphan.
     *
     * @return array<int, array<string, mixed>> the rows actually removed
     */
    private function deleteOrphanedUserAssignments(string $table): array
    {
        $orphans = $this->findOrphanedUserAssignments($table);

        foreach ($orphans as $orphan) {
            DB::table($table)
                ->where('model_type', $orphan['model_type'])
                ->where('model_id', $orphan['model_id'])
                ->delete();
        }

        return $orphans;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findOrphanedUserAssignments(string $table): array
    {
        $morphClass = (new User())->getMorphClass();

        return DB::table($table)
            ->where('model_type', $morphClass)
            ->whereNotExists(function ($query) use ($table) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', $table . '.model_id');
            })
            ->orderBy('model_id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function buildReport(bool $dryRun, bool $writesPerformed): OperationalCleanupReport
    {
        return new OperationalCleanupReport(
            environment: (string) config('app.env'),
            databaseName: (string) config('database.connections.' . config('database.default') . '.database'),
            performedAt: CarbonImmutable::now(),
            dryRun: $dryRun,
            writesPerformed: $writesPerformed,
            preservedCounts: $this->countTables(self::PRESERVED_TABLES),
            operationalCounts: $this->countTables(self::OPERATIONAL_TABLES),
            partnerCensus: $this->buildPartnerCensus(),
            accountCensus: $this->buildAccountCensus(),
            attachmentPlan: $this->buildAttachmentPlan(),
            deletionOrder: self::DELETION_ORDER,
            specialCleanupPlan: $this->buildSpecialCleanupPlan(),
            specialCleanupResult: [],
            risks: $this->buildRisks(),
            unexpectedOperationalTables: $this->findUnexpectedTables(),
            fingerprints: $this->buildFingerprints(),
            numbering: $this->buildNumberingReport(),
        );
    }

    /**
     * @param  array<string, bool>  $tables
     * @return array<string, array{active: int, trashed: int}>
     */
    private function countTables(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table => $hasSoftDeletes) {
            $total = DB::table($table)->count();

            $active = $hasSoftDeletes
                ? DB::table($table)->whereNull('deleted_at')->count()
                : $total;

            $counts[$table] = ['active' => $active, 'trashed' => $total - $active];
        }

        return $counts;
    }

    /**
     * Census of the partner rows this reset destroys, split by the only
     * authoritative classification field in the schema (`is_donor`). Recorded
     * because donors were preserved by the previous contract and are not
     * preserved any more — the operator should be able to see, in the report,
     * exactly what is being given up.
     *
     * @return array<string, mixed>
     */
    private function buildPartnerCensus(): array
    {
        $byType = DB::table('partners')
            ->leftJoin('partners_types', 'partners_types.id', '=', 'partners.partner_type_id')
            ->selectRaw('partners_types.name as type_name, count(*) as total')
            ->groupBy('partners_types.name')
            ->pluck('total', 'type_name')
            ->all();

        return [
            'total' => DB::table('partners')->count(),
            'donors' => DB::table('partners')->where('is_donor', true)->count(),
            'non_donors' => DB::table('partners')->where('is_donor', false)->count(),
            'by_partner_type' => $byType,
            'note' => 'All partner rows — donors included — are deleted by this reset. partners_types '
                . '(the lookup table) is preserved.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAccountCensus(): array
    {
        $accounts = DB::table('accounts')->orderBy('id')->get(['id', 'current_balance']);

        return [
            'total_accounts' => $accounts->count(),
            'non_zero_balance_count' => $accounts->filter(fn ($a) => (float) $a->current_balance !== 0.0)->count(),
            'note' => 'All account rows are deleted by this reset — balances are not zeroed in place any '
                . 'more. accounts_type, bank_types and currencies (the lookups) are preserved.',
        ];
    }

    /**
     * Resolves each attachment row against the disk recorded on the row
     * itself, falling back to the legacy public disk for rows written before
     * the `disk` column existed. An unapproved disk value is never handed to
     * Storage::disk() — it is reported instead.
     *
     * @return array<string, mixed>
     */
    private function buildAttachmentPlan(): array
    {
        $linked = $this->collectLinkedAttachmentFiles();

        $existingCount = 0;
        $existingBytes = 0;
        $missing = [];
        $byDisk = [];
        $unrecoverable = [];
        $invalidDisk = [];

        foreach ($linked as $file) {
            $disk = $file['disk'];
            $byDisk[$disk] = ($byDisk[$disk] ?? 0) + 1;

            if (! in_array($disk, Attachment::APPROVED_DISKS, true)) {
                $invalidDisk[] = ['path' => $file['file_path'], 'disk' => $disk];

                continue;
            }

            if (! $this->fileExists($disk, $file['file_path'])) {
                $missing[] = ['path' => $file['file_path'], 'disk' => $disk];

                continue;
            }

            $existingCount++;
            $existingBytes += (int) Storage::disk($disk)->size($file['file_path']);

            if (! in_array($disk, self::BACKUP_COVERED_DISKS, true)) {
                $unrecoverable[] = ['path' => $file['file_path'], 'disk' => $disk];
            }
        }

        return [
            'linked_db_count' => count($linked),
            'linked_by_disk' => $byDisk,
            'linked_existing_file_count' => $existingCount,
            'linked_existing_file_bytes' => $existingBytes,
            'linked_missing_files' => $missing,
            'linked_invalid_disk_files' => $invalidDisk,
            'backup_covered_disks' => self::BACKUP_COVERED_DISKS,
            'not_covered_by_backup' => $unrecoverable,
            'orphan_files' => $this->findOrphanFiles(),
        ];
    }

    /**
     * Files on disk with no attachments row. Report-only — never deleted, on
     * any disk, by any code path in this service.
     *
     * @return array<string, mixed>
     */
    private function findOrphanFiles(): array
    {
        $knownPaths = DB::table('attachments')
            ->select(['file_path', 'disk'])
            ->get()
            ->map(fn ($row) => ($row->disk ?: self::ATTACHMENT_DISK) . '::' . $row->file_path)
            ->all();

        $orphans = [];
        $bytes = 0;

        foreach ($this->orphanScanTargets() as $disk => $directories) {
            foreach ($directories as $directory) {
                foreach ($this->safeAllFiles($disk, $directory) as $path) {
                    if (in_array($disk . '::' . $path, $knownPaths, true)) {
                        continue;
                    }

                    $orphans[] = ['path' => $path, 'disk' => $disk];
                    $bytes += (int) Storage::disk($disk)->size($path);
                }
            }
        }

        return ['count' => count($orphans), 'bytes' => $bytes, 'files' => $orphans];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function orphanScanTargets(): array
    {
        return [
            Attachment::DISK_PUBLIC => self::KNOWN_ATTACHMENT_DIRECTORIES,
            // The private attachments disk holds nothing but attachments, so
            // its whole root is scanned rather than a directory allowlist.
            Attachment::DISK_ATTACHMENTS => [''],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function safeAllFiles(string $disk, string $directory): array
    {
        try {
            return Storage::disk($disk)->allFiles($directory);
        } catch (\Throwable) {
            // A disk that is not configured in this environment (or a missing
            // root directory) is reported as simply holding no files, rather
            // than aborting a read-only audit.
            return [];
        }
    }

    private function fileExists(string $disk, string $path): bool
    {
        if (! in_array($disk, Attachment::APPROVED_DISKS, true)) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSpecialCleanupPlan(): array
    {
        return [
            'test_setting_key_prefix' => self::TEST_SETTING_KEY_PREFIX,
            'test_settings_to_remove' => $this->findTestSettingKeys(),
            'orphan_model_has_roles_to_remove' => $this->findOrphanedUserAssignments('model_has_roles'),
            'orphan_model_has_permissions_to_remove' => $this->findOrphanedUserAssignments('model_has_permissions'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildRisks(): array
    {
        return [
            'accounts is the target of three RESTRICT foreign keys — transaction_lines.account_id, '
                . 'muwakha_families.account_id and muwakha_family_accounts.account_id. All three referencing '
                . 'tables are emptied earlier in DELETION_ORDER, which is the only reason deleting accounts '
                . 'succeeds without disabling foreign-key enforcement. Reordering those steps breaks the reset.',
            'partners is referenced only by nullOnDelete foreign keys (projects.donor_id, '
                . 'transactions.partner_id, general_expenses.partner_id, general_exchanges.partner_id). It is '
                . 'still deleted after all four referencing tables, so no SET NULL write ever touches a row '
                . 'that is about to be deleted anyway.',
            'RESTRICT foreign keys pointing from operational tables INTO preserved lookups '
                . '(transaction_lines.currency_id, transactions.fiscal_year_id/transaction_type_id, '
                . 'projects.currency_id/project_status_id, accounts.account_type_id/currency_id, '
                . 'partners.partner_type_id) are all safe: the parent lookup rows are never deleted.',
            'projects_costs, project_cost_budgets, project_cost_receipts, transaction_lines, '
                . 'muwakha_family_accounts, muwakha_family_projects, project_financial_snapshots, '
                . 'project_financial_snapshot_currency_totals and project_financial_alerts CASCADE at the DB '
                . 'level from their parents, but deletion is still explicit and child-first so behavior is '
                . 'deterministic regardless of engine-level FK enforcement (the app runs on MySQL, but tests '
                . 'run on SQLite, which only cascades when foreign_keys=ON).',
            'Project, ProjectCost, ProjectCostBudget, ProjectCostBudgetsPayment and ProjectCostReceipt each '
                . 'have an Eloquent observer that flips project_financial_snapshots.is_dirty on delete/restore. '
                . 'Bulk deletion uses DB::table()->delete() specifically to avoid firing these observers at '
                . 'scale, since the snapshot rows they would touch are deleted in the same operation anyway. '
                . 'Nothing in this service regenerates a snapshot afterwards.',
            'Physical attachment files on a disk outside BACKUP_COVERED_DISKS are NOT in the backup archive. '
                . 'apply() refuses rather than deleting them; --skip-files cleans the database only and leaves '
                . 'every file on disk. No code path here deletes an orphan file.',
            'settings, model_has_roles and model_has_permissions are preserved tables from which specific '
                . 'rows are still removed. verify() checks their row counts against an expected delta, so a '
                . 'deletion wider than the intended predicate is caught instead of silently passing.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function findUnexpectedTables(): array
    {
        $connectionDatabase = config('database.connections.' . config('database.default') . '.database');

        $omsTables = collect(Schema::getTables())
            ->filter(fn ($table) => ! isset($table['schema']) || $table['schema'] === $connectionDatabase)
            ->pluck('name')
            ->all();

        $known = array_merge(
            array_keys(self::PRESERVED_TABLES),
            array_keys(self::OPERATIONAL_TABLES),
            self::PARTIALLY_CLEANED_TABLES,
            self::FRAMEWORK_TABLES,
        );

        return array_values(array_diff($omsTables, $known));
    }

    /**
     * Identity of everything this reset must NOT change, captured before and
     * after apply() and compared by verify(). Accounts and donors used to be
     * fingerprinted here; they are deleted now, so fingerprinting them would
     * assert the opposite of the current contract.
     *
     * @return array<string, mixed>
     */
    private function buildFingerprints(): array
    {
        $currencies = DB::table('currencies')->orderBy('id')
            ->get(['id', 'code', 'is_base']);

        $exchangeRateChecksum = (string) DB::table('exchange_rate_histories')
            ->orderBy('id')
            ->pluck('rate')
            ->reduce(static fn ($carry, $rate) => bcadd((string) $carry, (string) $rate, 6), '0');

        return [
            'currencies' => $currencies->map(fn ($c) => (array) $c)->all(),
            'exchange_rate_history_count' => DB::table('exchange_rate_histories')->count(),
            'exchange_rate_history_checksum' => $exchangeRateChecksum,
            'legitimate_setting_keys' => DB::table('settings')
                ->where('key', 'not like', self::TEST_SETTING_KEY_PREFIX . '%')
                ->orderBy('key')
                ->pluck('key')
                ->all(),
            'user_count' => DB::table('users')->count(),
            'user_ids' => DB::table('users')->orderBy('id')->pluck('id')->all(),
            'role_count' => DB::table('roles')->count(),
            'permission_count' => DB::table('permissions')->count(),
            'role_has_permissions_count' => DB::table('role_has_permissions')->count(),
            'valid_user_role_assignments' => $this->validUserAssignments('model_has_roles'),
            'valid_user_permission_assignments' => $this->validUserAssignments('model_has_permissions'),
            'audit_event_count' => DB::table('audit_events')->count(),
            'backup_operation_count' => DB::table('backup_operations')->count(),
            'notification_count' => DB::table('notifications')->count(),
            'migration_count' => DB::table('migrations')->count(),
        ];
    }

    /**
     * Assignment rows whose User actually exists — the ones that must survive.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validUserAssignments(string $table): array
    {
        $morphClass = (new User())->getMorphClass();
        $pivotColumn = $table === 'model_has_roles' ? 'role_id' : 'permission_id';

        return DB::table($table)
            ->where('model_type', $morphClass)
            ->whereExists(function ($query) use ($table) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', $table . '.model_id');
            })
            ->orderBy('model_id')
            ->orderBy($pivotColumn)
            ->get([$pivotColumn, 'model_id'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Report-only. Nothing here resets any identity/sequence.
     *
     * @return array<string, mixed>
     */
    private function buildNumberingReport(): array
    {
        return [
            'mechanism' => 'custom, not DB AUTO_INCREMENT and not a stored sequence table',
            'transactions.transaction_number' => 'Built per-prefix from MAX() of existing numeric suffixes, '
                . 'including soft-deleted rows. Deleting all transactions naturally restarts each prefix at '
                . '001 the next time a transaction is created, purely because there are no remaining rows to '
                . 'MAX() over — no explicit reset is implemented or needed.',
            'projects.code / projects_super.code' => 'Built from withTrashed()->count() of existing rows for '
                . 'the same prefix (see Project::boot and ProjectSuper::boot). Deleting all projects restarts '
                . 'project numbering the same way; projects_super rows are PRESERVED, so super codes continue '
                . 'from their current high-water mark.',
            'database_auto_increment' => 'Primary keys (id columns) use ordinary DB AUTO_INCREMENT and are '
                . 'NOT reset by this cleanup — ids continue climbing from their current high-water mark.',
            'fiscal_year_based_numbering' => false,
            'stored_sequence_tables' => false,
            'recommendation' => 'Resetting AUTO_INCREMENT counters is a separate, optional decision and is '
                . 'deliberately not implemented here.',
        ];
    }

    /**
     * @return array<int, array{id: int, file_path: string, disk: string}>
     */
    private function collectLinkedAttachmentFiles(): array
    {
        return DB::table('attachments')
            ->whereIn('attachable_type', self::OPERATIONAL_ATTACHABLE_TYPES)
            ->orderBy('id')
            ->get(['id', 'file_path', 'disk'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'file_path' => (string) $row->file_path,
                'disk' => (string) ($row->disk ?: self::ATTACHMENT_DISK),
            ])
            ->all();
    }

    /**
     * @param  array<int, array{id: int, file_path: string, disk: string}>  $files
     * @return array<string, mixed>
     */
    private function deleteLinkedFiles(array $files): array
    {
        $deleted = [];
        $failed = [];

        foreach ($files as $file) {
            $disk = $file['disk'];
            $path = $file['file_path'];

            if (! $this->fileExists($disk, $path)) {
                continue;
            }

            if (Storage::disk($disk)->delete($path)) {
                $deleted[] = ['path' => $path, 'disk' => $disk];
            } else {
                $failed[] = ['path' => $path, 'disk' => $disk];
            }
        }

        return ['skipped' => false, 'deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $specialCleanup
     * @return array<int, string>
     */
    private function verify(OperationalCleanupReport $before, OperationalCleanupReport $after, array $specialCleanup): array
    {
        $failures = [];

        $expectedDeltas = [
            'settings' => count($specialCleanup['removed_test_settings'] ?? []),
            'model_has_roles' => count($specialCleanup['removed_orphan_model_has_roles'] ?? []),
            'model_has_permissions' => count($specialCleanup['removed_orphan_model_has_permissions'] ?? []),
        ];

        foreach (array_keys(self::PRESERVED_TABLES) as $table) {
            $beforeTotal = $before->preservedCounts[$table]['active'] + $before->preservedCounts[$table]['trashed'];
            $afterTotal = $after->preservedCounts[$table]['active'] + $after->preservedCounts[$table]['trashed'];
            $expected = $beforeTotal - ($expectedDeltas[$table] ?? 0);

            if ($afterTotal !== $expected) {
                $failures[] = "Preserved table '{$table}' has {$afterTotal} rows after apply, expected {$expected}.";
            }
        }

        foreach (self::OPERATIONAL_TABLES as $table => $hasSoftDeletes) {
            $counts = $after->operationalCounts[$table];

            if ($counts['active'] !== 0 || $counts['trashed'] !== 0) {
                $failures[] = "Operational table '{$table}' still has rows after apply (active={$counts['active']}, trashed={$counts['trashed']}).";
            }
        }

        if ($after->attachmentPlan['linked_db_count'] !== 0) {
            $failures[] = 'Operational attachment rows still present after apply ('
                . $after->attachmentPlan['linked_db_count'] . ').';
        }

        foreach ([
            'currencies' => 'Currency records changed during apply.',
            'exchange_rate_history_count' => 'Exchange rate history row count changed during apply.',
            'exchange_rate_history_checksum' => 'Exchange rate history contents changed during apply.',
            'legitimate_setting_keys' => 'Legitimate settings keys changed during apply.',
            'user_ids' => 'User records changed during apply.',
            'role_count' => 'Role records changed during apply.',
            'permission_count' => 'Permission records changed during apply.',
            'role_has_permissions_count' => 'Role/permission assignments changed during apply.',
            'valid_user_role_assignments' => 'Valid user role assignments changed during apply.',
            'valid_user_permission_assignments' => 'Valid user permission assignments changed during apply.',
            'audit_event_count' => 'Audit history changed during apply.',
            'backup_operation_count' => 'Backup history changed during apply.',
            'notification_count' => 'Notification history changed during apply.',
            'migration_count' => 'Laravel migration history changed during apply.',
        ] as $key => $message) {
            if ($before->fingerprints[$key] !== $after->fingerprints[$key]) {
                $failures[] = $message;
            }
        }

        if ($after->specialCleanupPlan['test_settings_to_remove'] !== []) {
            $failures[] = 'Test marker settings still present after apply: '
                . implode(', ', $after->specialCleanupPlan['test_settings_to_remove']);
        }

        if ($after->specialCleanupPlan['orphan_model_has_roles_to_remove'] !== []) {
            $failures[] = 'Orphaned model_has_roles rows still present after apply.';
        }

        if ($after->specialCleanupPlan['orphan_model_has_permissions_to_remove'] !== []) {
            $failures[] = 'Orphaned model_has_permissions rows still present after apply.';
        }

        return $failures;
    }
}
