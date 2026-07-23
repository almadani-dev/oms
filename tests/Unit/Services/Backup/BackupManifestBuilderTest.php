<?php

namespace Tests\Unit\Services\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupType;
use App\Services\Backup\AttachmentCollectionResult;
use App\Services\Backup\BackupManifestBuilder;
use App\Services\Backup\DumpResult;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Covers the manifest portion of the OMS Task 7B.1 "ARCHIVE / MANIFEST"
 * test category (items 26, 27).
 */
class BackupManifestBuilderTest extends TestCase
{
    public function test_manifest_contains_no_credentials_or_absolute_paths(): void
    {
        config([
            'database.default' => 'mysql_manifest_test',
            'database.connections.mysql_manifest_test' => [
                'driver' => 'mysql',
                'host' => 'db.internal',
                'port' => '3306',
                'database' => 'oms_prod',
                'username' => 'oms_user',
                'password' => 'top-secret-value',
            ],
        ]);

        $dump = new DumpResult('/var/www/oms/storage/app/private/backups/.work/x/dump.sql', 1234, str_repeat('a', 64));
        $attachments = new AttachmentCollectionResult(
            files: [['path' => 'receipts/1.jpg', 'sha256' => str_repeat('b', 64), 'size' => 10]],
            fileCount: 1,
            totalSizeBytes: 10,
        );

        $manifest = (new BackupManifestBuilder())->build(
            uuid: 'test-uuid',
            type: BackupType::Manual,
            scope: BackupScope::Full,
            createdAt: CarbonImmutable::now('UTC'),
            encryptionKeyId: 'key-1',
            envelopeVersion: 1,
            dump: $dump,
            attachments: $attachments,
        );

        $encoded = json_encode($manifest);

        $this->assertStringNotContainsString('top-secret-value', $encoded);
        $this->assertStringNotContainsString('oms_user', $encoded);
        $this->assertStringNotContainsString('db.internal', $encoded);
        $this->assertStringNotContainsString('/var/www/oms', $encoded);
        $this->assertStringNotContainsString('C:\\', $encoded);

        $this->assertSame('oms_prod', $manifest['database_identifier']);
        $this->assertSame('test-uuid', $manifest['backup_uuid']);
        $this->assertSame('manual', $manifest['backup_type']);
        $this->assertSame('full', $manifest['backup_scope']);
        $this->assertSame('key-1', $manifest['encryption']['key_id']);
        $this->assertSame('xchacha20poly1305-secretstream', $manifest['encryption']['algorithm']);
    }

    public function test_manifest_includes_correct_dump_hash(): void
    {
        $dump = new DumpResult('/tmp/dump.sql', 999, 'deadbeef'.str_repeat('0', 56));

        $manifest = (new BackupManifestBuilder())->build(
            uuid: 'u2',
            type: BackupType::Daily,
            scope: BackupScope::Database,
            createdAt: CarbonImmutable::now('UTC'),
            encryptionKeyId: 'key-1',
            envelopeVersion: 1,
            dump: $dump,
            attachments: null,
        );

        $this->assertSame('deadbeef'.str_repeat('0', 56), $manifest['dump']['sha256']);
        $this->assertSame(999, $manifest['dump']['size_bytes']);
        $this->assertNull($manifest['attachments']);
        $this->assertSame(['database/dump.sql'], $manifest['included_paths']);
    }

    public function test_manifest_omits_dump_and_attachments_when_not_provided(): void
    {
        $manifest = (new BackupManifestBuilder())->build(
            uuid: 'u3',
            type: BackupType::Weekly,
            scope: BackupScope::Files,
            createdAt: CarbonImmutable::now('UTC'),
            encryptionKeyId: 'key-1',
            envelopeVersion: 1,
            dump: null,
            attachments: new AttachmentCollectionResult([], 0, 0),
        );

        $this->assertNull($manifest['dump']);
        $this->assertSame(['files/attachments'], $manifest['included_paths']);
        $this->assertNull($manifest['database_identifier']);
    }

    public function test_manifest_does_not_contain_its_own_archive_hash(): void
    {
        $manifest = (new BackupManifestBuilder())->build(
            uuid: 'u4',
            type: BackupType::Manual,
            scope: BackupScope::Database,
            createdAt: CarbonImmutable::now('UTC'),
            encryptionKeyId: 'key-1',
            envelopeVersion: 1,
            dump: new DumpResult('/tmp/d.sql', 1, str_repeat('c', 64)),
            attachments: null,
        );

        $this->assertArrayNotHasKey('archive_sha256', $manifest);
        $this->assertArrayNotHasKey('checksum_sha256', $manifest);
    }
}
