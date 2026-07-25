<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupDeletionService;
use App\Services\Backup\BackupIntegrityVerifier;
use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\Exceptions\BackupDeletionRejectedException;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use App\Services\Backup\Exceptions\BackupLockedException;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Backup\FakeProcessRunner;

/**
 * OMS Task 7C.4 correction pass — the restore-execution activity gate every
 * ordinary backup-subsystem operation must now consult (still holding the
 * shared filesystem lock) before its existing Cache/per-backup lock: closes
 * the parent-launch-to-child-lock-acquisition handoff gap, where the OS
 * flock() has already been released by the parent web request but the
 * detached `oms:restore` child has not yet (or, after a crash, will never)
 * acquire its own lifetime exclusive lock.
 */
class RestoreActivityOrdinaryOperationGateTest extends BackupTestCase
{
    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    private function superAdmin(): User
    {
        foreach (['backups.delete', 'backups.download'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $this->guard()]);
        }

        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function orchestrator(): BackupCreationOrchestrator
    {
        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());

        return $this->app->make(BackupCreationOrchestrator::class);
    }

    private function makeClaimedRestoreRow(string $uuid): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'started_at' => now(),
        ]);
    }

    private function makeQueuedRestoreRow(string $uuid): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
            'launch_nonce' => str_repeat('n', 64),
        ]);
    }

    private function writeActiveProgress(string $uuid): void
    {
        (new RestoreProgressWriter())->write(RestoreProgressSnapshot::create(
            restoreUuid: $uuid,
            requestedBy: ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            requestedAt: now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            reason: 'test',
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
    }

    private function writeTamperedProgress(string $uuid): void
    {
        Storage::disk('restores')->put("{$uuid}/progress.json", json_encode([
            'restore_uuid' => $uuid,
            'schema_version' => 1,
            'signature' => 'not-a-real-signature',
        ]));
    }

    private function makeCompletedBackup(): BackupOperation
    {
        $path = 'ordinary-op-'.uniqid('', true).'.omsbak.enc';
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

    private function assertExclusiveLockIsFree(string $message): void
    {
        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle, $message);
        $handle->release();
    }

    // ---- a claimed/running restore blocks all five ordinary operations --------------------

    public function test_creation_run_is_blocked_by_a_claimed_restore(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000001';
        $this->makeClaimedRestoreRow($uuid);
        $this->writeActiveProgress($uuid);

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, null, null);

        $this->expectException(BackupLockedException::class);

        try {
            $orchestrator->run($operation->id);
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by restore activity.');
        }
    }

    public function test_retention_run_is_blocked_by_a_claimed_restore(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000002';
        $this->makeClaimedRestoreRow($uuid);
        $this->writeActiveProgress($uuid);

        $this->expectException(BackupLockedException::class);

        try {
            (new BackupRetentionService())->run();
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by restore activity.');
        }
    }

    public function test_integrity_verify_is_blocked_by_a_claimed_restore(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000003';
        $this->makeClaimedRestoreRow($uuid);
        $this->writeActiveProgress($uuid);

        $backup = $this->makeCompletedBackup();

        $this->expectException(BackupIntegrityException::class);

        try {
            $this->app->make(BackupIntegrityVerifier::class)->verify($backup);
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by restore activity.');
        }
    }

    public function test_deletion_is_blocked_by_a_claimed_restore(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000004';
        $this->makeClaimedRestoreRow($uuid);
        $this->writeActiveProgress($uuid);

        $backup = $this->makeCompletedBackup();
        $admin = $this->superAdmin();

        $this->expectException(BackupDeletionRejectedException::class);

        try {
            (new BackupDeletionService())->delete($backup, $admin);
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by restore activity.');
        }
    }

    public function test_download_is_blocked_by_a_claimed_restore(): void
    {
        $uuid = 'aaaaaaaa-1111-0000-0000-000000000005';
        $this->makeClaimedRestoreRow($uuid);
        $this->writeActiveProgress($uuid);

        $backup = $this->makeCompletedBackup();
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->get("/backups/{$backup->uuid}/download");

        $response->assertStatus(423);
        $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by restore activity.');
    }

    // ---- a tampered progress file blocks all five ordinary operations too -----------------

    public function test_creation_run_is_blocked_by_a_tampered_progress_file(): void
    {
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000001';
        $this->writeTamperedProgress($uuid);

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, null, null);

        $this->expectException(BackupLockedException::class);

        try {
            $orchestrator->run($operation->id);
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by tampered restore state.');
        }
    }

    public function test_retention_run_is_blocked_by_a_tampered_progress_file(): void
    {
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000002';
        $this->writeTamperedProgress($uuid);

        $this->expectException(BackupLockedException::class);

        try {
            (new BackupRetentionService())->run();
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by tampered restore state.');
        }
    }

    public function test_integrity_verify_is_blocked_by_a_tampered_progress_file(): void
    {
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000003';
        $this->writeTamperedProgress($uuid);

        $backup = $this->makeCompletedBackup();

        $this->expectException(BackupIntegrityException::class);

        try {
            $this->app->make(BackupIntegrityVerifier::class)->verify($backup);
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by tampered restore state.');
        }
    }

    public function test_deletion_is_blocked_by_a_tampered_progress_file(): void
    {
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000004';
        $this->writeTamperedProgress($uuid);

        $backup = $this->makeCompletedBackup();
        $admin = $this->superAdmin();

        $this->expectException(BackupDeletionRejectedException::class);

        try {
            (new BackupDeletionService())->delete($backup, $admin);
        } finally {
            $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by tampered restore state.');
        }
    }

    public function test_download_is_blocked_by_a_tampered_progress_file(): void
    {
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000005';
        $this->writeTamperedProgress($uuid);

        $backup = $this->makeCompletedBackup();
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->get("/backups/{$backup->uuid}/download");

        $response->assertStatus(423);
        $this->assertExclusiveLockIsFree('Shared lock must be released immediately when blocked by tampered restore state.');
    }

    // ---- a merely-queued (unlaunched) restore never blocks ---------------------------------

    public function test_an_unlaunched_queued_restore_row_alone_does_not_block_creation(): void
    {
        $this->makeQueuedRestoreRow('aaaaaaaa-3333-0000-0000-000000000001');

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, null, null);

        $result = $orchestrator->run($operation->id);

        $this->assertSame(BackupStatus::Completed, $result->status);
    }

    public function test_an_unlaunched_queued_restore_row_alone_does_not_block_download(): void
    {
        $this->makeQueuedRestoreRow('aaaaaaaa-3333-0000-0000-000000000002');

        $backup = $this->makeCompletedBackup();
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->get("/backups/{$backup->uuid}/download");

        $response->assertOk();
    }

    // ---- runWithLockAlreadyHeld() is deliberately exempt -----------------------------------

    /**
     * The full pre-restore safety backup is intentionally executed INSIDE
     * the active restore itself, under its own already-validated exclusive
     * handle — runWithLockAlreadyHeld() must never perform this check
     * (doing so would make a restore permanently unable to take its own
     * mandatory safety backup). Proven here with a claimed restore row and
     * an active progress file both present — exactly the state that blocks
     * every ordinary path above — while still succeeding via
     * runWithLockAlreadyHeld().
     */
    public function test_run_with_lock_already_held_is_never_blocked_by_restore_activity(): void
    {
        $uuid = 'aaaaaaaa-4444-0000-0000-000000000001';
        $this->makeClaimedRestoreRow($uuid);
        $this->writeActiveProgress($uuid);

        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle);

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::PreRestore, BackupScope::Full, null, null);

        try {
            $result = $orchestrator->runWithLockAlreadyHeld($operation->id, $handle);

            $this->assertSame(BackupStatus::Completed, $result->status);
        } finally {
            $handle->release();
        }
    }
}
