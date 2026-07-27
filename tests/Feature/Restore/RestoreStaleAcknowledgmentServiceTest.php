<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Restore\Exceptions\RestoreStaleAcknowledgmentException;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Services\Restore\RestoreStaleAcknowledgmentService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.8 section L — RestoreStaleAcknowledgmentService only ever
 * terminalizes a genuinely stale restore's DB row + signed progress file
 * after explicit human review. It is never resume/retry/rollback/repair —
 * every test here also proves it never touches maintenance mode or creates
 * any other BackupOperation row (a real rollback/safety-backup would).
 *
 * A real BackupSubsystemLock (never a fake) is used for the "live exclusive
 * lock blocks acknowledgment" test, released in a finally block — this
 * codebase has a documented history of a real flaky-test bug caused by a
 * leaked flock() handle across tests (see docs/TASKS_LOG.md's 2026-07-26
 * "test-stability pass" entry), so this is not optional here.
 */
class RestoreStaleAcknowledgmentServiceTest extends BackupTestCase
{
    private function service(): RestoreStaleAcknowledgmentService
    {
        return new RestoreStaleAcknowledgmentService();
    }

    private function snapshot(string $uuid, array $overrides = []): RestoreProgressSnapshot
    {
        $a = array_merge([
            'requestedBy' => ['user_id' => 1, 'name' => 'Requester', 'email' => 'requester@example.test'],
            'requestedAt' => now()->subHour()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            'reason' => 'Scheduled DR drill',
            'scope' => 'full',
            'sourceBackupUuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'preRestoreSafetyBackupUuid' => null,
            'phase' => 'staging',
            'phaseHistory' => [],
            'lastHeartbeatAt' => now()->subMinutes(30)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            'result' => null,
            'restoreFailedPhase' => null,
            'errorSummary' => null,
        ], $overrides);

        return RestoreProgressSnapshot::create(
            $uuid,
            $a['requestedBy'],
            $a['requestedAt'],
            $a['reason'],
            $a['scope'],
            $a['sourceBackupUuid'],
            $a['preRestoreSafetyBackupUuid'],
            $a['phase'],
            $a['phaseHistory'],
            $a['lastHeartbeatAt'],
            $a['result'],
            $a['restoreFailedPhase'],
            $a['errorSummary'],
        );
    }

    private function makeRestoringRow(string $uuid): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'started_at' => now()->subMinutes(30),
            'operation_reason' => 'Scheduled DR drill',
        ]);
    }

    // ---- eligibility --------------------------------------------------------------------

    public function test_a_genuinely_stale_restore_is_eligible(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000001';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $this->assertTrue($this->service()->isEligibleForAcknowledgment($uuid));
    }

    public function test_a_healthy_heartbeat_is_not_eligible(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000002';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid, [
            'lastHeartbeatAt' => now()->subSeconds(10)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
        ]));

        $this->assertFalse($this->service()->isEligibleForAcknowledgment($uuid));
    }

    public function test_tampered_progress_is_not_eligible(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000003';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered after being written';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $this->assertFalse($this->service()->isEligibleForAcknowledgment($uuid));
    }

    public function test_an_already_terminal_restore_is_not_eligible(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000004';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid, [
            'phase' => 'restored',
            'result' => 'restored',
            'lastHeartbeatAt' => now()->subMinutes(30)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
        ]));

        $this->assertFalse($this->service()->isEligibleForAcknowledgment($uuid));
    }

    public function test_a_live_exclusive_lock_blocks_eligibility(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000005';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $lock = new BackupSubsystemLock();
        $handle = $lock->acquireExclusive();
        $this->assertNotNull($handle, 'Precondition: the lock must be acquirable at all in this test.');

        try {
            $this->assertFalse($this->service()->isEligibleForAcknowledgment($uuid));
        } finally {
            $handle->release();
        }
    }

    // ---- acknowledge() --------------------------------------------------------------------

    public function test_acknowledge_requires_a_reason(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000006';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create();

        try {
            $this->service()->acknowledge($uuid, $actor, '   ');
            $this->fail('Expected RestoreStaleAcknowledgmentException.');
        } catch (RestoreStaleAcknowledgmentException $e) {
            $this->assertSame('reason_required', $e->reasonCode);
        }
    }

    public function test_acknowledge_rejects_a_non_stale_restore(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000007';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid, [
            'lastHeartbeatAt' => now()->subSeconds(10)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
        ]));
        $actor = User::factory()->create();

        try {
            $this->service()->acknowledge($uuid, $actor, 'confirmed stopped');
            $this->fail('Expected RestoreStaleAcknowledgmentException.');
        } catch (RestoreStaleAcknowledgmentException $e) {
            $this->assertSame('not_eligible', $e->reasonCode);
        }
    }

    public function test_acknowledge_rejects_tampered_progress(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000008';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered after being written';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $actor = User::factory()->create();

        try {
            $this->service()->acknowledge($uuid, $actor, 'confirmed stopped');
            $this->fail('Expected RestoreStaleAcknowledgmentException.');
        } catch (RestoreStaleAcknowledgmentException $e) {
            $this->assertSame('not_eligible', $e->reasonCode);
        }

        // The tampered file must remain exactly as tampered — never
        // silently "fixed" by a rejected acknowledgment attempt.
        $this->assertSame($decoded['reason'], json_decode($disk->get("{$uuid}/progress.json"), true)['reason']);
    }

    public function test_acknowledge_rejects_while_a_live_exclusive_lock_is_held(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000009';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $lock = new BackupSubsystemLock();
        $handle = $lock->acquireExclusive();
        $this->assertNotNull($handle);

        $actor = User::factory()->create();

        try {
            try {
                $this->service()->acknowledge($uuid, $actor, 'confirmed stopped');
                $this->fail('Expected RestoreStaleAcknowledgmentException.');
            } catch (RestoreStaleAcknowledgmentException $e) {
                $this->assertSame('lock_held', $e->reasonCode);
            }
        } finally {
            $handle->release();
        }
    }

    public function test_acknowledge_terminalizes_both_progress_and_db_row(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-00000000000a';
        $row = $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create(['name' => 'Ops Admin', 'email' => 'ops@example.test']);

        $this->service()->acknowledge($uuid, $actor, 'Confirmed the worker process is gone; server was rebooted.');

        $progress = (new RestoreProgressReader())->read($uuid);
        $this->assertTrue($progress->isTerminal());
        $this->assertSame('restore_failed', $progress->result);
        $this->assertSame('crashed_acknowledged', $progress->restoreFailedPhase);

        $row->refresh();
        $this->assertSame(BackupStatus::RestoreFailed, $row->status);
        $this->assertNotNull($row->failed_at);

        $metadata = $row->restore_metadata;
        $this->assertSame($actor->id, $metadata['stale_acknowledgment']['acknowledged_by']['user_id']);
        $this->assertSame('Ops Admin', $metadata['stale_acknowledgment']['acknowledged_by']['name']);
        $this->assertStringContainsString('worker process is gone', $metadata['stale_acknowledgment']['reason']);
        $this->assertNotNull($metadata['stale_acknowledgment']['acknowledged_at']);

        // Never persists a typed confirmation phrase.
        $this->assertArrayNotHasKey('confirmation_phrase', $metadata['stale_acknowledgment']);
    }

    /**
     * The section L guarantee this class exists to enforce: acknowledgment
     * is ONLY a terminal state write. No rollback, no maintenance-mode
     * mutation, no additional BackupOperation row (a real safety backup or
     * relaunch would create one) is ever performed.
     */
    public function test_acknowledge_performs_no_recovery_action(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-00000000000b';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create();

        $wasDown = app()->isDownForMaintenance();

        $this->service()->acknowledge($uuid, $actor, 'confirmed stopped');

        $this->assertSame($wasDown, app()->isDownForMaintenance(), 'Acknowledgment must never toggle maintenance mode.');
        $this->assertSame(1, BackupOperation::query()->count(), 'Acknowledgment must never create another BackupOperation row.');
    }

    public function test_a_second_restore_may_be_requested_after_acknowledgment(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-00000000000c';
        $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create();

        $this->service()->acknowledge($uuid, $actor, 'confirmed stopped');

        $guard = new \App\Services\Restore\RestoreActivityGuard();
        $this->assertSame(\App\Services\Restore\RestoreActivityState::Inactive, $guard->isActive());
    }

    // =====================================================================
    // OMS Task 7C.8 acceptance pass — explicit dual-gate confirmation
    // =====================================================================

    /**
     * The exact guarantee section L exists for: BEFORE acknowledgment, the
     * DB gate is active (status Restoring) AND the progress gate is active
     * (non-terminal, stale heartbeat) — RestoreActivityGuard::isActive()
     * blocks a new restore. AFTER acknowledgment, both are independently
     * verified terminal (not merely inferred from the guard), and the guard
     * itself now allows a new restore.
     */
    public function test_dual_gate_both_terminalize_after_acknowledgment(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-00000000000d';
        $row = $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create(['name' => 'Ops Admin', 'email' => 'ops@example.test']);

        $guard = new \App\Services\Restore\RestoreActivityGuard();

        // BEFORE: both gates active, guard blocks.
        $this->assertTrue($guard->isActive()->blocksNewRestore());
        $this->assertSame(BackupStatus::Restoring, $row->fresh()->status);
        $this->assertFalse((new RestoreProgressReader())->read($uuid)->isTerminal());

        $this->service()->acknowledge($uuid, $actor, 'Confirmed the worker process is gone.');

        // AFTER: DB gate terminal.
        $row->refresh();
        $this->assertSame(BackupStatus::RestoreFailed, $row->status);
        $this->assertFalse($row->status->isActive());

        // AFTER: signed progress gate terminal, with a still-valid signature
        // (RestoreProgressReader::read() throws on any signature failure —
        // reading it back successfully here IS the signature-validity proof).
        $progress = (new RestoreProgressReader())->read($uuid);
        $this->assertTrue($progress->isTerminal());
        $this->assertSame('restore_failed', $progress->result);
        $this->assertSame('crashed_acknowledged', $progress->restoreFailedPhase);
        $this->assertNotNull($progress->lastHeartbeatAt);

        // AFTER: both gates terminal => the guard allows a new restore.
        $this->assertSame(\App\Services\Restore\RestoreActivityState::Inactive, $guard->isActive());
        $this->assertFalse($guard->isActive()->blocksNewRestore());
    }

    /**
     * DB row missing entirely (a real possibility — e.g. a restore whose row
     * was never reconstructed, or was manually removed) with a valid, stale
     * progress file on disk: RestoreStaleDetector's disk-scan finds it
     * independent of any database row, and acknowledgment terminalizes the
     * progress gate safely — the DB update is a documented best-effort no-op
     * when there is no row to update, never an error.
     */
    public function test_missing_db_row_still_safely_terminalizes_the_progress_gate(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-00000000000e';
        // Deliberately no BackupOperation row created for this UUID.
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create();

        $this->assertNull(BackupOperation::query()->where('uuid', $uuid)->first());

        $this->service()->acknowledge($uuid, $actor, 'confirmed stopped, no DB row ever existed');

        $progress = (new RestoreProgressReader())->read($uuid);
        $this->assertTrue($progress->isTerminal());
        $this->assertSame('crashed_acknowledged', $progress->restoreFailedPhase);

        // Still no row — acknowledgment never creates one.
        $this->assertNull(BackupOperation::query()->where('uuid', $uuid)->first());

        $guard = new \App\Services\Restore\RestoreActivityGuard();
        $this->assertSame(\App\Services\Restore\RestoreActivityState::Inactive, $guard->isActive());
    }

    /**
     * A DB row that is ALREADY terminal (e.g. some other path already marked
     * it RestoreFailed) but whose progress file is still non-terminal and
     * stale is exactly the inconsistency RestoreActivityGuard's OR-logic
     * exists to catch (see RestoreActivityGuardTest's own equivalent case) —
     * it must still block a new restore, and acknowledgment (which does not
     * depend on the DB row's status at all) must still be able to close the
     * remaining open progress gate.
     */
    public function test_db_already_terminal_but_progress_still_active_blocks_until_acknowledged(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-00000000000f';
        $row = BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::RestoreFailed->value,
            'disk' => 'backups',
            'started_at' => now()->subMinutes(30),
            'failed_at' => now()->subMinutes(29),
            'operation_reason' => 'Scheduled DR drill',
        ]);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create();

        $guard = new \App\Services\Restore\RestoreActivityGuard();

        // BEFORE: DB says terminal, but the progress file's own non-terminal
        // state must still block — proves the guard's OR logic, not merely
        // the DB row, is what acknowledgment ultimately needs to satisfy.
        $this->assertSame(BackupStatus::RestoreFailed, $row->status);
        $this->assertTrue($guard->isActive()->blocksNewRestore());

        $this->service()->acknowledge($uuid, $actor, 'confirmed stopped');

        $this->assertTrue((new RestoreProgressReader())->read($uuid)->isTerminal());
        $this->assertSame(\App\Services\Restore\RestoreActivityState::Inactive, $guard->isActive());
    }

    /**
     * After a successful acknowledgment, a brand new restore request can
     * genuinely be created end to end through RestoreRequestService — not
     * merely inferred from the guard's own state in isolation.
     */
    public function test_a_new_restore_request_can_be_created_through_the_real_service_after_acknowledgment(): void
    {
        $staleUuid = 'aaaaaaaa-1111-0000-0000-000000000010';
        $this->makeRestoringRow($staleUuid);
        (new RestoreProgressWriter())->write($this->snapshot($staleUuid));
        $actor = User::factory()->create();

        $this->service()->acknowledge($staleUuid, $actor, 'confirmed stopped');

        $path = 'source-'.uniqid('', true).'.omsbak.enc';
        Storage::disk('backups')->put($path, 'not-real-bytes');
        $source = BackupOperation::create([
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

        $newRow = (new \App\Services\Restore\RestoreRequestService())
            ->createQueuedRestore($actor, $source, BackupScope::Full, 'Fresh restore after acknowledgment');

        $this->assertSame(BackupStatus::Queued, $newRow->status);
        $this->assertNotSame($staleUuid, $newRow->uuid);
    }

    /**
     * A progress-write failure during acknowledgment must propagate (never
     * be swallowed) — the caller (the Filament action) must never report
     * success, and the DB row must never be updated to RestoreFailed either,
     * since that update only runs AFTER the progress write succeeds.
     */
    public function test_a_progress_write_failure_propagates_and_never_updates_the_db_row(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000011';
        $row = $this->makeRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $actor = User::factory()->create();

        $failingWriter = new RestoreProgressWriter(new \Tests\Support\Restore\FakeRestoreProgressDurability(syncFileResult: false));
        $service = new RestoreStaleAcknowledgmentService(writer: $failingWriter);

        $threw = false;

        try {
            $service->acknowledge($uuid, $actor, 'confirmed stopped');
        } catch (\App\Services\Restore\Exceptions\RestoreProgressWriteException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A progress-write failure must propagate out of acknowledge(), never be silently swallowed.');

        // Neither gate was actually terminalized — the DB row must still be
        // exactly as it was, never updated to RestoreFailed off the back of
        // a failed write.
        $row->refresh();
        $this->assertSame(BackupStatus::Restoring, $row->status);
        $this->assertFalse((new RestoreProgressReader())->read($uuid)->isTerminal());
    }
}
