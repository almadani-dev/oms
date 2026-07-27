<?php

namespace App\Services\Restore;

use App\Enums\BackupStatus;
use App\Models\BackupOperation;
use App\Notifications\BackupNotificationEvent;
use App\Services\Backup\BackupNotifier;
use App\Support\Backup\BackupErrorSanitizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OMS Task 7C.7 — the single place a restore's TERMINAL outcome
 * (Restored/RestoreFailed/RestorePartial) is ever written, for both the
 * signed progress file and the `backup_operations` row. Centralized so
 * RestoreOrchestrator's many failure branches (see the Task 7C.7
 * compensation matrix) all funnel through one consistent, honest write
 * instead of duplicating the "write progress, then best-effort update the
 * DB row" pattern at every call site.
 *
 * Ordering and failure handling are deliberate, per the approved Task 7C.7
 * design:
 *   1. The signed progress file is ALWAYS attempted first, and is the
 *      authoritative terminal record — a write failure here is swallowed
 *      (never thrown back to the caller) so that a subsequent DB write is
 *      still attempted, but the progress file itself is what a recovery
 *      operator must trust if the two ever disagree.
 *   2. The `backup_operations` row is re-fetched fresh by UUID — NEVER
 *      handed in as a possibly-stale Eloquent instance — because by the
 *      time a terminal result is known, a database import may have already
 *      replaced the entire table underneath any earlier-loaded instance
 *      (see RestoreOrchestrator's own docblock on the database-replacement
 *      boundary). If the row cannot be found (a real possibility when
 *      reconciliation itself failed before reconstructing it, or never ran
 *      at all), the signed progress file above remains the sole
 *      authoritative record — this is expected, not an error.
 *   3. A DB write failure is swallowed the same way: it must never erase or
 *      contradict the progress outcome already durably recorded in step 1.
 *   4. A best-effort persistent notification is sent last, exactly like
 *      every other backup/restore outcome (BackupNotifier) — a failure here
 *      (e.g. the Super Admin role missing) must never affect either write
 *      above.
 */
final class RestoreTerminalResultWriter
{
    public function __construct(
        private readonly RestoreProgressWriter $progressWriter = new RestoreProgressWriter(),
        private readonly BackupNotifier $notifier = new BackupNotifier(),
    ) {
    }

    /**
     * $failedPhaseOverride lets a caller pin `restore_failed_phase` to the
     * phase where a failure ACTUALLY occurred, even when $lastProgress has
     * since moved past it (e.g. RestoreOrchestrator advances to
     * `maintenance_disabled` as bookkeeping AFTER deciding a failure/partial
     * result, which must never overwrite the real failing phase). Defaults
     * to $lastProgress->phase — every existing caller's behavior is
     * unchanged.
     *
     * @throws \InvalidArgumentException if $result is not one of Restored/RestoreFailed/RestorePartial
     */
    public function finish(RestoreProgressSnapshot $lastProgress, BackupStatus $result, ?string $errorSummary = null, ?string $failedPhaseOverride = null): RestoreProgressSnapshot
    {
        $resultValue = match ($result) {
            BackupStatus::Restored => 'restored',
            BackupStatus::RestoreFailed => 'restore_failed',
            BackupStatus::RestorePartial => 'restore_partial',
            default => throw new \InvalidArgumentException('RestoreTerminalResultWriter::finish() requires a terminal restore BackupStatus.'),
        };

        $isSuccess = $result === BackupStatus::Restored;
        $now = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        $phaseHistory = $lastProgress->phaseHistory;
        $phaseHistory[] = ['phase' => $resultValue, 'at' => $now];
        $phaseHistory = array_slice($phaseHistory, -RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES);

        $sanitizedSummary = $isSuccess || $errorSummary === null ? null : BackupErrorSanitizer::sanitize($errorSummary);

        $terminal = RestoreProgressSnapshot::create(
            restoreUuid: $lastProgress->restoreUuid,
            requestedBy: $lastProgress->requestedBy,
            requestedAt: $lastProgress->requestedAt,
            reason: $lastProgress->reason,
            scope: $lastProgress->scope,
            sourceBackupUuid: $lastProgress->sourceBackupUuid,
            preRestoreSafetyBackupUuid: $lastProgress->preRestoreSafetyBackupUuid,
            phase: $resultValue,
            phaseHistory: $phaseHistory,
            lastHeartbeatAt: $now,
            result: $resultValue,
            restoreFailedPhase: $isSuccess ? null : ($failedPhaseOverride ?? $lastProgress->phase),
            errorSummary: $sanitizedSummary,
            reconciliationSnapshot: $lastProgress->reconciliationSnapshot,
        );

        try {
            $this->progressWriter->write($terminal);
        } catch (Throwable $e) {
            // Best-effort only — see class docblock. The DB write below is
            // still attempted regardless.
            Log::warning('Failed to write terminal restore progress snapshot.', [
                'restore_uuid' => $terminal->restoreUuid,
                'result' => $resultValue,
            ]);
        }

        $this->updateDatabaseRow($terminal, $result, $sanitizedSummary);

        return $terminal;
    }

    private function updateDatabaseRow(RestoreProgressSnapshot $terminal, BackupStatus $result, ?string $sanitizedSummary): void
    {
        try {
            $row = BackupOperation::query()->where('uuid', $terminal->restoreUuid)->first();

            if ($row === null) {
                // Expected when reconciliation never reconstructed this
                // restore's own row (or failed before doing so) — the signed
                // progress file written above remains authoritative.
                Log::warning('Restore terminal result could not be written to the database — row not found.', [
                    'restore_uuid' => $terminal->restoreUuid,
                    'result' => $result->value,
                ]);

                return;
            }

            $row->forceFill([
                'status' => $result->value,
                'completed_at' => $result === BackupStatus::Restored ? now() : $row->completed_at,
                'failed_at' => $result === BackupStatus::Restored ? null : now(),
                'error_summary' => $sanitizedSummary,
            ])->save();

            $this->notifyBestEffort($row, $result, $sanitizedSummary);
        } catch (Throwable) {
            // Best-effort only — see class docblock. The signed progress
            // file already written above remains the authoritative record.
            Log::warning('Failed to update the restore backup_operations row with its terminal result.', [
                'restore_uuid' => $terminal->restoreUuid,
                'result' => $result->value,
            ]);
        }
    }

    private function notifyBestEffort(BackupOperation $row, BackupStatus $result, ?string $sanitizedSummary): void
    {
        $event = match ($result) {
            BackupStatus::Restored => BackupNotificationEvent::RestoreSucceeded,
            BackupStatus::RestoreFailed => BackupNotificationEvent::RestoreFailed,
            BackupStatus::RestorePartial => BackupNotificationEvent::RestorePartial,
            default => null,
        };

        if ($event === null) {
            return;
        }

        try {
            $this->notifier->notify($row, $event, $sanitizedSummary);
        } catch (Throwable) {
            // Best-effort only — never lets a notification failure affect
            // the already-persisted terminal DB/progress state.
        }
    }
}
