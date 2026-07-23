<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\BackupOperation;
use App\Services\Backup\BackupCreationOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Accepts only a BackupOperation ID — never a credential, a raw command
 * string, or a path. All secrets/config are resolved at execution time by
 * BackupCreationOrchestrator, from the environment, not from job state.
 *
 * A lock conflict (another operation already running) retries with backoff
 * rather than exhausting the operation as failed on the first collision;
 * every other failure is left to the orchestrator's own status handling
 * (it always marks the operation failed with a sanitized summary before
 * rethrowing) — failed() below is only a safety net for the rare case the
 * orchestrator itself never ran (e.g. the operation row vanished).
 */
class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $backupOperationId)
    {
        $this->onQueue((string) config('oms.backup.queue', 'backups'));
        $this->timeout = (int) config('oms.backup.job_timeout', 3600);
    }

    public function handle(BackupCreationOrchestrator $orchestrator): void
    {
        // A BackupLockedException (another operation already holds the
        // lock) is allowed to propagate like any other failure — Laravel's
        // own $tries/$backoff retries it automatically. The orchestrator
        // deliberately leaves the operation row untouched (still queued)
        // in that specific case, so a retry picks up cleanly.
        $orchestrator->run($this->backupOperationId);
    }

    public function failed(?Throwable $exception): void
    {
        $operation = BackupOperation::find($this->backupOperationId);

        if ($operation === null || $operation->status === BackupStatus::Completed) {
            return;
        }

        $operation->forceFill([
            'status' => BackupStatus::Failed->value,
            'failed_at' => now(),
            'error_summary' => $exception !== null
                ? mb_substr($exception->getMessage(), 0, 2000)
                : 'Backup job failed with no exception detail.',
        ])->save();
    }
}
