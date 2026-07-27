<?php

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\RestoreOrchestrator;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Console\Command;

/**
 * OMS Task 7C.4 — the independent restore process's own entry point,
 * launched only by RestoreLaunchService's detached child spawn (never the
 * database queue — a restore may replace the very database the queue lives
 * in). Deliberately never acquires the Cache lock either, for the same
 * reason BackupSubsystemLock exists as a plain flock() (see its docblock).
 *
 * OMS Task 7C.7 — orchestrate() (RestoreOrchestrator) replaces this class's
 * former fail-closed placeholder. Everything above the orchestrator call in
 * handle() (claim verification, bounded-retry lock acquisition, the
 * post-lock re-check, advanceToLockAcquired()) is the permanent shape from
 * Task 7C.4 and did not need to change: this class still never runs through
 * the database queue and never acquires the Cache lock, it only now hands
 * the claimed row/progress/lock handle to the real execution engine instead
 * of always failing closed.
 *
 * OMS Task 7C.4 correction pass: `phase=lock_acquired` is only ever written
 * by THIS class, and only after it has genuinely acquired the lifetime
 * exclusive lock (advanceToLockAcquired()) — the parent web request
 * (RestoreLaunchService) writes the initial progress file with
 * `phase=launching` instead, since it is strictly impossible for it to
 * have acquired a lock this process hasn't been spawned to hold yet.
 */
class RestoreCommand extends Command
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    protected $signature = 'oms:restore {uuid : The restore BackupOperation UUID}';

    protected $description = 'Execute an already-launched, claimed restore operation (OMS Task 7C).';

    public function handle(RestoreProgressReader $reader, RestoreProgressWriter $writer, BackupSubsystemLock $subsystemLock, RestoreOrchestrator $orchestrator): int
    {
        $uuid = (string) $this->argument('uuid');

        if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            $this->error('Invalid restore UUID.');

            return self::INVALID;
        }

        if ($this->loadExecutableRestore($uuid, $reader) === null) {
            $this->error('Restore operation is not in a claimed, executable state.');

            return self::FAILURE;
        }

        $lockHandle = $this->acquireLockWithRetry($subsystemLock);

        if ($lockHandle === null) {
            $this->error('Could not acquire the restore subsystem lock in time.');

            return self::FAILURE;
        }

        try {
            $loaded = $this->loadExecutableRestore($uuid, $reader);

            if ($loaded === null) {
                $this->error('Restore operation state changed before execution could begin.');

                return self::FAILURE;
            }

            [$row, $progress] = $loaded;

            // Only NOW does this process genuinely hold the lifetime
            // exclusive lock — this is the one accurate moment to report
            // `lock_acquired`. RestoreLaunchService's own initial write
            // (phase `launching`) deliberately never uses this phase, since
            // the parent writes it before this child exists.
            $progress = $this->advanceToLockAcquired($progress, $writer);

            $result = $orchestrator->orchestrate($row, $progress, $lockHandle);

            if ($result === BackupStatus::Restored) {
                $this->info('Restore completed successfully.');

                return self::SUCCESS;
            }

            $this->error("Restore did not complete successfully (status: {$result->value}).");

            return self::FAILURE;
        } finally {
            $lockHandle->release();
        }
    }

    /**
     * @return array{0: BackupOperation, 1: RestoreProgressSnapshot}|null
     */
    private function loadExecutableRestore(string $uuid, RestoreProgressReader $reader): ?array
    {
        $row = BackupOperation::query()->where('uuid', $uuid)->first();

        if (! $this->isClaimedForExecution($row)) {
            return null;
        }

        try {
            $progress = $reader->read($uuid);
        } catch (RestoreProgressIntegrityException) {
            return null;
        }

        if ($progress->isTerminal()) {
            return null;
        }

        return [$row, $progress];
    }

    private function isClaimedForExecution(?BackupOperation $row): bool
    {
        return $row !== null
            && $row->type === BackupType::Restore
            && $row->status === BackupStatus::Restoring
            && $row->started_at !== null
            && $row->launch_nonce === null;
    }

    /**
     * The parent launch request may still hold the same lifetime exclusive
     * lock briefly (it releases only after this child has already been
     * spawned) — so this retries on a short, bounded interval rather than
     * failing on the first non-blocking acquisition attempt.
     */
    private function acquireLockWithRetry(BackupSubsystemLock $subsystemLock): ?BackupSubsystemLockHandle
    {
        $timeoutSeconds = (int) config('oms.backup.restore.launch_lock_retry_timeout_seconds', 30);
        $intervalMs = max(1, (int) config('oms.backup.restore.launch_lock_retry_interval_ms', 200));
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $handle = $subsystemLock->acquireExclusive();

            if ($handle !== null) {
                return $handle;
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep($intervalMs * 1000);
        }
    }

    /**
     * Writes a non-terminal progress update advancing from whatever phase
     * the parent left (`launching`) to `lock_acquired`, now that this
     * process genuinely holds the lifetime exclusive BackupSubsystemLock.
     * A write failure here is non-fatal — it falls back to the snapshot as
     * read, so orchestrate() still runs and reaches a terminal state either
     * way; only the progress file's own phase reporting would be less
     * precise.
     */
    private function advanceToLockAcquired(RestoreProgressSnapshot $progress, RestoreProgressWriter $writer): RestoreProgressSnapshot
    {
        $nowAtom = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        $phaseHistory = $progress->phaseHistory;
        $phaseHistory[] = ['phase' => 'lock_acquired', 'at' => $nowAtom];
        $phaseHistory = array_slice($phaseHistory, -RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES);

        try {
            $advanced = RestoreProgressSnapshot::create(
                restoreUuid: $progress->restoreUuid,
                requestedBy: $progress->requestedBy,
                requestedAt: $progress->requestedAt,
                reason: $progress->reason,
                scope: $progress->scope,
                sourceBackupUuid: $progress->sourceBackupUuid,
                preRestoreSafetyBackupUuid: $progress->preRestoreSafetyBackupUuid,
                phase: 'lock_acquired',
                phaseHistory: $phaseHistory,
                lastHeartbeatAt: $nowAtom,
                result: null,
                restoreFailedPhase: null,
                errorSummary: null,
            );

            $writer->write($advanced);

            return $advanced;
        } catch (\Throwable) {
            return $progress;
        }
    }
}
