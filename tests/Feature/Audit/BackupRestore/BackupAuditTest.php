<?php

namespace Tests\Feature\Audit\BackupRestore;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Filament\Pages\BackupManagementPage;
use App\Jobs\CreateBackupJob;
use App\Models\AuditEvent;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Audit\BackupRestore\BackupAuditRecorder;
use App\Services\Audit\BackupRestore\BackupRestoreAuditSubject;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupDeletionService;
use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\Exceptions\BackupOperationException;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Backup\FakeProcessRunner;

/**
 * OMS Task 9B.6 — the BACKUP half of `event_category = backup_restore`.
 *
 * Deliberately extends Tests\Feature\Backup\BackupTestCase (rather than
 * Tests\Feature\Audit\AuditTestCase): the backup subsystem needs its faked
 * `backups`/`attachments`/`restores` disks, its deterministic test-only
 * encryption key and its fake mysqldump process runner, and this suite drives
 * the REAL creation/deletion/retention/download paths through them. No real
 * mysqldump runs, no real backup directory is touched, and no real archive is
 * ever created, downloaded or deleted on this machine.
 */
class BackupAuditTest extends BackupTestCase
{
    private const ALL_BACKUP_PERMISSIONS = [
        'backups.view_any', 'backups.view', 'backups.create',
        'backups.verify', 'backups.download', 'backups.delete', 'backups.restore',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());

        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
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
        foreach (self::ALL_BACKUP_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $this->guard()]);
        }

        $role = Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $role->givePermissionTo(self::ALL_BACKUP_PERMISSIONS);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeNonSuperAdminWithEveryBackupPermission(): User
    {
        foreach (self::ALL_BACKUP_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $this->guard()]);
        }

        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => $this->guard()]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(self::ALL_BACKUP_PERMISSIONS);

        return $user;
    }

    private function orchestrator(): BackupCreationOrchestrator
    {
        return $this->app->make(BackupCreationOrchestrator::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditEvent>
     */
    private function events(string $action, ?string $correlationId = null)
    {
        return AuditEvent::query()
            ->where('event_category', BackupAuditRecorder::EVENT_CATEGORY)
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

    // =====================================================================
    // request: one event, correct actor, no duplication
    // =====================================================================

    public function test_manual_ui_backup_records_exactly_one_request_event_with_the_real_user_actor(): void
    {
        Queue::fake();
        $user = $this->makeSuperAdmin();
        $this->actingAs($user);

        Livewire::test(BackupManagementPage::class)
            ->callAction('createBackup', data: ['scope' => BackupScope::Full->value, 'reason' => 'routine backup'])
            ->assertHasNoActionErrors();

        $operation = BackupOperation::sole();
        $event = $this->sole(BackupAuditRecorder::ACTION_REQUESTED);

        $this->assertSame(BackupRestoreAuditSubject::Backup->value, $event->subject_type);
        $this->assertSame($operation->uuid, $event->subject_key);
        $this->assertSame($operation->uuid, $event->correlation_id);
        $this->assertSame(AuditActorType::User, $event->actor_type);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($user->email, $event->actor_email);
        $this->assertSame(AuditStatus::Success, $event->status);
        $this->assertSame('routine backup', $event->reason);
        $this->assertSame('manual', $event->new_values['backup_type']);
        $this->assertSame('full', $event->new_values['scope']);
        $this->assertTrue($event->new_values['components']['database']);
        $this->assertTrue($event->new_values['components']['private_attachments']);
    }

    /**
     * The scheduler's two entries run `oms:backup --type=daily|weekly`; both
     * must be recorded as `scheduler` with no invented user.
     */
    public function test_scheduled_backup_records_one_request_event_with_the_scheduler_actor(): void
    {
        Queue::fake();

        foreach (['daily', 'weekly'] as $type) {
            Artisan::call('oms:backup', ['--type' => $type, '--scope' => 'full']);
        }

        $events = $this->events(BackupAuditRecorder::ACTION_REQUESTED);
        $this->assertCount(2, $events);

        foreach ($events as $event) {
            $this->assertSame(AuditActorType::Scheduler, $event->actor_type);
            $this->assertNull($event->actor_user_id);
            $this->assertNull($event->actor_name);
            $this->assertNull($event->actor_email);
            $this->assertSame([], $event->actor_roles);
            $this->assertNull($event->ip_address, 'A scheduler actor must never carry fabricated request metadata.');
            $this->assertNull($event->route_name);
        }

        $this->assertSame(['daily', 'weekly'], $events->pluck('new_values.backup_type')->all());
    }

    /**
     * A second `oms:backup --type=daily` on the same day loses the unique
     * deduplication_key race, so no second row and no second event exist.
     */
    public function test_deduplicated_scheduled_backup_does_not_record_a_second_request_event(): void
    {
        Queue::fake();

        Artisan::call('oms:backup', ['--type' => 'daily', '--scope' => 'full']);
        Artisan::call('oms:backup', ['--type' => 'daily', '--scope' => 'full']);

        $this->assertSame(1, BackupOperation::query()->count());
        $this->assertCount(1, $this->events(BackupAuditRecorder::ACTION_REQUESTED));
    }

    public function test_cli_backup_records_a_command_actor_without_inventing_a_user(): void
    {
        Queue::fake();

        Artisan::call('oms:backup', ['--type' => 'manual', '--scope' => 'database']);

        $event = $this->sole(BackupAuditRecorder::ACTION_REQUESTED);

        $this->assertSame(AuditActorType::Command, $event->actor_type);
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->ip_address);
    }

    public function test_request_event_is_rolled_back_with_the_operation_row_when_it_cannot_be_persisted(): void
    {
        Schema::drop('audit_events');

        try {
            $this->orchestrator()->enqueue(BackupType::Manual, BackupScope::Full, null, null);
            $this->fail('Expected the required audit failure to prevent the backup from being queued.');
        } catch (\Throwable) {
            // Required semantics: the transaction rolls back, so no queued
            // backup exists that could never be accounted for.
        }

        $this->assertSame(0, BackupOperation::query()->count());
    }

    // =====================================================================
    // completion / failure
    // =====================================================================

    public function test_successful_backup_emits_requested_and_completed_exactly_once(): void
    {
        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, 'audit run', null);
        $completed = $orchestrator->run($operation->id);

        $this->assertSame(BackupStatus::Completed, $completed->status);

        $requested = $this->sole(BackupAuditRecorder::ACTION_REQUESTED, $operation->uuid);
        $done = $this->sole(BackupAuditRecorder::ACTION_COMPLETED, $operation->uuid);
        $this->assertCount(0, $this->events(BackupAuditRecorder::ACTION_FAILED));

        // The request event describes a not-yet-encrypted operation...
        $this->assertNull($requested->new_values['encryption_key_id']);
        $this->assertNull($requested->new_values['encryption_method']);
        $this->assertNull($requested->new_values['archive_size_bytes']);

        // ...and the completion event describes the published archive.
        $this->assertSame('completed', $done->new_values['status']);
        $this->assertSame('test-key-1', $done->new_values['encryption_key_id']);
        $this->assertSame('xchacha20poly1305_secretstream_v1', $done->new_values['encryption_method']);
        $this->assertSame($completed->checksum_sha256, $done->new_values['checksum_sha256']);
        $this->assertSame($completed->size_bytes, $done->new_values['archive_size_bytes']);
        $this->assertSame(1, $done->new_values['manifest_version']);
        $this->assertNotNull($done->new_values['completed_at']);
        $this->assertNotNull($done->new_values['verified_at']);
        $this->assertSame(AuditStatus::Success, $done->status);
    }

    public function test_failed_backup_emits_requested_and_failed_with_a_generic_failure_code(): void
    {
        $this->bindFakeProcessRunner(new FakeProcessRunner(
            exitCode: 1,
            stdout: '',
            stderr: 'Access denied for user MYSQL_PWD=leaked-secret-here while running SELECT * FROM accounts',
        ));

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        try {
            $orchestrator->run($operation->id);
            $this->fail('Expected BackupOperationException.');
        } catch (BackupOperationException) {
            // expected
        }

        $this->assertCount(1, $this->events(BackupAuditRecorder::ACTION_REQUESTED, $operation->uuid));
        $this->assertCount(0, $this->events(BackupAuditRecorder::ACTION_COMPLETED, $operation->uuid));

        $failed = $this->sole(BackupAuditRecorder::ACTION_FAILED, $operation->uuid);

        $this->assertSame(AuditStatus::Failure, $failed->status);
        $this->assertSame('failed', $failed->new_values['status']);
        $this->assertSame('database_dump_failed', $failed->new_values['failure_code']);
        $this->assertSame('database_dump', $failed->new_values['failure_category']);
        $this->assertNotNull($failed->new_values['failed_at']);

        $encoded = json_encode($failed->new_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('leaked-secret-here', $encoded);
        $this->assertStringNotContainsString('MYSQL_PWD', $encoded);
        $this->assertStringNotContainsString('SELECT', $encoded);
        $this->assertStringNotContainsString('Access denied', $encoded);
    }

    /**
     * The queue-level safety net (CreateBackupJob::failed()) and the
     * orchestrator's own handler must never both record the same failure, and a
     * retried attempt must not add a second lifecycle row either.
     */
    public function test_queue_failure_hook_and_retries_never_duplicate_the_failed_state(): void
    {
        $this->bindFakeProcessRunner(new FakeProcessRunner(exitCode: 1, stdout: '', stderr: 'dump failed'));

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);
        $job = new CreateBackupJob($operation->id);

        foreach ([1, 2, 3] as $attempt) {
            try {
                $job->handle($orchestrator);
            } catch (\Throwable $e) {
                $job->failed($e);
            }
        }

        $this->assertCount(1, $this->events(BackupAuditRecorder::ACTION_REQUESTED, $operation->uuid));
        $this->assertCount(1, $this->events(BackupAuditRecorder::ACTION_FAILED, $operation->uuid));
    }

    /**
     * A completion-audit outage must never be able to turn a fully published,
     * verified archive into a `failed` row (Task 9B.6 §6).
     */
    public function test_a_completion_audit_outage_never_falsifies_a_published_backup(): void
    {
        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, null, null);

        Schema::drop('audit_events');

        $completed = $orchestrator->run($operation->id);

        $this->assertSame(BackupStatus::Completed, $completed->status);
        $this->assertNotNull($completed->verified_at);
        $this->assertTrue(Storage::disk('backups')->exists((string) $completed->stored_path));
    }

    // =====================================================================
    // payload policy
    // =====================================================================

    public function test_payloads_never_contain_a_path_key_secret_or_archive_content(): void
    {
        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, 'payload policy', null);
        $completed = $orchestrator->run($operation->id);

        $forbiddenSubstrings = [
            storage_path(),
            base_path(),
            (string) $completed->stored_path,
            (string) $completed->encrypted_filename,
            (string) config('oms.backup.encryption.key'),
            'super-secret-password',
            '.omsbak.enc',
            '.work',
            'dump.sql',
            'archive.zip',
        ];

        foreach ($this->events(BackupAuditRecorder::ACTION_REQUESTED)->merge($this->events(BackupAuditRecorder::ACTION_COMPLETED)) as $event) {
            $encoded = json_encode([
                'new' => $event->new_values,
                'old' => $event->old_values,
                'label' => $event->subject_label,
                'reason' => $event->reason,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($forbiddenSubstrings as $forbidden) {
                if ($forbidden === '') {
                    continue;
                }

                $this->assertStringNotContainsString($forbidden, $encoded);
            }

            foreach (['stored_path', 'disk', 'archive_path', 'encryption_key', 'app_key', 'password'] as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $event->new_values);
            }
        }
    }

    // =====================================================================
    // download
    // =====================================================================

    private function makeDownloadableBackup(): BackupOperation
    {
        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, 'download fixture', null);

        return $orchestrator->run($operation->id);
    }

    public function test_authorized_download_records_one_required_event_before_serving(): void
    {
        $backup = $this->makeDownloadableBackup();
        $user = $this->makeSuperAdmin();

        $response = $this->actingAs($user)->get(route('backups.download', ['backup' => $backup->uuid]));
        $response->assertOk();
        $response->streamedContent();

        $event = $this->sole(BackupAuditRecorder::ACTION_DOWNLOADED, $backup->uuid);

        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame(AuditActorType::User, $event->actor_type);
        $this->assertSame('backups.download', $event->route_name);
        $this->assertSame('GET', $event->http_method);
        $this->assertNotNull($event->ip_address);
        $this->assertSame(BackupRestoreAuditSubject::Backup->value, $event->subject_type);
    }

    public function test_a_required_download_audit_failure_prevents_the_archive_from_being_served(): void
    {
        $backup = $this->makeDownloadableBackup();
        $user = $this->makeSuperAdmin();

        Schema::drop('audit_events');

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->get(route('backups.download', ['backup' => $backup->uuid]));
            $this->fail('Expected the required download audit failure to prevent the download.');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString((string) $backup->stored_path, $e->getMessage());
        }

        // The archive itself is untouched — refusing to serve is not deleting.
        $this->assertTrue(Storage::disk('backups')->exists((string) $backup->stored_path));
    }

    public function test_unauthorized_download_stays_denied_and_records_at_most_one_denial(): void
    {
        $backup = $this->makeDownloadableBackup();

        $this->actingAs($this->makeNonSuperAdminWithEveryBackupPermission())
            ->get(route('backups.download', ['backup' => $backup->uuid]))
            ->assertForbidden();

        $event = $this->sole(BackupAuditRecorder::ACTION_DOWNLOAD_DENIED, $backup->uuid);

        $this->assertSame(AuditStatus::Failure, $event->status);
        $this->assertCount(0, $this->events(BackupAuditRecorder::ACTION_DOWNLOADED));
    }

    public function test_an_audit_outage_never_softens_a_download_denial(): void
    {
        $backup = $this->makeDownloadableBackup();
        $user = $this->makeNonSuperAdminWithEveryBackupPermission();

        Schema::drop('audit_events');

        $this->actingAs($user)
            ->get(route('backups.download', ['backup' => $backup->uuid]))
            ->assertForbidden();
    }

    public function test_an_unknown_backup_uuid_records_nothing_at_all(): void
    {
        $this->actingAs($this->makeNonSuperAdminWithEveryBackupPermission())
            ->get(route('backups.download', ['backup' => '11111111-2222-3333-4444-555555555555']))
            ->assertForbidden();

        $this->assertSame(0, AuditEvent::query()->count(), 'A guessed identifier must never create an audit row.');
    }

    // =====================================================================
    // deletion
    // =====================================================================

    /**
     * A deletable target plus a keeper that is unambiguously the "last known
     * good" backup. The keeper's `completed_at` is pushed forward explicitly:
     * `BackupDeletionService::isLastKnownGood()` orders by `completed_at` desc
     * and takes the first id, so two backups completed within the same second
     * would leave which one is protected up to arbitrary row order.
     */
    private function makeDeletableTarget(): BackupOperation
    {
        $keeper = $this->makeDownloadableBackup();
        $target = $this->makeDownloadableBackup();

        $keeper->forceFill(['completed_at' => now()->addMinutes(5)])->save();

        return $target;
    }

    public function test_manual_deletion_records_requested_then_deleted_exactly_once(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin);

        $target = $this->makeDeletableTarget();

        (new BackupDeletionService())->delete($target, $admin);

        $requested = $this->sole(BackupAuditRecorder::ACTION_DELETE_REQUESTED, $target->uuid);
        $deleted = $this->sole(BackupAuditRecorder::ACTION_DELETED, $target->uuid);

        $this->assertSame('manual', $requested->new_values['delete_trigger']);
        $this->assertSame($admin->id, $requested->actor_user_id);
        $this->assertTrue($deleted->new_values['archive_file_removed']);
        $this->assertSame('manual', $deleted->new_values['delete_trigger']);
        $this->assertLessThan($deleted->id, $requested->id, 'The request must be recorded before the deletion.');
        $this->assertFalse(Storage::disk('backups')->exists((string) $target->stored_path));
    }

    public function test_a_required_delete_audit_failure_leaves_the_archive_in_place(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin);

        $target = $this->makeDeletableTarget();

        Schema::drop('audit_events');

        try {
            (new BackupDeletionService())->delete($target, $admin);
            $this->fail('Expected the required delete audit failure to prevent the deletion.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertTrue(
            Storage::disk('backups')->exists((string) $target->stored_path),
            'An unaccountable deletion must not happen: the archive stays in place.',
        );
        $this->assertNull($target->fresh()->deleted_at);
    }

    public function test_retention_deletion_records_the_same_two_states_with_a_retention_trigger(): void
    {
        config(['oms.backup.retention.daily' => 1]);

        $orchestrator = $this->orchestrator();
        $uuids = [];

        // Three daily backups: with a keep-window of 1, the two oldest become
        // deletable. One extra manual backup keeps "last known good" off them.
        $this->makeDownloadableBackup();

        foreach ([1, 2, 3] as $index) {
            $operation = $orchestrator->enqueue(BackupType::Daily, BackupScope::Database, "daily {$index}", null);
            // The deduplication key is per calendar day, so the row must be
            // created directly for the second and third of the same day.
            $completed = $orchestrator->run($operation->id);
            $uuids[] = $completed->uuid;
            $completed->forceFill(['deduplication_key' => null, 'completed_at' => now()->addMinutes($index)])->save();
        }

        (new BackupRetentionService())->run();

        $deletedEvents = $this->events(BackupAuditRecorder::ACTION_DELETED);
        $this->assertGreaterThan(0, $deletedEvents->count());

        foreach ($deletedEvents as $event) {
            $this->assertSame('retention', $event->new_values['delete_trigger']);
            $this->assertSame(1, $event->new_values['retention_keep_count']);
            $this->assertSame(AuditActorType::Command, $event->actor_type);
            $this->assertNull($event->actor_user_id, 'Retention must never invent a user.');

            $this->assertCount(
                1,
                $this->events(BackupAuditRecorder::ACTION_DELETE_REQUESTED, $event->correlation_id),
                'Each retention deletion records exactly one delete_requested.',
            );
        }
    }

    public function test_a_second_retention_run_never_duplicates_a_deletion_state(): void
    {
        config(['oms.backup.retention.daily' => 1]);

        $orchestrator = $this->orchestrator();
        $this->makeDownloadableBackup();

        foreach ([1, 2] as $index) {
            $operation = $orchestrator->enqueue(BackupType::Daily, BackupScope::Database, "daily {$index}", null);
            $completed = $orchestrator->run($operation->id);
            $completed->forceFill(['deduplication_key' => null, 'completed_at' => now()->addMinutes($index)])->save();
        }

        $retention = new BackupRetentionService();
        $retention->run();

        $firstRunDeleted = $this->events(BackupAuditRecorder::ACTION_DELETED)->count();
        $firstRunRequested = $this->events(BackupAuditRecorder::ACTION_DELETE_REQUESTED)->count();

        $retention->run();

        $this->assertSame($firstRunDeleted, $this->events(BackupAuditRecorder::ACTION_DELETED)->count());
        $this->assertSame($firstRunRequested, $this->events(BackupAuditRecorder::ACTION_DELETE_REQUESTED)->count());
    }

    /**
     * Directly proves the ledger's at-most-once rule for every guarded state,
     * independent of which layer happens to call it.
     */
    public function test_repeated_recorder_calls_never_duplicate_a_guarded_lifecycle_state(): void
    {
        $backup = $this->makeDownloadableBackup();
        $recorder = $this->app->make(BackupAuditRecorder::class);

        foreach ([1, 2, 3] as $ignored) {
            $recorder->backupCompleted($backup);
            $recorder->backupFailed($backup);
            $recorder->backupDeleteRequested($backup, BackupAuditRecorder::TRIGGER_MANUAL);
            $recorder->backupDeleted($backup, BackupAuditRecorder::TRIGGER_MANUAL, archiveFileRemoved: true);
        }

        foreach ([
            BackupAuditRecorder::ACTION_COMPLETED,
            BackupAuditRecorder::ACTION_FAILED,
            BackupAuditRecorder::ACTION_DELETE_REQUESTED,
            BackupAuditRecorder::ACTION_DELETED,
        ] as $action) {
            $this->assertCount(1, $this->events($action, $backup->uuid), "{$action} must be recorded at most once.");
        }
    }
}
