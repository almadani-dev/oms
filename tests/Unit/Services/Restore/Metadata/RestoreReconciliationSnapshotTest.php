<?php

namespace Tests\Unit\Services\Restore\Metadata;

use App\Enums\BackupScope;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\Metadata\RestoreReconciliationSnapshot;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * OMS Task 7C.5 correction pass — RestoreReconciliationSnapshot: bundles the
 * three metadata snapshots for embedding inside a signed
 * RestoreProgressSnapshot, round-trips through toArray()/fromArray(), and
 * never accepts an arbitrary/malformed nested array.
 */
class RestoreReconciliationSnapshotTest extends TestCase
{
    public function test_to_array_and_from_array_round_trip(): void
    {
        $bundle = $this->buildBundle();
        $rebuilt = RestoreReconciliationSnapshot::fromArray($bundle->toArray());

        $this->assertEquals($bundle, $rebuilt);
    }

    public function test_from_array_rejects_a_missing_top_level_key(): void
    {
        $array = $this->buildBundle()->toArray();
        unset($array['safety_backup']);

        $this->expectException(InvalidArgumentException::class);
        RestoreReconciliationSnapshot::fromArray($array);
    }

    public function test_from_array_rejects_an_unexpected_extra_top_level_key(): void
    {
        $array = $this->buildBundle()->toArray();
        $array['unexpected'] = ['sneaky' => true];

        $this->expectException(InvalidArgumentException::class);
        RestoreReconciliationSnapshot::fromArray($array);
    }

    public function test_from_array_rejects_a_non_array_nested_value(): void
    {
        $array = $this->buildBundle()->toArray();
        $array['source_backup'] = 'not-an-array';

        $this->expectException(InvalidArgumentException::class);
        RestoreReconciliationSnapshot::fromArray($array);
    }

    public function test_tampering_with_a_nested_field_fails_nested_validation(): void
    {
        $array = $this->buildBundle()->toArray();
        $array['source_backup']['uuid'] = 'not-a-uuid';

        $this->expectException(InvalidArgumentException::class);
        RestoreReconciliationSnapshot::fromArray($array);
    }

    private function buildBundle(): RestoreReconciliationSnapshot
    {
        $now = now()->format(\DATE_ATOM);
        $identity = ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'];

        $source = BackupOperationSnapshot::create(
            uuid: 'aaaaaaaa-1111-1111-1111-111111111111',
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
            uuid: 'bbbbbbbb-2222-2222-2222-222222222222',
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
            restoreUuid: 'cccccccc-3333-3333-3333-333333333333',
            sourceUuid: 'aaaaaaaa-1111-1111-1111-111111111111',
            safetyUuid: 'bbbbbbbb-2222-2222-2222-222222222222',
            scope: BackupScope::Full->value,
            requestedBy: $identity,
            reason: 'testing',
            confirmedAt: $now,
            startedAt: $now,
            phaseHistory: [['phase' => 'database_restored', 'at' => $now]],
            resultContext: null,
        );

        return RestoreReconciliationSnapshot::create($source, $safety, $restore);
    }
}
