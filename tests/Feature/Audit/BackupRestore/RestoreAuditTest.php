<?php

namespace Tests\Feature\Audit\BackupRestore;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\AuditEvent;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Audit\BackupRestore\BackupRestoreAuditSubject;
use App\Services\Audit\BackupRestore\RestoreAuditRecorder;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Services\Restore\RestoreLaunchOutcomeStatus;
use App\Services\Restore\RestoreLaunchService;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Services\Restore\RestoreRequestService;
use App\Services\Restore\RestoreStaleAcknowledgmentService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Restore\FakeProcessStreamInputRunner;
use Tests\Support\Restore\FakeRestoreProcessLauncher;
use Tests\Support\Restore\RestoreOrchestratorTestFixtures;

/**
 * OMS Task 9B.6 — the RESTORE half of `event_category = backup_restore`,
 * including the part that only matters because a restore replaces the very
 * table these events live in.
 *
 * SIMULATING A DATABASE REPLACEMENT. A real `mysql` import cannot run in this
 * suite (the whole restore engine is exercised against a schema-only SQLite
 * :memory: connection with the mysql/mysqldump client processes faked — see
 * RestoreOrchestratorTestFixtures), so the one effect that matters for auditing
 * is reproduced directly: every existing `audit_events` row is removed by a raw
 * DB delete, exactly as an import replacing that table would, while the signed
 * progress journal on the private `restores` disk is left untouched. What the
 * replay then reconstructs can therefore only have come from that journal. (The
 * raw `DB::table()` delete is deliberate: AuditEvent itself forbids deletes at
 * the application level, and a controlled maintenance path is the documented
 * exception — see the model's docblock.)
 *
 * No real restore is performed and no real database is replaced.
 */
class RestoreAuditTest extends BackupTestCase
{
    use RestoreOrchestratorTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestoreOrchestratorFixtures();
    }

    // =====================================================================
    // helpers
    // =====================================================================

    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    private function makeSuperAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'backups.restore', 'guard_name' => $this->guard()]);

        $role = Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $role->givePermissionTo('backups.restore');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditEvent>
     */
    private function events(string $action, ?string $correlationId = null)
    {
        return AuditEvent::query()
            ->where('event_category', RestoreAuditRecorder::EVENT_CATEGORY)
            ->where('event_action', $action)
            ->when($correlationId !== null, fn ($q) => $q->where('correlation_id', $correlationId))
            ->orderBy('id')
            ->get();
    }

    private function sole(string $action, ?string $correlationId = null): AuditEvent
    {
        $events = $this->events($action, $correlationId);

        $this->assertCount(1, $events, "Expected exactly one {$action} event, found ".$events->count().'.');

        return $events->first();
    }

    /**
     * Reproduces the only effect of a real database import that matters here:
     * the audit table this restore already wrote to is gone.
     */
    private function simulateDatabaseReplacement(): void
    {
        DB::table('audit_events')->delete();

        $this->assertSame(0, AuditEvent::query()->count());
    }

    private function makeQueuedRestoreThroughTheRealService(User $actor, BackupOperation $source): BackupOperation
    {
        return app(RestoreRequestService::class)->createQueuedRestore(
            $actor,
            $source,
            BackupScope::Database,
            'Audited disaster-recovery drill',
        );
    }

    // =====================================================================
    // request + confirmation
    // =====================================================================

    public function test_a_restore_request_records_one_event_carrying_the_confirming_actor(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $restore = $this->makeQueuedRestoreThroughTheRealService($actor, $source);

        $event = $this->sole(RestoreAuditRecorder::ACTION_REQUESTED, $restore->uuid);

        $this->assertSame(BackupRestoreAuditSubject::Restore->value, $event->subject_type);
        $this->assertSame($restore->uuid, $event->subject_key);
        $this->assertSame($restore->uuid, $event->correlation_id);
        $this->assertSame(AuditActorType::User, $event->actor_type);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame('Audited disaster-recovery drill', $event->reason);
        $this->assertSame(AuditStatus::Success, $event->status);

        $payload = $event->new_values;
        $this->assertSame($source->uuid, $payload['source_backup_uuid']);
        $this->assertSame('database', $payload['scope']);
        $this->assertTrue($payload['components']['database']);
        $this->assertFalse($payload['components']['private_attachments']);
        $this->assertSame($actor->id, $payload['requested_by']['user_id']);
        $this->assertSame($actor->email, $payload['requested_by']['email']);
        // The confirmation is part of the request in this application — the
        // wizard validates both steps before the service is ever reached.
        $this->assertNotNull($payload['confirmed_at']);
        $this->assertSame($source->checksum_sha256, $payload['source_archive_checksum_sha256']);
        $this->assertSame($source->size_bytes, $payload['source_archive_size_bytes']);
        $this->assertSame('test-key-1', $payload['encryption_key_id']);
    }

    public function test_a_required_request_audit_failure_prevents_the_queued_restore_from_existing(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);

        Schema::drop('audit_events');

        try {
            $this->makeQueuedRestoreThroughTheRealService($actor, $source);
            $this->fail('Expected the required audit failure to prevent the restore request.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(
            0,
            BackupOperation::query()->where('type', BackupType::Restore->value)->count(),
            'A restore that could not be audited must never be queued.',
        );
    }

    // =====================================================================
    // start (claim)
    // =====================================================================

    public function test_claiming_a_queued_restore_records_one_started_event(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $restore = $this->makeQueuedRestoreThroughTheRealService($actor, $source);

        $launcher = new FakeRestoreProcessLauncher();
        $outcome = (new RestoreLaunchService($launcher))->launch($restore->uuid, (string) $restore->launch_nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Accepted, $outcome->status);

        $event = $this->sole(RestoreAuditRecorder::ACTION_STARTED, $restore->uuid);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame('restoring', $event->new_values['status']);
        $this->assertNotNull($event->new_values['started_at']);
    }

    public function test_a_required_start_audit_failure_leaves_the_restore_relaunchable(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $restore = $this->makeQueuedRestoreThroughTheRealService($actor, $source);
        $nonce = (string) $restore->launch_nonce;

        Schema::drop('audit_events');

        try {
            (new RestoreLaunchService(new FakeRestoreProcessLauncher()))->launch($restore->uuid, $nonce);
            $this->fail('Expected the required audit failure to prevent the claim.');
        } catch (\Throwable) {
            // expected
        }

        $fresh = $restore->fresh();
        $this->assertSame(BackupStatus::Queued, $fresh->status, 'The claim must roll back with its audit event.');
        $this->assertNull($fresh->started_at);
        $this->assertSame($nonce, $fresh->launch_nonce, 'The nonce must survive so the launch can be retried.');
    }

    public function test_a_launch_failure_records_one_failed_event_with_its_reason_code(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $restore = $this->makeQueuedRestoreThroughTheRealService($actor, $source);

        $launcher = new FakeRestoreProcessLauncher();
        $launcher->failNextWith(RestoreProcessLaunchException::spawnFailed());
        $outcome = (new RestoreLaunchService($launcher))->launch($restore->uuid, (string) $restore->launch_nonce);

        $this->assertSame(RestoreLaunchOutcomeStatus::Failed, $outcome->status);

        $event = $this->sole(RestoreAuditRecorder::ACTION_FAILED, $restore->uuid);
        $this->assertSame(AuditStatus::Failure, $event->status);
        $this->assertSame('launching', $event->new_values['failed_phase']);
        $this->assertNotNull($event->new_values['failure_code']);
        $this->assertNotSame('', $event->new_values['failure_code']);
    }

    // =====================================================================
    // database-replacement survival and reconciliation replay
    // =====================================================================

    /**
     * The core Task 9B.6 §7 requirement, end to end through the REAL restore
     * engine: the pre-restore lifecycle is written, the audit table is then
     * replaced, and the authoritative lifecycle reappears in the restored table
     * — reconstructed only from the signed progress journal.
     */
    public function test_the_restore_lifecycle_survives_a_simulated_database_replacement(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);
        (new RestoreProgressWriter())->write($progress);

        // The two pre-restore states, as the real services would have written
        // them into the database that is about to be replaced.
        $recorder = app(RestoreAuditRecorder::class);
        $recorder->restoreRequested($row, $source);
        $recorder->restoreStarted($row);
        $this->assertCount(1, $this->events(RestoreAuditRecorder::ACTION_REQUESTED, $uuid));
        $this->assertCount(1, $this->events(RestoreAuditRecorder::ACTION_STARTED, $uuid));

        $this->simulateDatabaseReplacement();

        // The durable journal is what survives — and it is still valid/signed.
        $this->assertTrue(Storage::disk('restores')->exists($uuid.'/progress.json'));
        $this->assertSame($uuid, (new RestoreProgressReader())->read($uuid)->restoreUuid);

        // The real engine runs in a DETACHED `oms:restore` process with no
        // authenticated session at all, so the ambient auth used for the two
        // interactive pre-restore writes above is dropped here. That is what
        // makes the actor assertions below meaningful: everything the engine
        // records must resolve to a `command` actor and recover the original
        // requester from the journal, never from ambient state.
        auth()->logout();

        [$orchestrator, , , , $artisan] = $this->buildOrchestrator();
        $lock = $this->acquireLock();

        try {
            $result = $orchestrator->orchestrate($row, (new RestoreProgressReader())->read($uuid), $lock);
        } finally {
            $lock->release();
        }

        $this->assertRestoredWithDiagnostics($result, $uuid);

        // The whole lifecycle is back, each state exactly once.
        $requested = $this->sole(RestoreAuditRecorder::ACTION_REQUESTED, $uuid);
        $started = $this->sole(RestoreAuditRecorder::ACTION_STARTED, $uuid);
        $reconciled = $this->sole(RestoreAuditRecorder::ACTION_RECONCILED, $uuid);
        $completed = $this->sole(RestoreAuditRecorder::ACTION_COMPLETED, $uuid);

        // ...and it is honestly marked as reconstructed rather than original.
        $this->assertTrue($requested->new_values['replayed_after_database_replacement']);
        $this->assertTrue($started->new_values['replayed_after_database_replacement']);
        $this->assertTrue($reconciled->new_values['replayed_after_database_replacement']);

        // Correlation id links every pre- and post-replacement state.
        foreach ([$requested, $started, $reconciled, $completed] as $event) {
            $this->assertSame($uuid, $event->correlation_id);
            $this->assertSame($uuid, $event->subject_key);
            $this->assertSame(BackupRestoreAuditSubject::Restore->value, $event->subject_type);
        }

        // The original requester snapshot survived outside the database.
        $this->assertSame('admin@example.com', $requested->new_values['requested_by']['email']);
        $this->assertSame('Admin', $completed->new_values['requested_by']['name']);
        $this->assertSame($source->uuid, $completed->new_values['source_backup_uuid']);
        $this->assertNotNull($completed->new_values['pre_restore_safety_backup_uuid']);

        // Reconciliation status is reported, and permission synchronization
        // really did run without adding any restore event of its own.
        $this->assertTrue($reconciled->new_values['reconciliation']['migrations_applied']);
        $this->assertTrue($reconciled->new_values['reconciliation']['permissions_synced']);
        $this->assertContains('oms:sync-permissions', $artisan->calls);

        $this->assertSame('restored', $completed->new_values['status']);
        $this->assertSame('restore_finalized', $completed->new_values['recovery_state']);
        $this->assertSame(AuditStatus::Success, $completed->status);
        $this->assertSame(AuditActorType::Command, $completed->actor_type);
    }

    public function test_replaying_the_reconciliation_repeatedly_never_duplicates_an_event(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);
        (new RestoreProgressWriter())->write($progress);

        $this->simulateDatabaseReplacement();

        [$orchestrator] = $this->buildOrchestrator();
        $lock = $this->acquireLock();

        try {
            $orchestrator->orchestrate($row, (new RestoreProgressReader())->read($uuid), $lock);
        } finally {
            $lock->release();
        }

        $baseline = [
            RestoreAuditRecorder::ACTION_REQUESTED => 1,
            RestoreAuditRecorder::ACTION_STARTED => 1,
            RestoreAuditRecorder::ACTION_RECONCILED => 1,
            RestoreAuditRecorder::ACTION_COMPLETED => 1,
        ];

        foreach ($baseline as $action => $expected) {
            $this->assertCount($expected, $this->events($action, $uuid));
        }

        // A recovery run that re-executes reconciliation and the terminal write
        // for the same operation must add nothing.
        $terminal = (new RestoreProgressReader())->read($uuid);
        $snapshot = $terminal->reconciliationSnapshot;
        $this->assertNotNull($snapshot);

        $recorder = app(RestoreAuditRecorder::class);

        foreach ([1, 2] as $ignored) {
            $recorder->replayAfterDatabaseReplacement(
                $snapshot->sourceBackup,
                $snapshot->safetyBackup,
                $snapshot->restoreOperation,
            );
            $recorder->restoreTerminal($terminal, BackupStatus::Restored);
        }

        foreach ($baseline as $action => $expected) {
            $this->assertCount($expected, $this->events($action, $uuid), "{$action} must remain at most once.");
        }
    }

    // =====================================================================
    // failure / interruption
    // =====================================================================

    public function test_a_failed_database_import_records_one_failed_event_with_a_generic_code(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        (new RestoreProgressWriter())->write($this->initialProgress($uuid, $source, BackupScope::Database));

        [$orchestrator] = $this->buildOrchestrator(
            dbRunner: new FakeProcessStreamInputRunner(exitCode: 1, stderr: 'ERROR 1045 (28000): Access denied for user; SELECT * FROM users'),
        );
        $lock = $this->acquireLock();

        try {
            $result = $orchestrator->orchestrate($row, (new RestoreProgressReader())->read($uuid), $lock);
        } finally {
            $lock->release();
        }

        $this->assertSame(BackupStatus::RestoreFailed, $result);

        $event = $this->sole(RestoreAuditRecorder::ACTION_FAILED, $uuid);
        $this->assertCount(0, $this->events(RestoreAuditRecorder::ACTION_COMPLETED, $uuid));
        $this->assertSame(AuditStatus::Failure, $event->status);
        $this->assertSame('database_import', $event->new_values['failure_category']);
        $this->assertNotNull($event->new_values['failed_phase']);
        $this->assertNotNull($event->new_values['failed_at']);
        $this->assertNull($event->new_values['completed_at']);

        // Deliberately asserted against distinctive SUBSTRINGS of the driver
        // error rather than the bare error number: a bare "1045" can legitimately
        // occur inside a numeric metadata field (an archive byte size, say), so
        // asserting on it would be a false alarm rather than a leak.
        $encoded = json_encode($event->new_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('SELECT', $encoded);
        $this->assertStringNotContainsString('Access denied', $encoded);
        $this->assertStringNotContainsString('ERROR 1045', $encoded);
        $this->assertStringNotContainsString('28000', $encoded);
    }

    public function test_an_acknowledged_crashed_restore_records_an_interrupted_event(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $uuid = 'aaaaaaaa-1111-0000-0000-0000000000aa';

        BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'started_at' => now()->subMinutes(30),
            'operation_reason' => 'Interrupted DR drill',
        ]);

        $stale = RestoreProgressSnapshot::create(
            restoreUuid: $uuid,
            requestedBy: ['user_id' => $actor->id, 'name' => (string) $actor->name, 'email' => (string) $actor->email],
            requestedAt: now()->subHour()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            reason: 'Interrupted DR drill',
            scope: 'full',
            sourceBackupUuid: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            preRestoreSafetyBackupUuid: null,
            phase: 'staging',
            phaseHistory: [],
            lastHeartbeatAt: now()->subMinutes(30)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
        );

        (new RestoreProgressWriter())->write($stale);

        (new RestoreStaleAcknowledgmentService())->acknowledge($uuid, $actor, 'Server was power-cycled; confirmed stopped.');

        $event = $this->sole(RestoreAuditRecorder::ACTION_INTERRUPTED, $uuid);

        $this->assertSame(AuditStatus::Failure, $event->status);
        $this->assertSame(AuditActorType::User, $event->actor_type);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame('crashed_acknowledged', $event->new_values['failed_phase']);
        $this->assertSame('interrupted_acknowledged_by_super_admin', $event->new_values['recovery_state']);
        $this->assertSame($actor->id, $event->new_values['acknowledged_by']['user_id']);
        $this->assertSame('Server was power-cycled; confirmed stopped.', $event->reason);

        // An interruption is never reported as an engine failure or a success.
        $this->assertCount(0, $this->events(RestoreAuditRecorder::ACTION_FAILED, $uuid));
        $this->assertCount(0, $this->events(RestoreAuditRecorder::ACTION_COMPLETED, $uuid));
    }

    // =====================================================================
    // payload policy
    // =====================================================================

    public function test_restore_payloads_never_contain_a_path_secret_nonce_or_archive_content(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->actingAs($actor);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        (new RestoreProgressWriter())->write($this->initialProgress($uuid, $source, BackupScope::Database));

        [$orchestrator] = $this->buildOrchestrator();
        $lock = $this->acquireLock();

        try {
            $orchestrator->orchestrate($row, (new RestoreProgressReader())->read($uuid), $lock);
        } finally {
            $lock->release();
        }

        $forbidden = [
            storage_path(),
            base_path(),
            (string) $source->stored_path,
            (string) config('oms.backup.encryption.key'),
            'super-secret-password',
            '.omsbak.enc',
            'dump.sql',
            'workspace',
            'mysql',
        ];

        $events = AuditEvent::query()
            ->where('event_category', RestoreAuditRecorder::EVENT_CATEGORY)
            ->where('correlation_id', $uuid)
            ->get();

        $this->assertGreaterThan(0, $events->count());

        foreach ($events as $event) {
            $encoded = json_encode([
                'new' => $event->new_values,
                'old' => $event->old_values,
                'label' => $event->subject_label,
                'reason' => $event->reason,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($forbidden as $needle) {
                if ($needle === '') {
                    continue;
                }

                $this->assertStringNotContainsString($needle, $encoded, "A restore audit payload leaked: {$needle}");
            }

            foreach (['launch_nonce', 'nonce', 'confirmation_phrase', 'encryption_key', 'app_key', 'stored_path', 'archive_path'] as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $event->new_values);
            }
        }
    }
}
