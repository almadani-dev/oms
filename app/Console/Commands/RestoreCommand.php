<?php

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Support\Backup\BackupErrorSanitizer;
use Illuminate\Console\Command;

/**
 * OMS Task 7C.4 — the independent restore process's own entry point,
 * launched only by RestoreLaunchService's detached child spawn (never the
 * database queue — a restore may replace the very database the queue lives
 * in). Deliberately never acquires the Cache lock either, for the same
 * reason BackupSubsystemLock exists as a plain flock() (see its docblock).
 *
 * This phase does not implement the destructive restore engine yet —
 * failClosed() is the exact, isolated seam OMS Task 7C.7 replaces with a
 * real RestoreOrchestrator call. Everything above it in handle() (claim
 * verification, bounded-retry lock acquisition, the post-lock re-check) is
 * the permanent shape and must not need to change when that happens.
 * Because there is no real engine yet, this command always fails closed: it
 * writes a terminal, sanitized "engine not connected" progress snapshot and
 * moves the DB row to RestoreFailed rather than ever leaving a permanently
 * active restore behind.
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

    public function handle(RestoreProgressReader $reader, RestoreProgressWriter $writer, BackupSubsystemLock $subsystemLock): int
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

            $this->failClosed($row, $progress, $writer);

            $this->error('Restore execution engine is not yet connected (OMS Task 7C.7 pending) — restore marked failed.');

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
     * read, so failClosed() still runs and the DB row still reaches a
     * terminal state either way; only the progress file's own phase
     * reporting would be less precise.
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

    /**
     * OMS Task 7C.7 replaces this method's body with a real
     * RestoreOrchestrator call — everything in handle() above it stays
     * untouched. Never destructive: only ever writes a terminal, sanitized
     * failure state.
     */
    private function failClosed(BackupOperation $row, RestoreProgressSnapshot $progress, RestoreProgressWriter $writer): void
    {
        $now = now();
        $sanitized = BackupErrorSanitizer::sanitize('Restore execution engine is not yet implemented (OMS Task 7C.7 pending).');

        try {
            $terminal = RestoreProgressSnapshot::create(
                restoreUuid: $progress->restoreUuid,
                requestedBy: $progress->requestedBy,
                requestedAt: $progress->requestedAt,
                reason: $progress->reason,
                scope: $progress->scope,
                sourceBackupUuid: $progress->sourceBackupUuid,
                preRestoreSafetyBackupUuid: $progress->preRestoreSafetyBackupUuid,
                phase: 'restore_failed',
                phaseHistory: $progress->phaseHistory,
                lastHeartbeatAt: $now->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
                result: 'restore_failed',
                restoreFailedPhase: $progress->phase,
                errorSummary: $sanitized,
            );

            $writer->write($terminal);
        } catch (\Throwable) {
            // Best-effort — the DB update below is the authoritative
            // terminal record even if the progress file could not be
            // updated.
        }

        $row->forceFill([
            'status' => BackupStatus::RestoreFailed,
            'failed_at' => $now,
            'error_summary' => $sanitized,
        ])->save();
    }
}
