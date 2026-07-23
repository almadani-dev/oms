<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupArchiveContentVerifier;
use App\Services\Backup\BackupManifestBuilder;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use Tests\TestCase;
use ZipArchive;

/**
 * Direct, deterministic coverage of the shared content-verification rules
 * (OMS Task 7B.1 correction 1) — the same implementation
 * BackupCreationOrchestrator now calls before ever publishing a candidate
 * archive, and BackupIntegrityVerifier calls to re-check an already-
 * published one. Every ZIP here is hand-built with a specific, known
 * defect; no encryption, no orchestrator, no database involved.
 */
class BackupArchiveContentVerifierTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-content-verifier-test-'.uniqid('', true);
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    public function test_valid_full_archive_verifies_and_returns_the_manifest(): void
    {
        $dumpContent = 'SELECT 1;';
        $fileContent = 'attachment-bytes';

        $manifest = $this->manifest('uuid-1', 'manual', 'full',
            ['sha256' => hash('sha256', $dumpContent), 'size_bytes' => strlen($dumpContent)],
            $this->attachmentsManifest(['a.txt' => $fileContent]),
        );

        $zip = $this->buildZip($manifest, $dumpContent, ['a.txt' => $fileContent]);

        $result = (new BackupArchiveContentVerifier())->verify($zip, 'uuid-1', 'manual', 'full');

        $this->assertSame('uuid-1', $result['backup_uuid']);
    }

    public function test_dump_hash_mismatch_is_rejected(): void
    {
        $dumpContent = 'SELECT 1;';
        $manifest = $this->manifest('uuid-2', 'manual', 'database',
            ['sha256' => hash('sha256', 'a-completely-different-dump'), 'size_bytes' => strlen($dumpContent)],
            null,
        );

        $zip = $this->buildZip($manifest, $dumpContent, []);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-2', 'manual', 'database');
    }

    public function test_attachment_hash_mismatch_is_rejected(): void
    {
        $fileContent = 'real-bytes';
        $manifest = $this->manifest('uuid-3', 'manual', 'files', null,
            $this->attachmentsManifest(['a.txt' => 'a-different-value-entirely']), // hash won't match $fileContent below
        );

        $zip = $this->buildZip($manifest, null, ['a.txt' => $fileContent]);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-3', 'manual', 'files');
    }

    public function test_missing_expected_attachment_entry_is_rejected(): void
    {
        $fileContent = 'real-bytes';
        $manifest = $this->manifest('uuid-4', 'manual', 'files', null,
            $this->attachmentsManifest(['a.txt' => $fileContent]),
        );

        $zip = $this->buildZip($manifest, null, ['a.txt' => $fileContent], omit: ['files/attachments/a.txt']);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-4', 'manual', 'files');
    }

    public function test_missing_expected_dump_entry_is_rejected(): void
    {
        $dumpContent = 'SELECT 1;';
        $manifest = $this->manifest('uuid-4b', 'manual', 'database',
            ['sha256' => hash('sha256', $dumpContent), 'size_bytes' => strlen($dumpContent)],
            null,
        );

        $zip = $this->buildZip($manifest, $dumpContent, [], omit: ['database/dump.sql']);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-4b', 'manual', 'database');
    }

    public function test_unexpected_extra_entry_is_rejected(): void
    {
        $fileContent = 'real-bytes';
        $manifest = $this->manifest('uuid-5', 'manual', 'files', null,
            $this->attachmentsManifest(['a.txt' => $fileContent]),
        );

        $zip = $this->buildZip($manifest, null, ['a.txt' => $fileContent], extra: ['files/attachments/sneaky.txt' => 'not in manifest']);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-5', 'manual', 'files');
    }

    public function test_uuid_mismatch_is_rejected(): void
    {
        $manifest = $this->manifest('uuid-6', 'manual', 'database', null, null);
        $manifest['dump'] = null;
        $zip = $this->buildZip($manifest, null, []);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'a-different-uuid', 'manual', 'database');
    }

    public function test_type_mismatch_is_rejected(): void
    {
        $manifest = $this->manifest('uuid-7', 'manual', 'database', null, null);
        $zip = $this->buildZip($manifest, null, []);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-7', 'daily', 'database');
    }

    public function test_scope_mismatch_is_rejected(): void
    {
        $manifest = $this->manifest('uuid-8', 'manual', 'database', null, null);
        $zip = $this->buildZip($manifest, null, []);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-8', 'manual', 'full');
    }

    public function test_scope_requiring_database_without_a_dump_manifest_is_rejected(): void
    {
        $manifest = $this->manifest('uuid-9', 'manual', 'database', null, null);
        $zip = $this->buildZip($manifest, null, []);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-9', 'manual', 'database');
    }

    public function test_scope_requiring_files_without_an_attachments_manifest_is_rejected(): void
    {
        $manifest = $this->manifest('uuid-10', 'manual', 'files', null, null);
        $zip = $this->buildZip($manifest, null, []);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-10', 'manual', 'files');
    }

    public function test_missing_manifest_json_is_rejected(): void
    {
        $zip = $this->workDir.DIRECTORY_SEPARATOR.'no-manifest.zip';
        $z = new ZipArchive();
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('database/dump.sql', 'x');
        $z->close();

        $this->expectException(BackupIntegrityException::class);
        (new BackupArchiveContentVerifier())->verify($zip, 'uuid-11', 'manual', 'database');
    }

    // ---- helpers --------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function manifest(string $uuid, string $type, string $scope, ?array $dump, ?array $attachments): array
    {
        return [
            'archive_version' => BackupManifestBuilder::VERSION,
            'backup_uuid' => $uuid,
            'backup_type' => $type,
            'backup_scope' => $scope,
            'dump' => $dump,
            'attachments' => $attachments,
        ];
    }

    /**
     * @param  array<string,string>  $files  relative path => content
     * @return array<string,mixed>
     */
    private function attachmentsManifest(array $files): array
    {
        $entries = [];
        $total = 0;

        foreach ($files as $path => $content) {
            $entries[] = ['path' => $path, 'sha256' => hash('sha256', $content), 'size' => strlen($content)];
            $total += strlen($content);
        }

        return ['file_count' => count($entries), 'total_size_bytes' => $total, 'files' => $entries];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,string>  $attachmentFiles  relative path => content
     * @param  list<string>  $omit  entry names to skip writing even though referenced
     * @param  array<string,string>  $extra  extra entry name => content, not in manifest
     */
    private function buildZip(array $manifest, ?string $dumpContent, array $attachmentFiles, array $omit = [], array $extra = []): string
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.uniqid('archive', true).'.zip';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        if (! in_array('manifest.json', $omit, true)) {
            $zip->addFromString('manifest.json', json_encode($manifest));
        }

        if ($dumpContent !== null && ! in_array('database/dump.sql', $omit, true)) {
            $zip->addFromString('database/dump.sql', $dumpContent);
        }

        foreach ($attachmentFiles as $relativePath => $content) {
            $entryName = 'files/attachments/'.$relativePath;

            if (! in_array($entryName, $omit, true)) {
                $zip->addFromString($entryName, $content);
            }
        }

        foreach ($extra as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }

        $zip->close();

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
