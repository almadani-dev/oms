<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Backup\BackupDeletionService;
use App\Services\Backup\BackupFileLock;
use App\Services\Backup\Exceptions\BackupDeletionRejectedException;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Covers OMS Task 7B.2's "MANUAL DELETE SERVICE" test category (62-74,
 * minus the purely-UI items covered by BackupManagementPageTest). Every
 * rule is exercised directly against BackupDeletionService, independent of
 * any Livewire/HTTP layer — the deeper of the two defense layers, mirroring
 * PermissionSyncActionTest/PermissionManagementServiceTest's established
 * split in this codebase.
 */
class BackupDeletionServiceTest extends BackupTestCase
{
    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function nonSuperAdminWithDirectPermission(): User
    {
        Permission::firstOrCreate(['name' => 'backups.delete', 'guard_name' => $this->guard()]);
        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => $this->guard()]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('backups.delete');

        return $user;
    }

    private function makeCompletedOperation(array $attrs = []): BackupOperation
    {
        $path = $attrs['stored_path'] ?? ('delete-test-'.uniqid('', true).'.omsbak.enc');

        if (! array_key_exists('__skip_file', $attrs)) {
            Storage::disk('backups')->put($path, 'encrypted-bytes');
        }

        unset($attrs['__skip_file']);

        return BackupOperation::create(array_merge([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => $path,
            'encrypted_filename' => $path,
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'completed_at' => now()->subDay(),
            'is_protected' => false,
        ], $attrs));
    }

    // ---- 63/71. a completed, unprotected, non-last-good backup can be deleted; metadata is soft-deleted ----

    public function test_a_normal_manual_backup_can_be_deleted_and_is_soft_deleted(): void
    {
        $admin = $this->superAdmin();
        // A separate, more recent verified backup so the one under test is
        // never treated as "last known-good".
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        $operation = $this->makeCompletedOperation(['verified_at' => null]);
        $path = $operation->stored_path;

        $result = (new BackupDeletionService())->delete($operation, $admin);

        $this->assertFalse($result->fileWasAlreadyMissing);
        $this->assertFalse(Storage::disk('backups')->exists($path));

        $fresh = BackupOperation::withTrashed()->find($operation->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame(BackupStatus::Deleted, $fresh->status);
    }

    // ---- 44/70 equivalent at service level: non-Super-Admin with the permission is still rejected ----

    public function test_non_super_admin_with_direct_permission_is_rejected(): void
    {
        $user = $this->nonSuperAdminWithDirectPermission();
        $this->assertTrue($user->can('backups.delete'));

        $operation = $this->makeCompletedOperation();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        try {
            (new BackupDeletionService())->delete($operation, $user);
        } finally {
            $this->assertNull($operation->fresh()->deleted_at, 'Backup must remain untouched when the actor is rejected.');
        }
    }

    // ---- 64. protected backup cannot be deleted ----

    public function test_a_protected_backup_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $operation = $this->makeCompletedOperation(['is_protected' => true]);

        $this->expectException(BackupDeletionRejectedException::class);

        try {
            (new BackupDeletionService())->delete($operation, $admin);
        } finally {
            $this->assertNull($operation->fresh()->deleted_at);
        }
    }

    // ---- 65. last known-good backup cannot be deleted ----

    public function test_the_last_known_good_backup_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $operation = $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        try {
            (new BackupDeletionService())->delete($operation, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame('last_known_good', $e->reasonCode);
        }

        $this->assertNull($operation->fresh()->deleted_at);
    }

    // ---- 66. active backup cannot be deleted (parameterized over active statuses) ----

    #[DataProvider('activeStatusProvider')]
    public function test_an_active_backup_cannot_be_deleted(BackupStatus $status): void
    {
        $admin = $this->superAdmin();
        $operation = $this->makeCompletedOperation(['status' => $status->value, 'completed_at' => null, 'verified_at' => null]);

        try {
            (new BackupDeletionService())->delete($operation, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame('active_status', $e->reasonCode);
        }
    }

    public static function activeStatusProvider(): array
    {
        return [
            'queued' => [BackupStatus::Queued],
            'running' => [BackupStatus::Running],
            'verifying' => [BackupStatus::Verifying],
            'deleting' => [BackupStatus::Deleting],
            'restoring' => [BackupStatus::Restoring],
        ];
    }

    // ---- 67. referenced pre_restore backup cannot be deleted ----

    public function test_a_pre_restore_backup_referenced_by_another_operation_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()->subHour()]);

        $preRestore = $this->makeCompletedOperation(['type' => BackupType::PreRestore->value]);

        BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'pre_restore_safety_backup_id' => $preRestore->id,
        ]);

        try {
            (new BackupDeletionService())->delete($preRestore, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame('referenced_pre_restore', $e->reasonCode);
        }
    }

    // ---- OMS Task 7C.1: a backup currently used as a restore source cannot be deleted ----

    /**
     * Every active BackupStatus a restore-type row could be in — not just
     * `Restoring` — must block the source backup. Mirrors
     * activeStatusProvider() below, which independently drives the
     * pre-existing `active_status` rule test for the same reason: the
     * active-status set is a single source of truth (BackupStatus::isActive())
     * and both rules must honor it identically.
     */
    #[DataProvider('activeStatusProvider')]
    public function test_a_backup_that_is_the_source_of_a_non_terminal_restore_cannot_be_deleted(BackupStatus $restoreStatus): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()->subHour()]);

        $source = $this->makeCompletedOperation();

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => $restoreStatus->value,
            'disk' => 'backups',
            'source_backup_id' => $source->id,
        ]);

        try {
            (new BackupDeletionService())->delete($source, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame('restore_source_in_use', $e->reasonCode);
        }

        $this->assertNull($source->fresh()->deleted_at);
    }

    /**
     * Every terminal restore outcome — Restored, RestoreFailed, and the
     * degraded RestorePartial alike — must NOT keep the source backup
     * blocked by this rule once the restore is no longer in progress.
     */
    #[DataProvider('terminalRestoreStatusProvider')]
    public function test_a_restore_source_backup_becomes_deletable_once_the_restore_is_terminal(BackupStatus $restoreStatus): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()->subHour()]);

        $source = $this->makeCompletedOperation();

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => $restoreStatus->value,
            'disk' => 'backups',
            'source_backup_id' => $source->id,
        ]);

        $result = (new BackupDeletionService())->delete($source, $admin);

        $this->assertNotNull($source->fresh()->deleted_at);
        $this->assertFalse($result->fileWasAlreadyMissing);
    }

    public static function terminalRestoreStatusProvider(): array
    {
        return [
            'restored' => [BackupStatus::Restored],
            'restore_failed' => [BackupStatus::RestoreFailed],
            'restore_partial' => [BackupStatus::RestorePartial],
        ];
    }

    public function test_eligibility_and_delete_agree_a_restore_source_backup_is_blocked(): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()->subHour()]);

        $source = $this->makeCompletedOperation();

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'source_backup_id' => $source->id,
        ]);

        $eligibility = (new BackupDeletionService())->eligibility($source);
        $this->assertFalse($eligibility->allowed);
        $this->assertSame('restore_source_in_use', $eligibility->reasonCode);

        try {
            (new BackupDeletionService())->delete($source, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame($eligibility->reasonCode, $e->reasonCode);
        }
    }

    // ---- 68. locked/in-use backup cannot be deleted ----

    public function test_a_locked_backup_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);
        $operation = $this->makeCompletedOperation(['verified_at' => null]);

        $externalLock = Cache::lock(BackupFileLock::name($operation->uuid), 60);
        $this->assertTrue($externalLock->get());

        try {
            (new BackupDeletionService())->delete($operation, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame('locked', $e->reasonCode);
        } finally {
            $externalLock->release();
        }

        $this->assertNull($operation->fresh()->deleted_at);
        $this->assertSame(BackupStatus::Completed, $operation->fresh()->status);
    }

    // ---- 69. missing physical file is handled safely ----

    public function test_a_missing_physical_file_is_handled_safely(): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);
        $operation = $this->makeCompletedOperation(['verified_at' => null]);

        Storage::disk('backups')->delete($operation->stored_path);

        $result = (new BackupDeletionService())->delete($operation, $admin);

        $this->assertTrue($result->fileWasAlreadyMissing);
        $this->assertNotNull($operation->fresh()->deleted_at);
    }

    // ---- 70. path traversal is rejected (unsafe stored_path never touched, metadata still safely soft-deleted) ----

    public function test_unsafe_stored_path_is_never_used_for_a_real_file_operation(): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        // Laravel's own local Flysystem adapter already refuses to read/
        // write a traversal path (throws), so SafeBackupPath::isSafe()'s
        // guard inside the service must reject it BEFORE any disk call is
        // even attempted — proven here by the delete succeeding (soft-only)
        // rather than the adapter's own traversal exception bubbling up.
        $operation = $this->makeCompletedOperation([
            'stored_path' => '../../etc/passwd',
            'verified_at' => null,
            '__skip_file' => true,
        ]);

        $result = (new BackupDeletionService())->delete($operation, $admin);

        $this->assertTrue($result->fileWasAlreadyMissing);
        $this->assertNotNull($operation->fresh()->deleted_at);
    }

    // ---- 72. no bulk delete exists (structural — asserted against the service's own single-record signature) ----

    public function test_deletion_service_only_ever_accepts_a_single_operation(): void
    {
        $method = new \ReflectionMethod(BackupDeletionService::class, 'delete');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame(BackupOperation::class, $parameters[0]->getType()?->getName());
    }

    // ---- 74. deletion failure does not leave status stuck at "deleting" ----

    public function test_an_unexpected_failure_restores_the_prior_status_instead_of_leaving_it_stuck_deleting(): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        $operation = $this->makeCompletedOperation(['verified_at' => null]);

        // Force a failure mid-delete via a disk whose delete() genuinely
        // throws, so the service's own catch/restore path is exercised for
        // real rather than assumed.
        $failingDisk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $failingDisk->shouldReceive('exists')->andReturn(true);
        $failingDisk->shouldReceive('delete')->andThrow(new \RuntimeException('simulated disk failure'));
        Storage::set('backups', $failingDisk);

        try {
            (new BackupDeletionService())->delete($operation, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame('unexpected_failure', $e->reasonCode);
        }

        $fresh = $operation->fresh();
        $this->assertNotSame(BackupStatus::Deleting, $fresh->status);
        $this->assertSame(BackupStatus::Completed, $fresh->status);
        $this->assertNull($fresh->deleted_at);
    }

    // ---- already-deleted row is rejected, not silently re-processed ----

    public function test_an_already_deleted_operation_cannot_be_deleted_again(): void
    {
        $admin = $this->superAdmin();
        $operation = $this->makeCompletedOperation();
        $operation->delete();

        try {
            (new BackupDeletionService())->delete($operation, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame('already_deleted', $e->reasonCode);
        }
    }

    // ---- eligibility() (the read-only "إمكانية الحذف" badge decision) must
    // agree with delete() exactly, for every rule ----

    public function test_eligibility_allows_an_older_unprotected_non_last_good_unlocked_backup(): void
    {
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);
        $operation = $this->makeCompletedOperation(['verified_at' => null, 'completed_at' => now()->subDay()]);

        $eligibility = (new BackupDeletionService())->eligibility($operation);

        $this->assertTrue($eligibility->allowed);
        $this->assertNull($eligibility->reasonCode);
    }

    public function test_eligibility_and_delete_agree_the_last_known_good_backup_is_blocked(): void
    {
        $admin = $this->superAdmin();
        $operation = $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        $eligibility = (new BackupDeletionService())->eligibility($operation);
        $this->assertFalse($eligibility->allowed);
        $this->assertSame('last_known_good', $eligibility->reasonCode);

        try {
            (new BackupDeletionService())->delete($operation, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame($eligibility->reasonCode, $e->reasonCode);
        }
    }

    public function test_eligibility_and_delete_agree_a_manually_protected_backup_is_blocked(): void
    {
        $admin = $this->superAdmin();
        $operation = $this->makeCompletedOperation(['is_protected' => true]);

        $eligibility = (new BackupDeletionService())->eligibility($operation);
        $this->assertFalse($eligibility->allowed);
        $this->assertSame('protected', $eligibility->reasonCode);

        try {
            (new BackupDeletionService())->delete($operation, $admin);
            $this->fail('Expected BackupDeletionRejectedException.');
        } catch (BackupDeletionRejectedException $e) {
            $this->assertSame($eligibility->reasonCode, $e->reasonCode);
        }
    }

    public function test_eligibility_and_delete_agree_a_locked_backup_is_blocked(): void
    {
        $admin = $this->superAdmin();
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);
        $operation = $this->makeCompletedOperation(['verified_at' => null]);

        $externalLock = Cache::lock(BackupFileLock::name($operation->uuid), 60);
        $this->assertTrue($externalLock->get());

        try {
            $eligibility = (new BackupDeletionService())->eligibility($operation);
            $this->assertFalse($eligibility->allowed);
            $this->assertSame('locked', $eligibility->reasonCode);

            try {
                (new BackupDeletionService())->delete($operation, $admin);
                $this->fail('Expected BackupDeletionRejectedException.');
            } catch (BackupDeletionRejectedException $e) {
                $this->assertSame($eligibility->reasonCode, $e->reasonCode);
            }
        } finally {
            $externalLock->release();
        }
    }
}
