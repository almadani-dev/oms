<?php

namespace App\Console\Commands;

use App\Enums\BackupScope;
use App\Enums\BackupType;
use App\Jobs\CreateBackupJob;
use App\Services\Backup\BackupCreationOrchestrator;
use Illuminate\Console\Command;

/**
 * Thin CLI entry point — all decision logic lives in
 * BackupCreationOrchestrator. Never accepts a file path, disk, or
 * credential as an option; --reason is a free-text audit note only.
 *
 * Default execution dispatches CreateBackupJob to the `backups` queue and
 * returns immediately (required for the scheduler's daily/weekly entries,
 * which must never block schedule:run). --sync is for controlled local
 * diagnostics only — it still goes through the exact same orchestrator
 * used by the queued job, just synchronously in this process.
 */
class CreateBackup extends Command
{
    protected $signature = 'oms:backup
        {--type=manual : manual|daily|weekly|pre_restore}
        {--scope=full : database|files|full}
        {--reason= : Optional free-text reason recorded on the operation.}
        {--sync : Run synchronously in this process instead of queuing. Local/manual diagnostics only.}';

    protected $description = 'Queue (or, with --sync, run) an OMS backup operation.';

    public function handle(BackupCreationOrchestrator $orchestrator): int
    {
        $type = BackupType::tryFrom((string) $this->option('type'));

        if ($type === null) {
            $this->error('Invalid --type. Expected one of: manual, daily, weekly, pre_restore.');

            return self::INVALID;
        }

        $scope = BackupScope::tryFrom((string) $this->option('scope'));

        if ($scope === null) {
            $this->error('Invalid --scope. Expected one of: database, files, full.');

            return self::INVALID;
        }

        $reason = $this->option('reason');

        // Console context has no authenticated Filament user — created_by
        // stays null for CLI-initiated backups (scheduled or manual CLI).
        // A future Filament UI action passes the acting Super Admin's ID
        // through this same orchestrator, not through this command.
        $operation = $orchestrator->enqueue($type, $scope, $reason !== null ? (string) $reason : null, null);

        if (! $operation->wasRecentlyCreated) {
            $this->info("A {$type->value} backup for today already exists (id={$operation->id}, status={$operation->status->value}) — not dispatching a duplicate.");

            return self::SUCCESS;
        }

        if ((bool) $this->option('sync')) {
            $orchestrator->run($operation->id);
            $this->info("Backup {$operation->uuid} completed synchronously.");

            return self::SUCCESS;
        }

        CreateBackupJob::dispatch($operation->id);
        $this->info("Backup {$operation->uuid} queued ({$type->value}/{$scope->value}).");

        return self::SUCCESS;
    }
}
