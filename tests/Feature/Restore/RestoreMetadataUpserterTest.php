<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Restore\Exceptions\RestoreMetadataReconciliationException;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\RestoreMetadataUpserter;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.5 — RestoreMetadataUpserter: source/safety/restore upsert
 * order, forced-authoritative status correction, UUID-based FK resolution
 * (never a trusted old numeric ID), and created_by resolution against the
 * freshly-migrated users table. Runs against the real migrated SQLite
 * :memory: connection BackupTestCase sets up — no MySQL is involved.
 */
class RestoreMetadataUpserterTest extends BackupTestCase
{
    private const SOURCE_UUID = 'aaaaaaaa-1111-1111-1111-111111111111';

    private const SAFETY_UUID = 'bbbbbbbb-2222-2222-2222-222222222222';

    private const RESTORE_UUID = 'cccccccc-3333-3333-3333-333333333333';

    public function test_stale_source_status_is_corrected_to_completed(): void
    {
        BackupOperation::create([
            'uuid' => self::SOURCE_UUID,
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Verifying->value,
            'disk' => 'backups',
            'stored_path' => 'stale.enc',
        ]);

        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots());

        $source = BackupOperation::query()->where('uuid', self::SOURCE_UUID)->first();

        $this->assertSame(BackupStatus::Completed, $source->status);
        $this->assertNotNull($source->verified_at);
        $this->assertSame('source.enc', $source->stored_path);
    }

    public function test_safety_row_is_inserted_when_absent(): void
    {
        // Only the source exists beforehand — the safety backup genuinely
        // does not exist in the restored dump, exactly like a real restore.
        BackupOperation::create([
            'uuid' => self::SOURCE_UUID,
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => 'source.enc',
            'verified_at' => now(),
        ]);

        $this->assertDatabaseMissing('backup_operations', ['uuid' => self::SAFETY_UUID]);

        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots());

        $safety = BackupOperation::query()->where('uuid', self::SAFETY_UUID)->first();

        $this->assertNotNull($safety);
        $this->assertSame(BackupStatus::Completed, $safety->status);
        $this->assertNotNull($safety->verified_at, 'The safety backup row must be forced authoritative Completed AND verified, exactly like the source backup.');
        $this->assertTrue($safety->is_protected);
    }

    public function test_restore_row_resolves_fks_by_uuid_and_stays_restoring(): void
    {
        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots());

        $source = BackupOperation::query()->where('uuid', self::SOURCE_UUID)->firstOrFail();
        $safety = BackupOperation::query()->where('uuid', self::SAFETY_UUID)->firstOrFail();
        $restore = BackupOperation::query()->where('uuid', self::RESTORE_UUID)->firstOrFail();

        $this->assertSame(BackupType::Restore, $restore->type);
        $this->assertSame(BackupStatus::Restoring, $restore->status);
        $this->assertSame($source->id, $restore->source_backup_id);
        $this->assertSame($safety->id, $restore->pre_restore_safety_backup_id);
        $this->assertNull($restore->launch_nonce);

        // No dangling FK: both referenced IDs must resolve to real,
        // currently-existing rows — not merely equal numbers.
        $this->assertNotNull(BackupOperation::find($restore->source_backup_id));
        $this->assertNotNull(BackupOperation::find($restore->pre_restore_safety_backup_id));
    }

    public function test_manifest_version_is_restored(): void
    {
        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots());

        $source = BackupOperation::query()->where('uuid', self::SOURCE_UUID)->firstOrFail();

        $this->assertSame(1, $source->manifest_version);
    }

    public function test_deleted_at_is_cleared_for_a_previously_trashed_source_row(): void
    {
        $source = BackupOperation::create([
            'uuid' => self::SOURCE_UUID,
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => 'stale.enc',
            'verified_at' => now(),
        ]);
        $source->delete();

        $this->assertSoftDeleted('backup_operations', ['uuid' => self::SOURCE_UUID]);

        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots());

        $reconstructed = BackupOperation::query()->where('uuid', self::SOURCE_UUID)->firstOrFail();

        $this->assertNull($reconstructed->deleted_at, 'A reconstructed source backup row must never remain soft-deleted.');
    }

    public function test_deleted_at_is_cleared_for_a_previously_trashed_restore_row(): void
    {
        $restore = BackupOperation::create([
            'uuid' => self::RESTORE_UUID,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::RestoreFailed->value,
            'disk' => 'restores',
        ]);
        $restore->delete();

        $this->assertSoftDeleted('backup_operations', ['uuid' => self::RESTORE_UUID]);

        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots());

        $reconstructed = BackupOperation::query()->where('uuid', self::RESTORE_UUID)->firstOrFail();

        $this->assertNull($reconstructed->deleted_at, 'A reconstructed restore row must never remain soft-deleted.');
        $this->assertSame(BackupStatus::Restoring, $reconstructed->status);
    }

    public function test_present_user_id_is_preserved_on_created_by(): void
    {
        $user = User::create([
            'name' => 'Real Admin',
            'email' => 'real-admin@example.com',
            'password' => 'irrelevant-hash',
        ]);

        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots($user->id));

        $source = BackupOperation::query()->where('uuid', self::SOURCE_UUID)->firstOrFail();
        $restore = BackupOperation::query()->where('uuid', self::RESTORE_UUID)->firstOrFail();

        $this->assertSame($user->id, $source->created_by);
        $this->assertSame($user->id, $restore->created_by);
        $this->assertNull($source->restore_metadata);
    }

    public function test_absent_user_id_results_in_null_created_by_with_identity_preserved(): void
    {
        // 999999 deliberately does not exist in the freshly-migrated users
        // table — must never become a dangling foreign key.
        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots(999999));

        $source = BackupOperation::query()->where('uuid', self::SOURCE_UUID)->firstOrFail();
        $restore = BackupOperation::query()->where('uuid', self::RESTORE_UUID)->firstOrFail();

        $this->assertNull($source->created_by);
        $this->assertSame('Admin', $source->restore_metadata['creator_identity']['name']);
        $this->assertSame('admin@example.com', $source->restore_metadata['creator_identity']['email']);

        $this->assertNull($restore->created_by);
        $this->assertSame('Admin', $restore->restore_metadata['creator_identity']['name']);
    }

    public function test_no_confirmation_or_secret_fields_are_ever_persisted(): void
    {
        (new RestoreMetadataUpserter())->reconstruct(...$this->snapshots());

        $restore = BackupOperation::query()->where('uuid', self::RESTORE_UUID)->firstOrFail();
        $flat = json_encode($restore->restore_metadata);

        foreach (['confirmation_phrase', 'password', 'encryption_key', 'nonce', 'db_password'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $flat);
        }
    }

    public function test_source_upsert_failure_prevents_safety_and_restore_upserts(): void
    {
        [$source, $safety, $restore] = $this->snapshots();

        // Force a genuine DB-level failure on the very first write (the
        // source upsert) by dropping the table it writes to.
        Schema::drop('backup_operations');

        try {
            (new RestoreMetadataUpserter())->reconstruct($source, $safety, $restore);
            $this->fail('Expected RestoreMetadataReconciliationException.');
        } catch (RestoreMetadataReconciliationException $e) {
            $this->assertSame('source_upsert_failed', $e->reasonCode);
        }
    }

    public function test_safety_upsert_failure_prevents_restore_upsert(): void
    {
        $saveCount = 0;

        BackupOperation::saving(function () use (&$saveCount): void {
            $saveCount++;

            // The 1st save() is the source upsert (must succeed); force the
            // 2nd (the safety upsert) to fail.
            if ($saveCount === 2) {
                throw new \RuntimeException('simulated safety upsert failure');
            }
        });

        [$source, $safety, $restore] = $this->snapshots();

        try {
            (new RestoreMetadataUpserter())->reconstruct($source, $safety, $restore);
            $this->fail('Expected RestoreMetadataReconciliationException.');
        } catch (RestoreMetadataReconciliationException $e) {
            $this->assertSame('safety_upsert_failed', $e->reasonCode);
        }

        $this->assertDatabaseHas('backup_operations', ['uuid' => self::SOURCE_UUID]);
        $this->assertDatabaseMissing('backup_operations', ['uuid' => self::RESTORE_UUID]);
    }

    /**
     * @return array{0: BackupOperationSnapshot, 1: BackupOperationSnapshot, 2: RestoreOperationSnapshot}
     */
    private function snapshots(?int $userId = null): array
    {
        $now = now()->format(\DATE_ATOM);
        $identity = ['user_id' => $userId, 'name' => 'Admin', 'email' => 'admin@example.com'];

        $source = BackupOperationSnapshot::create(
            uuid: self::SOURCE_UUID,
            type: 'manual',
            scope: BackupScope::Full->value,
            disk: 'backups',
            archivePath: 'source.enc',
            archiveFilename: 'source.enc',
            sizeBytes: 100,
            checksumSha256: str_repeat('a', 64),
            encryptionKeyId: 'key-1',
            manifestVersion: 1,
            fileCount: 2,
            originalSizeBytes: 200,
            createdAt: $now,
            startedAt: $now,
            completedAt: $now,
            verifiedAt: $now,
            createdBy: $identity,
            isProtected: false,
            operationReason: null,
        );

        $safety = BackupOperationSnapshot::create(
            uuid: self::SAFETY_UUID,
            type: 'pre_restore',
            scope: BackupScope::Full->value,
            disk: 'backups',
            archivePath: 'safety.enc',
            archiveFilename: 'safety.enc',
            sizeBytes: 100,
            checksumSha256: str_repeat('b', 64),
            encryptionKeyId: 'key-1',
            manifestVersion: 1,
            fileCount: 2,
            originalSizeBytes: 200,
            createdAt: $now,
            startedAt: $now,
            completedAt: $now,
            verifiedAt: $now,
            createdBy: $identity,
            isProtected: true,
            operationReason: 'pre-restore safety backup',
        );

        $restore = RestoreOperationSnapshot::create(
            restoreUuid: self::RESTORE_UUID,
            sourceUuid: self::SOURCE_UUID,
            safetyUuid: self::SAFETY_UUID,
            scope: BackupScope::Full->value,
            requestedBy: $identity,
            reason: 'testing',
            confirmedAt: $now,
            startedAt: $now,
            phaseHistory: [['phase' => 'database_restored', 'at' => $now]],
            resultContext: null,
        );

        return [$source, $safety, $restore];
    }
}
