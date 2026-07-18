<?php

namespace App\Services\Maintenance;

use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Wipes operational/transactional data from the OMS development database
 * while preserving system configuration, reference data, donors, accounts,
 * currencies, exchange-rate history, and users/roles/permissions.
 *
 * audit() is fully read-only and safe to call anywhere the environment guard
 * allows. apply() performs real, irreversible deletion and must only ever be
 * invoked from the console command after its own confirmation-token, backup
 * and environment checks pass.
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

    public const ATTACHMENT_DISK = 'public';

    /** Directories under the public disk that operational attachments are stored in. */
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

    /** Child-before-parent order, verified against actual FK constraints in the migrations. */
    private const DELETION_ORDER = [
        'project_financial_alerts',
        'project_financial_snapshot_currency_totals',
        'project_financial_snapshots',
        'transaction_lines',
        'project_cost_budgets_payments',
        'project_cost_receipts',
        'project_cost_budgets',
        'general_expenses',
        'general_exchanges',
        'transactions',
        'projects_costs',
        'projects',
        'attachments (rows linked to the operational records deleted above)',
        'accounts.current_balance reset to 0 (no account rows deleted)',
    ];

    /** table => has a deleted_at column. */
    private const PRESERVED_TABLES = [
        'users' => true,
        'roles' => false,
        'permissions' => false,
        'model_has_roles' => false,
        'model_has_permissions' => false,
        'role_has_permissions' => false,
        'accounts' => true,
        'accounts_type' => true,
        'bank_types' => true,
        'currencies' => true,
        'exchange_rate_histories' => true,
        'fiscal_years' => true,
        'transactions_types' => true,
        'transaction_super_types' => true,
        'partners_types' => true,
        'partners' => true,
        'projects_super' => true,
        'projects_status' => true,
        'settings' => true,
        'migrations' => false,
    ];

    /** table => has a deleted_at column. */
    private const OPERATIONAL_TABLES = [
        'projects' => true,
        'projects_costs' => true,
        'project_cost_budgets' => true,
        'project_cost_budgets_payments' => true,
        'project_cost_receipts' => true,
        'general_expenses' => true,
        'general_exchanges' => true,
        'transactions' => true,
        'transaction_lines' => true,
        'attachments' => true,
        'project_financial_snapshots' => false,
        'project_financial_snapshot_currency_totals' => false,
        'project_financial_alerts' => false,
    ];

    /** Laravel/queue/session internals present in the schema but irrelevant to this audit. */
    private const FRAMEWORK_TABLES = [
        'cache', 'cache_locks', 'sessions', 'failed_jobs', 'job_batches',
        'jobs', 'password_reset_tokens',
    ];

    public function audit(): OperationalCleanupReport
    {
        $this->assertEnvironmentAllowed();

        return $this->buildReport(dryRun: true, writesPerformed: false);
    }

    /**
     * Executes the destructive cleanup. Must never be called against a
     * non-local/development/testing environment, without the exact
     * confirmation token, or without a verified, non-empty backup file.
     */
    public function apply(string $backupFilePath, string $confirmationToken, bool $skipFiles = false): OperationalCleanupReport
    {
        $this->assertEnvironmentAllowed();
        $this->assertConfirmationToken($confirmationToken);
        $this->assertBackupFile($backupFilePath);

        $before = $this->buildReport(dryRun: false, writesPerformed: false);

        $linkedFiles = $this->collectLinkedAttachmentFiles();

        DB::transaction(function () {
            foreach (self::DELETION_ORDER as $entry) {
                if (! array_key_exists($entry, self::OPERATIONAL_TABLES)) {
                    continue;
                }

                DB::table($entry)->delete();
            }

            DB::table('attachments')->whereIn('attachable_type', self::OPERATIONAL_ATTACHABLE_TYPES)->delete();

            DB::table('accounts')->update(['current_balance' => 0]);
        });

        $fileReport = $skipFiles
            ? ['skipped' => true, 'deleted' => [], 'failed' => []]
            : $this->deleteLinkedFiles($linkedFiles);

        $after = $this->buildReport(dryRun: false, writesPerformed: true);

        $verificationFailures = $this->verify($before, $after);

        return new OperationalCleanupReport(
            environment: $after->environment,
            databaseName: $after->databaseName,
            performedAt: $after->performedAt,
            dryRun: false,
            writesPerformed: true,
            preservedCounts: $after->preservedCounts,
            operationalCounts: $after->operationalCounts,
            entityClassification: $after->entityClassification,
            attachmentPlan: array_merge($after->attachmentPlan, ['fileDeletion' => $fileReport]),
            deletionOrder: $after->deletionOrder,
            risks: $after->risks,
            unexpectedOperationalTables: $after->unexpectedOperationalTables,
            fingerprints: $after->fingerprints,
            accountBalances: $after->accountBalances,
            numbering: $after->numbering,
            verificationFailures: $verificationFailures,
        );
    }

    private function assertEnvironmentAllowed(): void
    {
        $env = config('app.env');

        if (! in_array($env, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException("Refusing to run: environment '{$env}' is not one of [" . implode(', ', self::ALLOWED_ENVIRONMENTS) . '].');
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
            entityClassification: $this->buildEntityClassification(),
            attachmentPlan: $this->buildAttachmentPlan(),
            deletionOrder: self::DELETION_ORDER,
            risks: $this->buildRisks(),
            unexpectedOperationalTables: $this->findUnexpectedTables(),
            fingerprints: $this->buildFingerprints(),
            accountBalances: $this->buildAccountBalances(),
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

            if ($hasSoftDeletes) {
                $active = DB::table($table)->whereNull('deleted_at')->count();
            } else {
                $active = $total;
            }

            $counts[$table] = ['active' => $active, 'trashed' => $total - $active];
        }

        return $counts;
    }

    /**
     * Partner classification uses only the authoritative `is_donor` boolean.
     * There is no association/beneficiary/vendor flag in the schema distinct
     * from is_donor, so every non-donor partner is reported as
     * unknown/unclassified and preserved — never a deletion candidate.
     *
     * @return array<string, mixed>
     */
    private function buildEntityClassification(): array
    {
        $donors = DB::table('partners')->where('is_donor', true)->count();
        $nonDonors = DB::table('partners')->where('is_donor', false)->count();

        $nonDonorsByType = DB::table('partners')
            ->join('partners_types', 'partners_types.id', '=', 'partners.partner_type_id')
            ->where('partners.is_donor', false)
            ->selectRaw('partners_types.name as type_name, count(*) as total')
            ->groupBy('partners_types.name')
            ->pluck('total', 'type_name')
            ->all();

        return [
            'donors' => $donors,
            'association_system_entities' => 0,
            'operational_beneficiaries' => 0,
            'operational_vendors' => 0,
            'unknown_unclassified' => $nonDonors,
            'unknown_unclassified_by_partner_type' => $nonDonorsByType,
            'note' => 'partners.is_donor is the only authoritative classification field. '
                . 'partner_type_id names are free-text business categories and are not inferred as '
                . 'beneficiary/vendor/association per instructions. All non-donor partners are preserved '
                . 'and reported here for manual review; none are deletion candidates in this phase.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAttachmentPlan(): array
    {
        $linked = DB::table('attachments')
            ->whereIn('attachable_type', self::OPERATIONAL_ATTACHABLE_TYPES)
            ->get(['id', 'file_path', 'file_size']);

        $existingCount = 0;
        $existingBytes = 0;
        $missing = [];

        foreach ($linked as $attachment) {
            if (Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->file_path)) {
                $existingCount++;
                $existingBytes += Storage::disk(self::ATTACHMENT_DISK)->size($attachment->file_path);
            } else {
                $missing[] = $attachment->file_path;
            }
        }

        $knownPaths = DB::table('attachments')->pluck('file_path')->all();

        $orphans = [];
        $orphanBytes = 0;

        foreach (self::KNOWN_ATTACHMENT_DIRECTORIES as $dir) {
            foreach (Storage::disk(self::ATTACHMENT_DISK)->allFiles($dir) as $file) {
                if (! in_array($file, $knownPaths, true)) {
                    $orphans[] = $file;
                    $orphanBytes += Storage::disk(self::ATTACHMENT_DISK)->size($file);
                }
            }
        }

        return [
            'linked_db_count' => $linked->count(),
            'linked_existing_file_count' => $existingCount,
            'linked_existing_file_bytes' => $existingBytes,
            'linked_missing_files' => $missing,
            'orphan_file_count' => count($orphans),
            'orphan_file_bytes' => $orphanBytes,
            'orphan_files' => $orphans,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildRisks(): array
    {
        return [
            'transaction_lines.account_id, projects_costs.account_id, transactions.fiscal_year_id/'
                . 'transaction_type_id, and projects.currency_id/project_status_id are RESTRICT-on-delete '
                . 'foreign keys into preserved reference tables — safe, since accounts/fiscal_years/'
                . 'transaction types/currencies/project statuses are never deleted.',
            'projects_costs, project_cost_budgets, project_cost_budgets_payments, project_cost_receipts, '
                . 'transaction_lines, project_financial_snapshots, project_financial_snapshot_currency_totals '
                . 'and project_financial_alerts all CASCADE at the DB level from projects/projects_costs/'
                . 'transactions/project_cost_budgets, but deletion below is still explicit and child-first so '
                . 'behavior is deterministic regardless of engine-level FK enforcement (the app runs on MySQL '
                . 'in this environment, but tests run on SQLite, which only cascades when foreign_keys=ON).',
            'Project, ProjectCost, ProjectCostBudget, ProjectCostBudgetsPayment and ProjectCostReceipt each '
                . 'have an Eloquent observer that flips project_financial_snapshots.is_dirty on delete/restore. '
                . 'Bulk deletion uses DB::table()->delete() specifically to avoid firing these observers at '
                . 'scale, since the snapshot rows they would touch are deleted in the same operation anyway.',
            'transactions.partner_id, general_expenses.partner_id and general_exchanges.partner_id are '
                . 'nullOnDelete foreign keys into partners — irrelevant here since partners are never deleted.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function findUnexpectedTables(): array
    {
        $connectionDatabase = config('database.connections.' . config('database.default') . '.database');

        $omsTables = collect(Schema::getTables())
            ->where('schema', $connectionDatabase)
            ->pluck('name')
            ->all();

        $known = array_merge(
            array_keys(self::PRESERVED_TABLES),
            array_keys(self::OPERATIONAL_TABLES),
            self::FRAMEWORK_TABLES,
        );

        return array_values(array_diff($omsTables, $known));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFingerprints(): array
    {
        $accounts = DB::table('accounts')->orderBy('id')
            ->get(['id', 'account_code', 'name', 'account_type_id', 'currency_id']);

        $donors = DB::table('partners')->where('is_donor', true)->orderBy('id')
            ->get(['id', 'name', 'partner_type_id']);

        $currencies = DB::table('currencies')->orderBy('id')
            ->get(['id', 'code', 'is_base']);

        $exchangeRateCount = DB::table('exchange_rate_histories')->count();
        $exchangeRateChecksum = (string) DB::table('exchange_rate_histories')
            ->orderBy('id')
            ->pluck('rate')
            ->reduce(static fn ($carry, $rate) => bcadd((string) $carry, (string) $rate, 6), '0');

        return [
            'accounts' => $accounts->map(fn ($a) => (array) $a)->all(),
            'donors' => $donors->map(fn ($d) => (array) $d)->all(),
            'currencies' => $currencies->map(fn ($c) => (array) $c)->all(),
            'exchange_rate_history_count' => $exchangeRateCount,
            'exchange_rate_history_checksum' => $exchangeRateChecksum,
            'user_count' => DB::table('users')->count(),
            'permission_count' => DB::table('permissions')->count(),
            'role_count' => DB::table('roles')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAccountBalances(): array
    {
        $accounts = DB::table('accounts')->orderBy('id')->get(['id', 'current_balance']);

        return [
            'total_accounts' => $accounts->count(),
            'non_zero_balance_count' => $accounts->filter(fn ($a) => (float) $a->current_balance !== 0.0)->count(),
            'snapshot' => $accounts->map(fn ($a) => ['id' => $a->id, 'current_balance' => $a->current_balance])->all(),
        ];
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
            'transactions.transaction_number' => 'Built per-prefix (e.g. fiscal-year/flow prefix) from '
                . 'MAX() of existing numeric suffixes, including soft-deleted rows '
                . '(see CreateAccount::generateTransactionNumber and equivalents in each Create*Payment/'
                . 'Receipt/Expense/Exchange page). Deleting all transactions naturally restarts each '
                . 'prefix at 001 the next time a transaction is created, purely as a side effect of there '
                . 'being no remaining rows to MAX() over — no explicit reset is implemented or needed.',
            'projects.code / projects_super.code' => 'Built from withTrashed()->count() of existing rows '
                . 'for the same prefix (see Project::boot and ProjectSuper::boot). Deleting all projects '
                . 'naturally restarts numbering the same way.',
            'database_auto_increment' => 'Primary keys (id columns) use ordinary DB AUTO_INCREMENT and are '
                . 'NOT reset by this cleanup — ids will continue climbing from their current high-water mark.',
            'fiscal_year_based_numbering' => false,
            'stored_sequence_tables' => false,
            'recommendation' => 'Resetting AUTO_INCREMENT counters is a separate, optional decision not '
                . 'implemented here, per instructions.',
        ];
    }

    /**
     * @return array<int, array{id: int, file_path: string}>
     */
    private function collectLinkedAttachmentFiles(): array
    {
        return DB::table('attachments')
            ->whereIn('attachable_type', self::OPERATIONAL_ATTACHABLE_TYPES)
            ->get(['id', 'file_path'])
            ->map(fn ($row) => ['id' => $row->id, 'file_path' => $row->file_path])
            ->all();
    }

    /**
     * @param  array<int, array{id: int, file_path: string}>  $files
     * @return array<string, mixed>
     */
    private function deleteLinkedFiles(array $files): array
    {
        $deleted = [];
        $failed = [];

        foreach ($files as $file) {
            $path = $file['file_path'];

            if (! Storage::disk(self::ATTACHMENT_DISK)->exists($path)) {
                continue;
            }

            if (Storage::disk(self::ATTACHMENT_DISK)->delete($path)) {
                $deleted[] = $path;
            } else {
                $failed[] = $path;
            }
        }

        return ['skipped' => false, 'deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * @return array<int, string>
     */
    private function verify(OperationalCleanupReport $before, OperationalCleanupReport $after): array
    {
        $failures = [];

        foreach (self::PRESERVED_TABLES as $table => $hasSoftDeletes) {
            if ($before->preservedCounts[$table] !== $after->preservedCounts[$table]) {
                $failures[] = "Preserved table '{$table}' row count changed during apply.";
            }
        }

        if ($before->fingerprints['accounts'] !== $after->fingerprints['accounts']) {
            $failures[] = 'Account definitions (id/code/name/type/currency) changed during apply.';
        }

        if ($before->fingerprints['donors'] !== $after->fingerprints['donors']) {
            $failures[] = 'Donor records changed during apply.';
        }

        if ($before->fingerprints['currencies'] !== $after->fingerprints['currencies']) {
            $failures[] = 'Currency records changed during apply.';
        }

        if ($before->fingerprints['exchange_rate_history_count'] !== $after->fingerprints['exchange_rate_history_count']
            || $before->fingerprints['exchange_rate_history_checksum'] !== $after->fingerprints['exchange_rate_history_checksum']
        ) {
            $failures[] = 'Exchange rate history changed during apply.';
        }

        if ($after->accountBalances['non_zero_balance_count'] !== 0) {
            $failures[] = 'Not all account balances are zero after apply.';
        }

        foreach (self::OPERATIONAL_TABLES as $table => $hasSoftDeletes) {
            $counts = $after->operationalCounts[$table];

            if ($counts['active'] !== 0 || $counts['trashed'] !== 0) {
                $failures[] = "Operational table '{$table}' still has rows after apply (active={$counts['active']}, trashed={$counts['trashed']}).";
            }
        }

        return $failures;
    }
}
