<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Services\Restore\RestoreLaunchOutcomeStatus;
use App\Services\Restore\RestoreLaunchService;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Restore\FakeRestoreProcessLauncher;
use Tests\Support\Restore\FakeRestoreProgressDurability;

/**
 * OMS Task 7C.4 — RestoreLaunchService: atomic claim, global launch
 * serialization, initial progress timing, and launcher-failure handling.
 * Never resolves a real RestoreProcessLauncher from the container — always
 * injects FakeRestoreProcessLauncher, so no real process is ever spawned.
 */
class RestoreLaunchServiceTest extends BackupTestCase
{
    private function service(?FakeRestoreProcessLauncher $launcher = null, ?RestoreProgressWriter $writer = null): array
    {
        $launcher ??= new FakeRestoreProcessLauncher();

        $service = $writer !== null
            ? new RestoreLaunchService($launcher, progressWriter: $writer)
            : new RestoreLaunchService($launcher);

        return [$service, $launcher];
    }

    private function makeVerifiedSourceBackup(): BackupOperation
    {
        $path = 'source-'.uniqid('', true).'.omsbak.enc';
        Storage::disk('backups')->put($path, 'not-real-bytes');

        return BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => $path,
            'encrypted_filename' => $path,
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'completed_at' => now(),
            'verified_at' => now(),
        ]);
    }

    private function validMetadata(array $overrides = []): array
    {
        return array_merge([
            'requester' => ['user_id' => 7, 'name' => 'Test Admin', 'email' => 'admin@example.test'],
            'confirmed_at' => now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
        ], $overrides);
    }

    private function makeQueuedRestore(string $nonce, array $overrides = []): BackupOperation
    {
        $sourceBackup = $this->makeVerifiedSourceBackup();

        return BackupOperation::create(array_merge([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
            'source_backup_id' => $sourceBackup->id,
            'operation_reason' => 'Scheduled DR drill',
            'launch_nonce' => $nonce,
            'restore_metadata' => $this->validMetadata(),
        ], $overrides));
    }

    // ---- happy path -----------------------------------------------------------------

    public function test_valid_queued_row_is_claimed_and_launched_once(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service, $launcher] = $this->service();

        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Accepted, $outcome->status);
        $this->assertSame($row->uuid, $outcome->restoreUuid);
        $this->assertSame([$row->uuid], $launcher->launchedUuids);

        $fresh = BackupOperation::query()->findOrFail($row->id);
        $this->assertSame(BackupStatus::Restoring, $fresh->status);
        $this->assertNotNull($fresh->started_at);
        $this->assertNull($fresh->launch_nonce);
    }

    public function test_progress_is_written_before_the_launcher_is_called(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service, $launcher] = $this->service();

        $service->launch($row->uuid, $nonce);

        $this->assertSame([true], $launcher->progressExistedAtCallTime);
    }

    public function test_initial_progress_snapshot_contains_no_nonce_or_confirmation_phrase(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service] = $this->service();
        $service->launch($row->uuid, $nonce);

        $raw = Storage::disk('restores')->get("{$row->uuid}/progress.json");

        $this->assertStringNotContainsString($nonce, $raw);
        $this->assertStringNotContainsString('confirmation_phrase', $raw);

        $snapshot = (new RestoreProgressReader())->read($row->uuid);
        $this->assertSame('launching', $snapshot->phase);
        $this->assertFalse($snapshot->isTerminal());
    }

    // ---- atomic claim / replay --------------------------------------------------------

    public function test_replay_returns_conflict_and_does_not_spawn_twice(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service, $launcher] = $this->service();

        $first = $service->launch($row->uuid, $nonce);
        $second = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Accepted, $first->status);
        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $second->status);
        $this->assertCount(1, $launcher->launchedUuids);
    }

    public function test_replay_does_not_overwrite_the_existing_progress_file(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service] = $this->service();
        $service->launch($row->uuid, $nonce);

        $before = Storage::disk('restores')->get("{$row->uuid}/progress.json");
        $service->launch($row->uuid, $nonce);
        $after = Storage::disk('restores')->get("{$row->uuid}/progress.json");

        $this->assertSame($before, $after);
    }

    public function test_wrong_nonce_does_not_claim(): void
    {
        $row = $this->makeQueuedRestore(str_repeat('n', 64));

        [$service, $launcher] = $this->service();
        $outcome = $service->launch($row->uuid, str_repeat('x', 64));

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('nonce_mismatch', $outcome->reasonCode);
        $this->assertCount(0, $launcher->launchedUuids);
        $this->assertSame(BackupStatus::Queued, BackupOperation::query()->findOrFail($row->id)->status);
    }

    public function test_already_started_row_does_not_claim(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce, ['started_at' => now()]);

        [$service] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('already_started', $outcome->reasonCode);
    }

    public function test_non_queued_status_does_not_claim(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce, ['status' => BackupStatus::Restoring->value, 'started_at' => now()]);

        [$service] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('not_queued', $outcome->reasonCode);
    }

    public function test_non_restore_type_row_does_not_claim(): void
    {
        $sourceBackup = $this->makeVerifiedSourceBackup();
        $row = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
            'launch_nonce' => str_repeat('n', 64),
        ]);

        [$service] = $this->service();
        $outcome = $service->launch($row->uuid, str_repeat('n', 64));

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('not_a_restore_operation', $outcome->reasonCode);
    }

    public function test_missing_confirmation_snapshot_does_not_claim(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce, ['restore_metadata' => ['requester' => ['user_id' => 1, 'name' => 'A', 'email' => 'a@example.test']]]);

        [$service] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('confirmation_missing', $outcome->reasonCode);
    }

    public function test_forbidden_metadata_key_does_not_claim(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce, ['restore_metadata' => $this->validMetadata(['confirmation_phrase' => 'CONFIRM'])]);

        [$service] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('metadata_forbidden_key', $outcome->reasonCode);
    }

    public function test_unverified_source_backup_does_not_claim(): void
    {
        $sourceBackup = $this->makeVerifiedSourceBackup();
        $sourceBackup->update(['verified_at' => null]);

        $nonce = str_repeat('n', 64);
        $row = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
            'source_backup_id' => $sourceBackup->id,
            'operation_reason' => 'Test',
            'launch_nonce' => $nonce,
            'restore_metadata' => $this->validMetadata(),
        ]);

        [$service] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('source_backup_invalid', $outcome->reasonCode);
    }

    // ---- global launch serialization --------------------------------------------------

    public function test_another_active_db_restore_blocks(): void
    {
        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'started_at' => now(),
        ]);

        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service, $launcher] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('restore_already_active', $outcome->reasonCode);
        $this->assertCount(0, $launcher->launchedUuids);
    }

    public function test_another_active_progress_file_blocks(): void
    {
        $otherUuid = 'aaaaaaaa-0000-0000-0000-0000000000aa';
        (new RestoreProgressWriter())->write(RestoreProgressSnapshot::create(
            restoreUuid: $otherUuid,
            requestedBy: ['user_id' => 1, 'name' => 'A', 'email' => 'a@example.test'],
            requestedAt: now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            reason: 'other',
            scope: 'full',
            sourceBackupUuid: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            preRestoreSafetyBackupUuid: null,
            phase: 'database_restoring',
            phaseHistory: [],
            lastHeartbeatAt: now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
        ));

        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service, $launcher] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('restore_already_active', $outcome->reasonCode);
        $this->assertCount(0, $launcher->launchedUuids);
    }

    public function test_tampered_progress_file_for_the_current_uuid_still_blocks(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        // Simulate a stale/tampered leftover progress file for this exact
        // restore UUID from a previous crashed attempt.
        Storage::disk('restores')->put("{$row->uuid}/progress.json", json_encode([
            'restore_uuid' => $row->uuid,
            'schema_version' => 1,
            'signature' => 'not-a-real-signature',
        ]));

        [$service, $launcher] = $this->service();
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Conflict, $outcome->status);
        $this->assertSame('restore_state_requires_review', $outcome->reasonCode);
        $this->assertCount(0, $launcher->launchedUuids);
    }

    public function test_ordinary_shared_lock_operation_blocks_the_launch_section(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $sharedHolder = (new BackupSubsystemLock())->acquireShared();
        $this->assertNotNull($sharedHolder);

        try {
            [$service, $launcher] = $this->service();
            $outcome = $service->launch($row->uuid, $nonce);

            $this->assertSame(RestoreLaunchOutcomeStatus::Locked, $outcome->status);
            $this->assertCount(0, $launcher->launchedUuids);
        } finally {
            $sharedHolder->release();
        }
    }

    public function test_the_filesystem_lock_is_released_after_every_outcome(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        [$service] = $this->service();
        $service->launch($row->uuid, $nonce);

        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle, 'The exclusive lock must be released after a successful launch.');
        $handle->release();
    }

    // ---- progress-write failure --------------------------------------------------------

    public function test_progress_write_failure_prevents_spawn_and_marks_the_row_failed(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $failingWriter = new RestoreProgressWriter(new FakeRestoreProgressDurability(syncFileResult: false));
        [$service, $launcher] = $this->service(writer: $failingWriter);

        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Failed, $outcome->status);
        $this->assertCount(0, $launcher->launchedUuids);

        $fresh = BackupOperation::query()->findOrFail($row->id);
        $this->assertSame(BackupStatus::RestoreFailed, $fresh->status);
        $this->assertNull($fresh->launch_nonce);

        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle, 'The exclusive lock must be released after a progress-write failure.');
        $handle->release();
    }

    // ---- launcher failure ---------------------------------------------------------------

    public function test_launcher_failure_produces_a_terminal_sanitized_failure(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $launcher = new FakeRestoreProcessLauncher();
        $launcher->failNextWith(RestoreProcessLaunchException::spawnFailed());

        [$service] = $this->service($launcher);
        $outcome = $service->launch($row->uuid, $nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Failed, $outcome->status);
        $this->assertSame('spawn_failed', $outcome->reasonCode);

        $fresh = BackupOperation::query()->findOrFail($row->id);
        $this->assertSame(BackupStatus::RestoreFailed, $fresh->status);
        $this->assertNotNull($fresh->failed_at);
        $this->assertNull($fresh->launch_nonce);
        $this->assertStringNotContainsString($nonce, (string) $fresh->error_summary);

        $snapshot = (new RestoreProgressReader())->read($row->uuid);
        $this->assertTrue($snapshot->isTerminal());
        $this->assertSame('restore_failed', $snapshot->result);

        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle, 'The exclusive lock must be released after a launcher failure.');
        $handle->release();
    }
}
