<?php

namespace Tests\Unit\Services\Restore\Metadata;

use App\Enums\BackupScope;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * OMS Task 7C.5 — BackupOperationSnapshot: create() is the only entry point
 * and validates every field; nothing here can be built from an arbitrary,
 * unvalidated array.
 */
class BackupOperationSnapshotTest extends TestCase
{
    private function validArgs(): array
    {
        $now = now()->format(\DATE_ATOM);

        return [
            'uuid' => 'aaaaaaaa-1111-1111-1111-111111111111',
            'type' => 'manual',
            'scope' => BackupScope::Full->value,
            'disk' => 'backups',
            'archivePath' => 'source.enc',
            'archiveFilename' => 'source.enc',
            'sizeBytes' => 100,
            'checksumSha256' => str_repeat('a', 64),
            'encryptionKeyId' => 'key-1',
            'manifestVersion' => 1,
            'fileCount' => 2,
            'originalSizeBytes' => 200,
            'createdAt' => $now,
            'startedAt' => $now,
            'completedAt' => $now,
            'verifiedAt' => $now,
            'createdBy' => ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com'],
            'isProtected' => false,
            'operationReason' => null,
        ];
    }

    public function test_valid_snapshot_is_created(): void
    {
        $snapshot = BackupOperationSnapshot::create(...$this->validArgs());

        $this->assertSame('aaaaaaaa-1111-1111-1111-111111111111', $snapshot->uuid);
        $this->assertSame('manual', $snapshot->type);
        $this->assertSame(1, $snapshot->createdBy['user_id']);
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['uuid'] = 'not-a-uuid';

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_restore_type_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['type'] = 'restore';

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['scope'] = 'not-a-scope';

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_unsafe_archive_path_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['archivePath'] = '../escape.enc';

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_malformed_checksum_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['checksumSha256'] = 'not-a-checksum';

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_negative_size_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['sizeBytes'] = -1;

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_invalid_timestamp_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['completedAt'] = 'not-a-timestamp';

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_oversized_disk_name_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['disk'] = str_repeat('d', BackupOperationSnapshot::MAX_DISK_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_identity_missing_required_keys_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['createdBy'] = ['name' => 'Admin', 'email' => 'admin@example.com'];

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_oversized_operation_reason_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['operationReason'] = str_repeat('x', BackupOperationSnapshot::MAX_STRING_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_out_of_range_manifest_version_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['manifestVersion'] = BackupOperationSnapshot::MAX_MANIFEST_VERSION + 1;

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::create(...$args);
    }

    public function test_to_array_and_from_array_round_trip(): void
    {
        $snapshot = BackupOperationSnapshot::create(...$this->validArgs());
        $rebuilt = BackupOperationSnapshot::fromArray($snapshot->toArray());

        $this->assertEquals($snapshot, $rebuilt);
    }

    public function test_from_array_rejects_a_missing_key(): void
    {
        $array = BackupOperationSnapshot::create(...$this->validArgs())->toArray();
        unset($array['checksum_sha256']);

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::fromArray($array);
    }

    public function test_from_array_rejects_an_unexpected_extra_key(): void
    {
        $array = BackupOperationSnapshot::create(...$this->validArgs())->toArray();
        $array['unexpected_extra_field'] = 'sneaky';

        $this->expectException(InvalidArgumentException::class);
        BackupOperationSnapshot::fromArray($array);
    }
}
