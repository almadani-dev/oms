<?php

namespace App\Services\Restore;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * OMS Task 7C.7 — DETECTION ONLY. Read-only inspection of every candidate
 * non-terminal restore, comparing its signed progress file's own
 * `last_heartbeat_at` against `config('oms.backup.restore.stale_after_minutes')`.
 *
 * This class NEVER: retries, resumes, relaunches, acquires a takeover or any
 * other lock, mutates a restore's status, runs a rollback or any other
 * recovery action, deletes a workspace, or leaves maintenance mode. It only
 * ever reads a `backup_operations` row and, at most, one `progress.json` per
 * restore UUID, and returns a bounded list of observations for the caller
 * (RestoreWatchdogCommand) to log/notify. Human acknowledgment and any
 * actual recovery action remain later, explicit UI/recovery scope — never
 * this class's job.
 *
 * Candidates come from two independent sources, mirroring
 * RestoreActivityGuard's own dual-source philosophy (the database row alone
 * cannot be trusted during exactly the window a restore matters most): every
 * `type = restore, status = restoring` database row, AND every UUID-named
 * directory directly under the restores disk root that has a `progress.json`
 * — deduplicated by UUID so a restore visible from both sources is only ever
 * inspected once.
 *
 * OMS Task 7C.7 hardening pass — database-unavailability handling: a `mysql`
 * import can make `config('database.default')` temporarily unreachable
 * (schema/data mid-replacement, or the connection genuinely down). This
 * class must never let that crash `detect()` itself, and a DB-query failure
 * must NEVER be silently read as "no active restore" — it always falls back
 * to the disk-based candidate scan (which needs no database connection at
 * all), and the failure itself is surfaced as a bounded, generic
 * `needs_review` observation so an operator sees "detection was degraded,"
 * never a false "everything is healthy." The disk scan is defended the same
 * way, independently, in case the restores disk itself is briefly
 * unavailable.
 *
 * Timestamps are compared via `Illuminate\Support\Carbon::now()` rather than
 * `time()`/a raw `DateTimeImmutable` diff, purely so a test can drive a
 * simulated long-running restore deterministically via `Carbon::setTestNow()`
 * time travel instead of a real `sleep()` — `RestoreHeartbeat` (the write
 * side) does the same for the identical reason.
 */
final class RestoreStaleDetector
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(
        private readonly RestoreProgressReader $reader = new RestoreProgressReader(),
    ) {
    }

    /**
     * @return list<StaleRestoreObservation>
     */
    public function detect(): array
    {
        $staleAfterMinutes = max(1, (int) config('oms.backup.restore.stale_after_minutes', 20));
        $observations = [];
        $seenUuids = [];

        [$databaseUuids, $databaseUnavailable] = $this->candidateUuidsFromDatabase();

        if ($databaseUnavailable) {
            $observations[] = StaleRestoreObservation::needsReview('unknown', 'database_unavailable_during_detection');
        }

        foreach ($databaseUuids as $uuid) {
            $seenUuids[$uuid] = true;
            $observation = $this->inspect($uuid, $staleAfterMinutes);

            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        foreach ($this->candidateUuidsFromDisk() as $uuid) {
            if (isset($seenUuids[$uuid])) {
                continue;
            }

            $observation = $this->inspect($uuid, $staleAfterMinutes);

            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        return $observations;
    }

    private function inspect(string $uuid, int $staleAfterMinutes): ?StaleRestoreObservation
    {
        try {
            $progress = $this->reader->read($uuid);
        } catch (RestoreProgressIntegrityException) {
            return StaleRestoreObservation::needsReview($uuid, 'progress_unreadable');
        }

        if ($progress->isTerminal()) {
            return null;
        }

        $lastHeartbeat = Carbon::createFromFormat(RestoreProgressSnapshot::TIMESTAMP_FORMAT, $progress->lastHeartbeatAt);

        if ($lastHeartbeat === false) {
            return StaleRestoreObservation::needsReview($uuid, 'invalid_heartbeat_timestamp');
        }

        $ageMinutes = (int) floor(Carbon::now()->diffInSeconds($lastHeartbeat, true) / 60);

        if ($ageMinutes < $staleAfterMinutes) {
            return null;
        }

        return StaleRestoreObservation::staleHeartbeat($uuid, $progress->phase, $ageMinutes);
    }

    /**
     * @return array{0: list<string>, 1: bool} candidate UUIDs, and whether
     *                                          the database was unavailable
     *                                          (in which case the list is
     *                                          always empty — never a
     *                                          partial/unsafe guess)
     */
    private function candidateUuidsFromDatabase(): array
    {
        try {
            return [
                BackupOperation::query()
                    ->where('type', BackupType::Restore->value)
                    ->where('status', BackupStatus::Restoring->value)
                    ->pluck('uuid')
                    ->all(),
                false,
            ];
        } catch (Throwable $e) {
            Log::warning('RestoreStaleDetector could not query the database for active restores; falling back to disk-only detection.', [
                'exception' => $e::class,
            ]);

            return [[], true];
        }
    }

    /**
     * @return list<string>
     */
    private function candidateUuidsFromDisk(): array
    {
        try {
            $disk = Storage::disk((string) config('oms.backup.restore.disk', 'restores'));
            $uuids = [];

            foreach ($disk->directories() as $directory) {
                $uuid = basename($directory);

                if (preg_match(self::UUID_PATTERN, $uuid) === 1 && $disk->exists($directory.'/progress.json')) {
                    $uuids[] = $uuid;
                }
            }

            return $uuids;
        } catch (Throwable $e) {
            Log::warning('RestoreStaleDetector could not scan the restores disk for active restores.', [
                'exception' => $e::class,
            ]);

            return [];
        }
    }
}
