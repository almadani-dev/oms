<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Services\Restore\RestoreStaleDetector;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.7 — RestoreStaleDetector: detection only. Every test here
 * proves the detector NEVER mutates a BackupOperation row, NEVER touches the
 * subsystem lock, and only ever reports what it observes.
 */
class RestoreStaleDetectorTest extends BackupTestCase
{
    private function claimedRow(string $uuid): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'restores',
            'started_at' => now(),
            'launch_nonce' => null,
        ]);
    }

    private function writeProgress(string $uuid, string $phase, string $lastHeartbeatAt): void
    {
        $now = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        $snapshot = RestoreProgressSnapshot::create(
            restoreUuid: $uuid,
            requestedBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            requestedAt: $now,
            reason: 'Watchdog test',
            scope: 'database',
            sourceBackupUuid: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            preRestoreSafetyBackupUuid: null,
            phase: $phase,
            phaseHistory: [['phase' => $phase, 'at' => $now]],
            lastHeartbeatAt: $lastHeartbeatAt,
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
        );

        (new RestoreProgressWriter())->write($snapshot);
    }

    public function test_a_healthy_recent_heartbeat_is_not_reported_as_stale(): void
    {
        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid);
        $this->writeProgress($uuid, 'staging', now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        $observations = (new RestoreStaleDetector())->detect();

        $this->assertSame([], array_filter($observations, fn ($o) => $o->restoreUuid === $uuid));
    }

    public function test_a_heartbeat_older_than_the_configured_threshold_is_reported_stale(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 5]);

        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid);
        $this->writeProgress($uuid, 'database_restoring', now()->subMinutes(30)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        $observations = (new RestoreStaleDetector())->detect();
        $mine = array_values(array_filter($observations, fn ($o) => $o->restoreUuid === $uuid));

        $this->assertCount(1, $mine);
        $this->assertSame('stale_heartbeat', $mine[0]->reasonCode);
        $this->assertSame('database_restoring', $mine[0]->phase);
        $this->assertGreaterThanOrEqual(30, $mine[0]->heartbeatAgeMinutes);
    }

    public function test_a_terminal_progress_file_is_never_reported_as_stale(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 1]);

        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid);

        $now = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);
        $snapshot = RestoreProgressSnapshot::create(
            restoreUuid: $uuid,
            requestedBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            requestedAt: $now,
            reason: 'Watchdog test',
            scope: 'database',
            sourceBackupUuid: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            preRestoreSafetyBackupUuid: null,
            phase: 'restore_failed',
            phaseHistory: [['phase' => 'restore_failed', 'at' => $now]],
            lastHeartbeatAt: now()->subHours(2)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            result: 'restore_failed',
            restoreFailedPhase: 'staging',
            errorSummary: 'sanitized',
        );
        (new RestoreProgressWriter())->write($snapshot);

        $observations = (new RestoreStaleDetector())->detect();

        $this->assertSame([], array_values(array_filter($observations, fn ($o) => $o->restoreUuid === $uuid)));
    }

    public function test_never_mutates_the_restore_row_or_progress_file(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 1]);

        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid);
        $this->writeProgress($uuid, 'reconciling', now()->subHour()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        $rawBefore = \Illuminate\Support\Facades\Storage::disk('restores')->get("{$uuid}/progress.json");

        (new RestoreStaleDetector())->detect();

        $fresh = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(BackupStatus::Restoring, $fresh->status, 'The detector must never mutate a restore row.');

        $rawAfter = \Illuminate\Support\Facades\Storage::disk('restores')->get("{$uuid}/progress.json");
        $this->assertSame($rawBefore, $rawAfter, 'The detector must never rewrite the progress file.');
    }

    public function test_detects_candidates_from_disk_even_when_the_database_row_is_missing(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 1]);

        $uuid = (string) Str::uuid();
        // No BackupOperation row at all — simulating the database row
        // having disappeared during a crashed import.
        $this->writeProgress($uuid, 'database_restoring', now()->subHour()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        $observations = (new RestoreStaleDetector())->detect();
        $mine = array_values(array_filter($observations, fn ($o) => $o->restoreUuid === $uuid));

        $this->assertCount(1, $mine);
        $this->assertSame('stale_heartbeat', $mine[0]->reasonCode);
    }

    // ---- Database unavailability (OMS Task 7C.7 hardening pass) ------------------------

    /**
     * A `mysql` import can make `config('database.default')` temporarily
     * unreachable — simulated here by genuinely dropping the table
     * `RestoreStaleDetector` queries, so `BackupOperation::query()` throws a
     * real \Throwable, not a mock. The detector must degrade gracefully
     * (never crash) and still find this restore via the disk-based scan,
     * which needs no database connection at all — a healthy heartbeat must
     * still read as healthy even while the database is unreachable.
     */
    public function test_a_healthy_restore_is_still_found_via_disk_when_the_database_is_unavailable(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 60]);

        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid);
        $this->writeProgress($uuid, 'database_restoring', now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        Schema::drop('backup_operations');

        $observations = (new RestoreStaleDetector())->detect();

        $mine = array_values(array_filter($observations, fn ($o) => $o->restoreUuid === $uuid));
        $this->assertSame([], $mine, 'A healthy heartbeat must not be reported stale, even when the database itself is unavailable.');

        $degraded = array_values(array_filter($observations, fn ($o) => $o->reasonCode === 'database_unavailable_during_detection'));
        $this->assertCount(1, $degraded, 'The database-unavailable condition itself must be surfaced as a bounded observation, never silently swallowed.');
    }

    /**
     * Same database-unavailable simulation, but with a genuinely stale
     * heartbeat — the detector must still classify it as stale via the
     * disk-based scan. A database error must never be silently read as
     * "no active restore" (which would hide a genuinely stuck restore) nor
     * as license to guess/recover — detection only, still.
     */
    public function test_a_stale_restore_is_still_reported_when_the_database_is_unavailable(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 5]);

        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid);
        $this->writeProgress($uuid, 'reconciling', now()->subMinutes(30)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        Schema::drop('backup_operations');

        $observations = (new RestoreStaleDetector())->detect();
        $mine = array_values(array_filter($observations, fn ($o) => $o->restoreUuid === $uuid));

        $this->assertCount(1, $mine);
        $this->assertSame('stale_heartbeat', $mine[0]->reasonCode);
    }
}
