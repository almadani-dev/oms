<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupFileLock;
use App\Services\Backup\BackupRetentionService;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\Exceptions\BackupLockedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Covers the OMS Task 7B.1 "RETENTION" test category (items 49-58).
 * BackupOperation rows are created directly (not via the full orchestrator
 * pipeline, which is already covered by BackupCreationOrchestratorTest) —
 * only the metadata + a placeholder file on Storage::fake('backups')
 * matters for retention's own logic.
 */
class BackupRetentionServiceTest extends BackupTestCase
{
    private function makeOperation(array $attrs = []): BackupOperation
    {
        $disk = $attrs['disk'] ?? 'backups';
        $path = $attrs['stored_path'] ?? ('backup-'.Str::uuid()->toString().'.omsbak.enc');

        return BackupOperation::create(array_merge([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => $disk,
            'stored_path' => $path,
            'encrypted_filename' => basename($path),
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'completed_at' => now(),
            'is_protected' => false,
        ], $attrs));
    }

    private function putPlaceholderFile(string $disk, string $path): void
    {
        Storage::disk($disk)->put($path, 'fake-encrypted-content');
    }

    // ---- 49. latest 7 daily kept -------------------------------------------------

    public function test_latest_seven_daily_backups_are_kept_older_ones_deleted(): void
    {
        $operations = [];

        for ($i = 0; $i < 10; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $remainingIds = BackupOperation::query()->where('type', BackupType::Daily->value)->pluck('id')->all();
        $this->assertCount(7, $remainingIds);

        foreach (array_slice($operations, 0, 7) as $operation) {
            $this->assertContains($operation->id, $remainingIds);
        }

        foreach (array_slice($operations, 7) as $operation) {
            $this->assertNotContains($operation->id, $remainingIds);
            $this->assertSoftDeleted('backup_operations', ['id' => $operation->id]);
        }
    }

    // ---- 50. latest 4 weekly kept --------------------------------------------------

    public function test_latest_four_weekly_backups_are_kept_older_ones_deleted(): void
    {
        $operations = [];

        for ($i = 0; $i < 6; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::Weekly->value, 'completed_at' => now()->subWeeks($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $remainingIds = BackupOperation::query()->where('type', BackupType::Weekly->value)->pluck('id')->all();
        $this->assertCount(4, $remainingIds);

        foreach (array_slice($operations, 4) as $operation) {
            $this->assertSoftDeleted('backup_operations', ['id' => $operation->id]);
        }
    }

    // ---- 51. manual kept -----------------------------------------------------------

    public function test_manual_backups_are_never_auto_deleted(): void
    {
        $operations = [];

        for ($i = 0; $i < 15; $i++) {
            $operation = $this->makeOperation([
                'type' => BackupType::Manual->value,
                'completed_at' => now()->subDays($i * 10),
                'is_protected' => false,
            ]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $this->assertSame(15, BackupOperation::query()->where('type', BackupType::Manual->value)->count());
    }

    // ---- 52. protected kept --------------------------------------------------------

    public function test_protected_backup_is_kept_beyond_its_type_window(): void
    {
        $operations = [];

        for ($i = 0; $i < 9; $i++) {
            $isOldest = $i === 8;
            $operation = $this->makeOperation([
                'type' => BackupType::Daily->value,
                'completed_at' => now()->subDays($i),
                'is_protected' => $isOldest,
            ]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $oldest = $operations[8];
        $this->assertNotSoftDeleted('backup_operations', ['id' => $oldest->id]);
    }

    // ---- 53. latest 3 pre_restore kept -----------------------------------------------

    public function test_latest_three_pre_restore_backups_are_kept(): void
    {
        $operations = [];

        for ($i = 0; $i < 5; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::PreRestore->value, 'completed_at' => now()->subHours($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $remainingIds = BackupOperation::query()->where('type', BackupType::PreRestore->value)->pluck('id')->all();
        $this->assertCount(3, $remainingIds);
    }

    // ---- 54. referenced pre_restore kept ----------------------------------------------

    public function test_referenced_pre_restore_backup_is_kept_even_outside_its_window(): void
    {
        $operations = [];

        for ($i = 0; $i < 5; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::PreRestore->value, 'completed_at' => now()->subHours($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        $oldest = $operations[4];

        // Simulates a restore row that used this old pre_restore backup as
        // its safety net.
        $restoreReferencer = $this->makeOperation([
            'type' => BackupType::Manual->value,
            'status' => BackupStatus::Restored->value,
            'pre_restore_safety_backup_id' => $oldest->id,
        ]);
        $this->putPlaceholderFile('backups', $restoreReferencer->stored_path);

        (new BackupRetentionService())->run();

        $this->assertNotSoftDeleted('backup_operations', ['id' => $oldest->id]);
    }

    // ---- 55. last known-good verified backup kept ---------------------------------------

    public function test_last_known_good_verified_backup_is_kept_even_outside_its_window(): void
    {
        $operations = [];

        for ($i = 0; $i < 10; $i++) {
            $isOldest = $i === 9;
            $operation = $this->makeOperation([
                'type' => BackupType::Daily->value,
                'completed_at' => now()->subDays($i),
                'verified_at' => $isOldest ? now() : null,
            ]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $oldest = $operations[9];
        $this->assertNotSoftDeleted('backup_operations', ['id' => $oldest->id]);

        $remainingIds = BackupOperation::query()->where('type', BackupType::Daily->value)->pluck('id')->all();
        $this->assertCount(8, $remainingIds); // 7 window + the protected last-known-good
    }

    // ---- 56. active operations are not deleted --------------------------------------------

    public function test_active_operations_are_never_deleted(): void
    {
        $operation = $this->makeOperation([
            'type' => BackupType::Daily->value,
            'status' => BackupStatus::Running->value,
            'completed_at' => null,
        ]);
        $this->putPlaceholderFile('backups', $operation->stored_path);

        // Fill the window with 7 newer completed backups so the running one
        // would be a deletion candidate by age alone if it were completed.
        for ($i = 0; $i < 7; $i++) {
            $extra = $this->makeOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays($i)]);
            $this->putPlaceholderFile('backups', $extra->stored_path);
        }

        (new BackupRetentionService())->run();

        $this->assertNotSoftDeleted('backup_operations', ['id' => $operation->id]);
    }

    // ---- 57. deletion uses only safe approved disk paths ------------------------------------

    public function test_file_on_a_non_approved_disk_is_never_touched(): void
    {
        Storage::fake('public');

        $operations = [];

        for ($i = 0; $i < 9; $i++) {
            $disk = $i === 8 ? 'public' : 'backups';
            $operation = $this->makeOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays($i), 'disk' => $disk]);
            $this->putPlaceholderFile($disk, $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $mismatchedDiskOperation = $operations[8];
        $this->assertSoftDeleted('backup_operations', ['id' => $mismatchedDiskOperation->id]);
        $this->assertTrue(Storage::disk('public')->exists($mismatchedDiskOperation->stored_path), 'A file on a non-approved disk must never be deleted by retention.');
    }

    public function test_unsafe_stored_path_is_never_passed_to_the_filesystem(): void
    {
        $operations = [];

        for ($i = 0; $i < 8; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        $unsafe = $this->makeOperation([
            'type' => BackupType::Daily->value,
            'completed_at' => now()->subDays(20),
            'stored_path' => '../../etc/passwd',
        ]);
        $operations[] = $unsafe;

        // No file is placed for the unsafe path (a real path traversal
        // target does not exist on the fake disk either); the assertion is
        // that retention does not error out trying to touch it and the row
        // is still processed (soft-deleted) safely.
        (new BackupRetentionService())->run();

        $this->assertSoftDeleted('backup_operations', ['id' => $unsafe->id]);
    }

    // ---- 58. metadata is soft-deleted --------------------------------------------------------

    public function test_deleted_operation_metadata_remains_queryable_via_with_trashed(): void
    {
        $operations = [];

        for ($i = 0; $i < 9; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        (new BackupRetentionService())->run();

        $deleted = $operations[8];
        $this->assertNull(BackupOperation::find($deleted->id));
        $this->assertNotNull(BackupOperation::withTrashed()->find($deleted->id));
        $this->assertSame(BackupStatus::Deleted, BackupOperation::withTrashed()->find($deleted->id)->status);
    }

    // ---- correction 3: download/retention race — per-backup file lock -----------------------

    public function test_retention_skips_a_backup_whose_per_backup_lock_is_held_externally(): void
    {
        $operations = [];

        for ($i = 0; $i < 9; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        // Index 8 is the oldest — outside the 7-window and would normally
        // be deleted this run.
        $target = $operations[8];
        $externalLock = Cache::lock(BackupFileLock::name($target->uuid), 60);
        $this->assertTrue($externalLock->get(), 'Test setup: expected to acquire the per-backup lock.');

        try {
            $report = (new BackupRetentionService())->run();

            $this->assertContains($target->id, $report->inUseOperationIds);
            $this->assertNotContains($target->id, $report->deletedOperationIds);
            $this->assertNotSoftDeleted('backup_operations', ['id' => $target->id]);
            $this->assertTrue(Storage::disk('backups')->exists($target->stored_path), 'No file may be deleted while its per-backup lock is held.');
        } finally {
            $externalLock->release();
        }
    }

    public function test_retention_deletes_the_backup_once_its_lock_becomes_available_again(): void
    {
        $operations = [];

        for ($i = 0; $i < 9; $i++) {
            $operation = $this->makeOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays($i)]);
            $this->putPlaceholderFile('backups', $operation->stored_path);
            $operations[] = $operation;
        }

        $target = $operations[8];

        // Not held this time — sanity check that the SAME row a locked run
        // would have skipped is normally deletable once the lock is free.
        (new BackupRetentionService())->run();

        $this->assertSoftDeleted('backup_operations', ['id' => $target->id]);
        $this->assertFalse(Storage::disk('backups')->exists($target->stored_path));
    }

    // ---- OMS Task 7C.2: shared subsystem lock ------------------------------------------------

    public function test_run_is_blocked_while_the_exclusive_subsystem_lock_is_held(): void
    {
        $exclusive = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($exclusive, 'Test setup: expected to acquire the exclusive subsystem lock.');

        $operation = $this->makeOperation(['type' => BackupType::Daily->value]);
        $this->putPlaceholderFile('backups', $operation->stored_path);

        try {
            (new BackupRetentionService())->run();
            $this->fail('Expected BackupLockedException.');
        } catch (BackupLockedException) {
            // expected
        } finally {
            $exclusive->release();
        }

        $this->assertNotSoftDeleted('backup_operations', ['id' => $operation->id]);
    }
}
