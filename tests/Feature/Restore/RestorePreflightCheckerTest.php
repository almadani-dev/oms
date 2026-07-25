<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\SecretstreamEnvelope;
use App\Services\Restore\Exceptions\RestoreInsufficientDiskSpaceException;
use App\Services\Restore\Exceptions\RestorePreflightException;
use App\Services\Restore\RestoreActivityGuard;
use App\Services\Restore\RestoreDiskSpaceEstimator;
use App\Services\Restore\RestorePreflightChecker;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Backup\FakeProcessRunner;
use Tests\Support\Restore\FakeCurrentDatabaseSizeEstimator;
use Tests\Support\Restore\FakeFilesystemIdentity;

/**
 * OMS Task 7C.3 — RestorePreflightChecker. Uses the real
 * BackupCreationOrchestrator (fake process runner, faked disks) to build
 * genuine completed+verified encrypted archives, per the task's "use actual
 * fixture archives built through the existing backup builder/encryption
 * code where practical" instruction.
 */
class RestorePreflightCheckerTest extends BackupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');

        config([
            'oms.backup.mysql_client_path' => $this->fakeMysqlClientPath(),
            'oms.backup.restore.free_space_margin_percent' => 20,
            'oms.backup.restore.min_free_space_reserve_bytes' => 1,
        ]);
    }

    private function fakeMysqlClientPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-fake-mysql-'.uniqid('', true);
        file_put_contents($path, '#!/bin/sh');
        @chmod($path, 0755);

        return $path;
    }

    private function checker(?FakeFilesystemIdentity $filesystemIdentity = null): RestorePreflightChecker
    {
        return new RestorePreflightChecker(
            new BackupKeyRing(),
            $this->app->make(SecretstreamEnvelope::class),
            new RestoreActivityGuard(),
            // Fixed, never a real MySQL/information_schema query — keeps
            // every preflight test deterministic and network-free.
            new RestoreDiskSpaceEstimator(new FakeCurrentDatabaseSizeEstimator(1000)),
            $filesystemIdentity ?? new FakeFilesystemIdentity(),
        );
    }

    private function createCompletedBackup(BackupScope $scope = BackupScope::Full): BackupOperation
    {
        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        $operation = $orchestrator->enqueue(BackupType::Manual, $scope, 'preflight test fixture', null);

        return $orchestrator->run($operation->id);
    }

    // ---- 1. source backup validity -----------------------------------------------------

    public function test_completed_and_verified_compatible_source_passes(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        $result = $this->checker()->check($backup->uuid, BackupScope::Full);

        $this->assertSame($backup->uuid, $result->sourceBackup->uuid);
        $this->assertSame(BackupScope::Full, $result->selectedScope);
        $this->assertGreaterThan(0, $result->estimatedRequiredBytes);
    }

    public function test_unknown_source_uuid_fails(): void
    {
        $this->expectException(RestorePreflightException::class);
        $this->checker()->check('00000000-0000-0000-0000-000000000000', BackupScope::Full);
    }

    public function test_soft_deleted_source_fails(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);
        $backup->delete();

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('source_soft_deleted', $e->reasonCode);
        }
    }

    public function test_incomplete_source_fails(): void
    {
        $backup = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
        ]);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('source_not_completed', $e->reasonCode);
        }
    }

    public function test_unverified_source_fails(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);
        $backup->forceFill(['verified_at' => null])->save();

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('source_not_verified', $e->reasonCode);
        }
    }

    public function test_missing_archive_file_fails(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);
        Storage::disk('backups')->delete((string) $backup->stored_path);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('source_archive_missing', $e->reasonCode);
        }
    }

    public function test_restore_type_source_fails(): void
    {
        $backup = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restored->value,
            'disk' => 'backups',
            'verified_at' => now(),
        ]);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('source_is_restore_operation', $e->reasonCode);
        }
    }

    // ---- scope compatibility -------------------------------------------------------------

    public function test_database_restore_from_a_database_only_source_passes(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Database);

        $result = $this->checker()->check($backup->uuid, BackupScope::Database);

        $this->assertSame(BackupScope::Database, $result->selectedScope);
    }

    public function test_files_restore_from_a_database_only_source_fails(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Database);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Files);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('scope_incompatible', $e->reasonCode);
        }
    }

    public function test_full_restore_from_a_files_only_source_fails(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Files);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('scope_incompatible', $e->reasonCode);
        }
    }

    public function test_database_restore_from_a_full_source_passes(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        $result = $this->checker()->check($backup->uuid, BackupScope::Database);

        $this->assertSame(BackupScope::Database, $result->selectedScope);
    }

    // ---- 2. encryption / key resolution ---------------------------------------------------

    public function test_configured_previous_key_still_resolves(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        // Rotate: the key used to create this backup becomes "previous".
        $oldKeyId = (string) config('oms.backup.encryption.key_id');
        $oldKey = (string) config('oms.backup.encryption.key');

        config([
            'oms.backup.encryption.key_id' => 'test-key-2',
            'oms.backup.encryption.key' => base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'oms.backup.encryption.previous_keys' => [$oldKeyId => $oldKey],
        ]);

        $result = $this->checker()->check($backup->uuid, BackupScope::Full);

        $this->assertSame($backup->uuid, $result->sourceBackup->uuid);
    }

    public function test_unknown_key_id_fails_closed(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        // Rotate away with no previous_keys entry for the original key.
        config([
            'oms.backup.encryption.key_id' => 'test-key-2',
            'oms.backup.encryption.key' => base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'oms.backup.encryption.previous_keys' => [],
        ]);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('encryption_key_unavailable', $e->reasonCode);
        }
    }

    public function test_malformed_archive_header_fails_closed(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);
        $disk = Storage::disk('backups');
        $disk->put((string) $backup->stored_path, 'not-a-real-envelope-header');

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('archive_header_invalid', $e->reasonCode);
        }
    }

    // ---- 3. database requirements ----------------------------------------------------------

    public function test_mysql_client_not_required_for_files_only_scope(): void
    {
        config(['oms.backup.mysql_client_path' => '']);
        $backup = $this->createCompletedBackup(BackupScope::Files);

        $result = $this->checker()->check($backup->uuid, BackupScope::Files);

        $this->assertSame(BackupScope::Files, $result->selectedScope);
    }

    public function test_missing_mysql_client_fails_for_database_scope(): void
    {
        config(['oms.backup.mysql_client_path' => sys_get_temp_dir().'/oms-does-not-exist-'.uniqid('', true)]);
        $backup = $this->createCompletedBackup(BackupScope::Database);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Database);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('mysql_client_missing', $e->reasonCode);
        }
    }

    public function test_incomplete_database_connection_configuration_fails(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Database);
        config(['database.connections.mysql_backup_test.database' => '']);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Database);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('database_connection_incomplete', $e->reasonCode);
        }
    }

    // ---- 4. filesystem compatibility -------------------------------------------------------

    public function test_same_filesystem_passes(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Files);

        $result = $this->checker(new FakeFilesystemIdentity())->check($backup->uuid, BackupScope::Files);

        $this->assertSame(BackupScope::Files, $result->selectedScope);
    }

    public function test_cross_filesystem_fails(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Files);

        $restoreRoot = Storage::disk('restores')->path('');
        $attachmentsParent = dirname(rtrim(Storage::disk('attachments')->path(''), '/\\'));

        $fake = new FakeFilesystemIdentity([
            $restoreRoot => 'fs-a',
            $attachmentsParent => 'fs-b',
        ]);

        try {
            $this->checker($fake)->check($backup->uuid, BackupScope::Files);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('filesystem_incompatible', $e->reasonCode);
        }
    }

    // ---- 5. restore activity ----------------------------------------------------------------

    public function test_active_restore_row_blocks(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
        ]);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('restore_already_active', $e->reasonCode);
        }
    }

    public function test_tampered_progress_state_blocks_as_requiring_review(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        $uuid = 'aaaaaaaa-0000-0000-0000-000000000099';
        (new RestoreProgressWriter())->write(RestoreProgressSnapshot::create(
            $uuid,
            ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            '2026-07-23T10:00:00+00:00',
            'test',
            'full',
            $backup->uuid,
            null,
            'validating',
            [],
            '2026-07-23T10:00:05+00:00',
            null,
            null,
            null,
        ));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('restore_state_requires_review', $e->reasonCode);
        }
    }

    public function test_excluding_the_current_restore_uuid_does_not_block_on_its_own_active_progress_file(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        $currentUuid = 'aaaaaaaa-0000-0000-0000-000000000098';
        (new RestoreProgressWriter())->write(RestoreProgressSnapshot::create(
            $currentUuid,
            ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            '2026-07-23T10:00:00+00:00',
            'test',
            'full',
            $backup->uuid,
            null,
            'preflight',
            [],
            '2026-07-23T10:00:05+00:00',
            null,
            null,
            null,
        ));

        $result = $this->checker()->check($backup->uuid, BackupScope::Full, $currentUuid);

        $this->assertSame(BackupScope::Full, $result->selectedScope);
    }

    public function test_excluding_the_current_restore_uuid_still_blocks_on_a_different_active_restore(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
        ]);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full, 'aaaaaaaa-0000-0000-0000-000000000097');
            $this->fail('Expected RestorePreflightException.');
        } catch (RestorePreflightException $e) {
            $this->assertSame('restore_already_active', $e->reasonCode);
        }
    }

    // ---- 6. disk space, before workspace creation --------------------------------------------

    public function test_insufficient_disk_space_fails_and_no_workspace_directory_is_created(): void
    {
        config(['oms.backup.restore.min_free_space_reserve_bytes' => PHP_INT_MAX]);

        $backup = $this->createCompletedBackup(BackupScope::Full);

        try {
            $this->checker()->check($backup->uuid, BackupScope::Full);
            $this->fail('Expected RestoreInsufficientDiskSpaceException.');
        } catch (RestoreInsufficientDiskSpaceException $e) {
            $this->assertGreaterThan($e->availableBytes, $e->requiredBytes);
        }

        // '.locks' is created as a side effect of BackupSubsystemLock
        // during backup-fixture creation above — unrelated to this
        // preflight check. No restore-UUID workspace directory exists.
        $uuidDirectories = array_filter(
            Storage::disk('restores')->directories(),
            static fn (string $directory): bool => $directory !== '.locks',
        );
        $this->assertSame([], array_values($uuidDirectories));
    }
}
