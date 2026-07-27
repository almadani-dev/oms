<?php

namespace App\Services\Restore;

use App\Enums\BackupStatus;
use App\Models\BackupOperation;
use App\Models\User;
use App\Notifications\BackupNotificationEvent;
use App\Services\Backup\BackupNotifier;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\Exceptions\RestoreStaleAcknowledgmentException;
use App\Support\Backup\BackupErrorSanitizer;
use Throwable;

/**
 * OMS Task 7C.8 section L — the ONLY thing this class ever does is
 * terminalize the stale safety gate after explicit human review. It is NOT
 * resume, retry, rollback, or repair: it never touches maintenance mode,
 * never runs a rollback, never deletes a workspace/quarantine, and never
 * spawns or relaunches anything. It only writes a terminal `restore_failed`
 * signed progress snapshot (with `restore_failed_phase = crashed_acknowledged`
 * — see RestoreProgressSnapshot's docblock) and, where the database is
 * available, updates the matching `backup_operations` row to `RestoreFailed`.
 *
 * Eligibility is intentionally narrow: only a restore RestoreStaleDetector
 * classifies with the exact `stale_heartbeat` reason code qualifies. Every
 * other detector reason (`progress_unreadable`, `invalid_heartbeat_timestamp`,
 * `database_unavailable_during_detection`) means the state could not be
 * safely and honestly confirmed as "just old" — those must never be
 * acknowledgeable from this normal UI path (see section M's tampered-state
 * handling), and a restore that is not reported as a candidate at all (a
 * healthy heartbeat, or already terminal) is equally ineligible.
 *
 * The whole eligibility re-check + write runs while holding
 * BackupSubsystemLock::acquireExclusive() — if a live restore process still
 * holds it, this fails closed (`lock_held`) rather than acknowledging a
 * restore that might not actually be stale at all.
 */
final class RestoreStaleAcknowledgmentService
{
    public const MAX_REASON_LENGTH = 1000;

    public function __construct(
        private readonly RestoreStaleDetector $detector = new RestoreStaleDetector(),
        private readonly RestoreProgressReader $reader = new RestoreProgressReader(),
        private readonly RestoreProgressWriter $writer = new RestoreProgressWriter(),
        private readonly BackupSubsystemLock $subsystemLock = new BackupSubsystemLock(),
        private readonly BackupNotifier $notifier = new BackupNotifier(),
    ) {
    }

    public function findStaleObservation(string $restoreUuid): ?StaleRestoreObservation
    {
        foreach ($this->detector->detect() as $observation) {
            if (hash_equals($restoreUuid, $observation->restoreUuid)) {
                return $observation;
            }
        }

        return null;
    }

    /**
     * Read-only eligibility check — safe to call repeatedly to decide
     * whether the acknowledgment action should even be offered in the UI.
     * Briefly acquires and releases the exclusive lock only to prove no live
     * process currently holds it; never leaves it held.
     */
    public function isEligibleForAcknowledgment(string $restoreUuid): bool
    {
        $observation = $this->findStaleObservation($restoreUuid);

        if ($observation === null || $observation->reasonCode !== 'stale_heartbeat') {
            return false;
        }

        $lockHandle = $this->subsystemLock->acquireExclusive();

        if ($lockHandle === null) {
            return false;
        }

        $lockHandle->release();

        return true;
    }

    /**
     * @throws RestoreStaleAcknowledgmentException
     */
    public function acknowledge(string $restoreUuid, User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw RestoreStaleAcknowledgmentException::reasonRequired();
        }

        $observation = $this->findStaleObservation($restoreUuid);

        if ($observation === null || $observation->reasonCode !== 'stale_heartbeat') {
            throw RestoreStaleAcknowledgmentException::notEligible();
        }

        $lockHandle = $this->subsystemLock->acquireExclusive();

        if ($lockHandle === null) {
            throw RestoreStaleAcknowledgmentException::lockHeld();
        }

        try {
            // Re-check under the lock — a live process could have started
            // (and begun sending fresh heartbeats) between the check above
            // and acquiring exclusive access.
            $recheck = $this->findStaleObservation($restoreUuid);

            if ($recheck === null || $recheck->reasonCode !== 'stale_heartbeat') {
                throw RestoreStaleAcknowledgmentException::notEligible();
            }

            try {
                $progress = $this->reader->read($restoreUuid);
            } catch (RestoreProgressIntegrityException) {
                throw RestoreStaleAcknowledgmentException::notEligible();
            }

            if ($progress->isTerminal()) {
                throw RestoreStaleAcknowledgmentException::notEligible();
            }

            $this->writeTerminalAcknowledgment($progress, $actor, $reason);
        } finally {
            $lockHandle->release();
        }
    }

    private function writeTerminalAcknowledgment(RestoreProgressSnapshot $progress, User $actor, string $reason): void
    {
        $now = now();
        $nowAtom = $now->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);
        $sanitizedReason = BackupErrorSanitizer::sanitize($reason);
        $summary = 'تم تأكيد توقف عملية الاستعادة يدوياً من قبل مسؤول النظام.';

        $phaseHistory = $progress->phaseHistory;
        $phaseHistory[] = ['phase' => 'restore_failed', 'at' => $nowAtom];
        $phaseHistory = array_slice($phaseHistory, -RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES);

        $terminal = RestoreProgressSnapshot::create(
            restoreUuid: $progress->restoreUuid,
            requestedBy: $progress->requestedBy,
            requestedAt: $progress->requestedAt,
            reason: $progress->reason,
            scope: $progress->scope,
            sourceBackupUuid: $progress->sourceBackupUuid,
            preRestoreSafetyBackupUuid: $progress->preRestoreSafetyBackupUuid,
            phase: 'restore_failed',
            phaseHistory: $phaseHistory,
            lastHeartbeatAt: $nowAtom,
            result: 'restore_failed',
            restoreFailedPhase: 'crashed_acknowledged',
            errorSummary: $summary,
            reconciliationSnapshot: $progress->reconciliationSnapshot,
        );

        // The signed progress file is the authoritative terminal record —
        // written first, and its failure must propagate (unlike
        // RestoreTerminalResultWriter's best-effort write, an explicit
        // human acknowledgment that silently failed to persist must not be
        // reported as successful).
        $this->writer->write($terminal);

        $this->updateDatabaseRowBestEffort($progress->restoreUuid, $actor, $sanitizedReason, $now, $nowAtom);
    }

    private function updateDatabaseRowBestEffort(string $restoreUuid, User $actor, string $sanitizedReason, \Illuminate\Support\Carbon $now, string $nowAtom): void
    {
        try {
            $row = BackupOperation::query()->where('uuid', $restoreUuid)->first();

            if ($row === null) {
                return;
            }

            $metadata = is_array($row->restore_metadata) ? $row->restore_metadata : [];
            $metadata['stale_acknowledgment'] = [
                'acknowledged_by' => [
                    'user_id' => $actor->id,
                    'name' => (string) $actor->name,
                    'email' => (string) $actor->email,
                ],
                'acknowledged_at' => $nowAtom,
                'reason' => $sanitizedReason,
            ];

            $row->forceFill([
                'status' => BackupStatus::RestoreFailed->value,
                'failed_at' => $now,
                'error_summary' => BackupErrorSanitizer::sanitize('Restore manually acknowledged as stopped by a Super Admin.'),
                'restore_metadata' => $metadata,
            ])->save();

            $this->notifier->notify($row, BackupNotificationEvent::RestoreFailed, $row->error_summary);
        } catch (Throwable) {
            // Best-effort only — the signed progress file above is already
            // the authoritative terminal record.
        }
    }
}
