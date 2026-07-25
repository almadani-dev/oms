<?php

namespace Tests\Unit\Services\Restore;

use App\Enums\BackupScope;
use App\Services\Restore\Exceptions\RestoreArchiveExtractionException;
use App\Services\Restore\RestoreArchiveExtractor;
use App\Services\Restore\RestoreWorkspace;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;
use ZipArchive;

/**
 * OMS Task 7C.3 — RestoreArchiveExtractor. Every ZIP here is hand-built
 * with a specific known defect (or none), matching
 * BackupArchiveContentVerifierTest's own style — no encryption, no
 * preflight, no preparer involved; only the safe-extraction rules
 * themselves.
 */
class RestoreArchiveExtractorTest extends BackupTestCase
{
    private const RESTORE_UUID = 'aaaaaaaa-3333-3333-3333-333333333333';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-extractor-test-'.uniqid('', true);
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    private function extractor(): RestoreArchiveExtractor
    {
        return new RestoreArchiveExtractor();
    }

    private function workspace(): RestoreWorkspace
    {
        $workspace = new RestoreWorkspace(self::RESTORE_UUID);
        $workspace->prepare();

        return $workspace;
    }

    /**
     * @param  list<array{name: string, content: string, symlink?: bool}>  $entries
     */
    private function buildZip(array $entries): string
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.uniqid('archive', true).'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $entry) {
            $zip->addFromString($entry['name'], $entry['content']);

            if ($entry['symlink'] ?? false) {
                $unixMode = 0120777; // S_IFLNK | 0777
                $zip->setExternalAttributesName($entry['name'], ZipArchive::OPSYS_UNIX, $unixMode << 16);
            }
        }

        $zip->close();

        return $path;
    }

    private function dumpManifest(string $dumpContent): array
    {
        return ['sha256' => hash('sha256', $dumpContent), 'size_bytes' => strlen($dumpContent)];
    }

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

    // ---- happy paths ------------------------------------------------------------------------

    public function test_valid_database_only_extraction(): void
    {
        $dumpContent = 'CREATE TABLE x (id INT);';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'database/dump.sql', 'content' => $dumpContent],
        ]);

        $manifest = ['dump' => $this->dumpManifest($dumpContent)];
        $workspace = $this->workspace();

        $result = $this->extractor()->extract($zip, $manifest, BackupScope::Database, $workspace);

        $this->assertSame('database/dump.sql', $result->stagedDumpRelativePath);
        $this->assertSame(0, $result->stagedAttachmentsCount);
        $this->assertSame($dumpContent, file_get_contents($workspace->stagedDumpPath()));
    }

    public function test_valid_files_only_extraction(): void
    {
        $files = ['receipts/a.jpg' => 'file-a-content', 'receipts/b.jpg' => 'file-b-content'];
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'files/attachments/receipts/a.jpg', 'content' => $files['receipts/a.jpg']],
            ['name' => 'files/attachments/receipts/b.jpg', 'content' => $files['receipts/b.jpg']],
        ]);

        $manifest = ['attachments' => $this->attachmentsManifest($files)];
        $workspace = $this->workspace();

        $result = $this->extractor()->extract($zip, $manifest, BackupScope::Files, $workspace);

        $this->assertNull($result->stagedDumpRelativePath);
        $this->assertSame(2, $result->stagedAttachmentsCount);
        $this->assertSame('file-a-content', file_get_contents($workspace->resolveStagedAttachmentPath('receipts/a.jpg')));
        $this->assertSame('file-b-content', file_get_contents($workspace->resolveStagedAttachmentPath('receipts/b.jpg')));
    }

    public function test_valid_full_extraction(): void
    {
        $dumpContent = 'SELECT 1;';
        $files = ['receipts/a.jpg' => 'file-a-content'];
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'database/dump.sql', 'content' => $dumpContent],
            ['name' => 'files/attachments/receipts/a.jpg', 'content' => $files['receipts/a.jpg']],
        ]);

        $manifest = [
            'archive_version' => 1,
            'backup_uuid' => 'uuid-1',
            'dump' => $this->dumpManifest($dumpContent),
            'attachments' => $this->attachmentsManifest($files),
        ];
        $workspace = $this->workspace();

        $result = $this->extractor()->extract($zip, $manifest, BackupScope::Full, $workspace);

        $this->assertSame('database/dump.sql', $result->stagedDumpRelativePath);
        $this->assertSame(1, $result->stagedAttachmentsCount);
        $this->assertSame(strlen($dumpContent) + strlen($files['receipts/a.jpg']), $result->extractedTotalBytes);
        $this->assertSame('uuid-1', $result->manifestSummary['backup_uuid']);
        $this->assertArrayNotHasKey('files', $result->manifestSummary);
    }

    // ---- zip slip variants --------------------------------------------------------------------

    public function test_path_traversal_attachment_path_is_rejected(): void
    {
        $content = 'evil';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'files/attachments/../../etc/passwd', 'content' => $content],
        ]);

        $manifest = ['attachments' => $this->attachmentsManifest(['../../etc/passwd' => $content])];

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    public function test_absolute_attachment_path_is_rejected(): void
    {
        $content = 'evil';
        $manifest = ['attachments' => $this->attachmentsManifest(['/etc/passwd' => $content])];
        $zip = $this->buildZip([['name' => 'manifest.json', 'content' => '{}']]);

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    public function test_drive_letter_attachment_path_is_rejected(): void
    {
        $content = 'evil';
        $manifest = ['attachments' => $this->attachmentsManifest(['C:\\Windows\\evil.dll' => $content])];
        $zip = $this->buildZip([['name' => 'manifest.json', 'content' => '{}']]);

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    public function test_backslash_traversal_attachment_path_is_rejected(): void
    {
        $content = 'evil';
        $manifest = ['attachments' => $this->attachmentsManifest(['..\\..\\evil.txt' => $content])];
        $zip = $this->buildZip([['name' => 'manifest.json', 'content' => '{}']]);

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    public function test_null_byte_attachment_path_is_rejected(): void
    {
        $content = 'evil';
        $manifest = ['attachments' => $this->attachmentsManifest(["evil\0.txt" => $content])];
        $zip = $this->buildZip([['name' => 'manifest.json', 'content' => '{}']]);

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    // ---- duplicate normalized paths -------------------------------------------------------

    public function test_duplicate_normalized_paths_are_rejected(): void
    {
        $content = 'same-content-different-name';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'files/attachments/a/b.txt', 'content' => $content],
            ['name' => 'files/attachments/a//b.txt', 'content' => $content],
        ]);

        // Two distinct manifest-declared entries whose paths normalize to
        // the same logical file.
        $manifest = ['attachments' => [
            'file_count' => 2,
            'total_size_bytes' => strlen($content) * 2,
            'files' => [
                ['path' => 'a/b.txt', 'sha256' => hash('sha256', $content), 'size' => strlen($content)],
                ['path' => 'a//b.txt', 'sha256' => hash('sha256', $content), 'size' => strlen($content)],
            ],
        ]];

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    // ---- symlink / special entries ---------------------------------------------------------

    public function test_symlink_zip_entry_is_rejected(): void
    {
        $content = 'target-path-as-content';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'files/attachments/evil-link', 'content' => $content, 'symlink' => true],
        ]);

        $manifest = ['attachments' => $this->attachmentsManifest(['evil-link' => $content])];

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    // ---- size / hash mismatches -------------------------------------------------------------

    public function test_declared_size_mismatch_is_rejected(): void
    {
        $content = 'actual-content-here';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'database/dump.sql', 'content' => $content],
        ]);

        // Manifest declares a size that does not match the real ZIP entry.
        $manifest = ['dump' => ['sha256' => hash('sha256', $content), 'size_bytes' => strlen($content) + 5]];

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Database, $this->workspace());
    }

    public function test_streamed_hash_mismatch_is_rejected(): void
    {
        $content = 'actual-content-here';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'database/dump.sql', 'content' => $content],
        ]);

        // Size matches, but the declared hash does not.
        $manifest = ['dump' => ['sha256' => hash('sha256', 'a-completely-different-value'), 'size_bytes' => strlen($content)]];
        $workspace = $this->workspace();

        try {
            $this->extractor()->extract($zip, $manifest, BackupScope::Database, $workspace);
            $this->fail('Expected RestoreArchiveExtractionException.');
        } catch (RestoreArchiveExtractionException $e) {
            $this->assertSame('hash_mismatch', $e->reasonCode);
        }

        $this->assertFalse(is_file($workspace->stagedDumpPath()));
    }

    // ---- unexpected entry ---------------------------------------------------------------------

    public function test_manifest_entry_missing_from_the_zip_is_rejected(): void
    {
        $zip = $this->buildZip([['name' => 'manifest.json', 'content' => '{}']]);

        $manifest = ['attachments' => $this->attachmentsManifest(['receipts/missing.jpg' => 'not-actually-in-zip'])];

        $this->expectException(RestoreArchiveExtractionException::class);
        $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
    }

    // ---- OMS Task 7C.3 correction: independent entry-set revalidation at extraction time ------

    /**
     * A mutation/TOCTOU-style scenario: the ZIP physically contains an
     * extra entry that the manifest never declared at all. Even though
     * nothing in the scope-specific extraction loop would ever look this
     * entry up by name, the independent full-manifest entry-set check must
     * reject the archive outright (not merely ignore the stray entry)
     * before any staged content is written.
     */
    public function test_unexpected_extra_zip_entry_not_declared_in_manifest_is_rejected_before_extraction(): void
    {
        $dumpContent = 'SELECT 1;';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'database/dump.sql', 'content' => $dumpContent],
            ['name' => 'files/attachments/sneaky-not-in-manifest.bin', 'content' => 'attacker-planted-content'],
        ]);

        $manifest = ['dump' => $this->dumpManifest($dumpContent)];
        $workspace = $this->workspace();

        try {
            $this->extractor()->extract($zip, $manifest, BackupScope::Database, $workspace);
            $this->fail('Expected RestoreArchiveExtractionException.');
        } catch (RestoreArchiveExtractionException $e) {
            $this->assertSame('unexpected_entry', $e->reasonCode);
        }

        $this->assertFalse(is_file($workspace->stagedDumpPath()), 'No staged content should ever be written once the entry-set check fails.');
    }

    /**
     * The independent entry-set check re-derives the FULL archive's
     * expected entries from the manifest — not merely the ones the
     * currently SELECTED extraction scope needs — so a missing attachment
     * entry is caught even when only extracting the database scope.
     */
    public function test_full_manifest_integrity_is_revalidated_even_when_extracting_a_narrower_scope(): void
    {
        $dumpContent = 'SELECT 1;';
        // The zip is missing the attachment entry the manifest declares —
        // even though this restore only wants the database scope.
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'database/dump.sql', 'content' => $dumpContent],
        ]);

        $manifest = [
            'dump' => $this->dumpManifest($dumpContent),
            'attachments' => $this->attachmentsManifest(['receipts/missing.jpg' => 'declared-but-absent']),
        ];

        try {
            $this->extractor()->extract($zip, $manifest, BackupScope::Database, $this->workspace());
            $this->fail('Expected RestoreArchiveExtractionException.');
        } catch (RestoreArchiveExtractionException $e) {
            $this->assertSame('unexpected_entry', $e->reasonCode);
        }
    }

    /**
     * A duplicate-normalized-path manifest is rejected by the independent
     * entry-set check itself, before the scope-specific extraction loop
     * ever runs — proven here with a Files-scope extraction where the
     * duplicate would otherwise only be caught later.
     */
    public function test_duplicate_normalized_paths_are_rejected_by_the_entry_set_check_itself(): void
    {
        $content = 'same-content-different-name';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'files/attachments/a/b.txt', 'content' => $content],
            ['name' => 'files/attachments/a//b.txt', 'content' => $content],
        ]);

        $manifest = ['attachments' => [
            'file_count' => 2,
            'total_size_bytes' => strlen($content) * 2,
            'files' => [
                ['path' => 'a/b.txt', 'sha256' => hash('sha256', $content), 'size' => strlen($content)],
                ['path' => 'a//b.txt', 'sha256' => hash('sha256', $content), 'size' => strlen($content)],
            ],
        ]];

        try {
            $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
            $this->fail('Expected RestoreArchiveExtractionException.');
        } catch (RestoreArchiveExtractionException $e) {
            $this->assertSame('duplicate_entry', $e->reasonCode);
        }
    }

    /**
     * An unsafe manifest-declared path is rejected by the entry-set check
     * itself even when the ZIP happens to physically contain a literally-
     * matching (also unsafe-named) entry — safety is never contingent on
     * whether the raw name happens to also exist in the ZIP.
     */
    public function test_unsafe_path_is_rejected_by_the_entry_set_check_even_with_a_matching_zip_entry(): void
    {
        $content = 'evil';
        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'files/attachments/../../etc/passwd', 'content' => $content],
        ]);

        $manifest = ['attachments' => $this->attachmentsManifest(['../../etc/passwd' => $content])];

        try {
            $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
            $this->fail('Expected RestoreArchiveExtractionException.');
        } catch (RestoreArchiveExtractionException $e) {
            $this->assertSame('unsafe_path', $e->reasonCode);
        }
    }

    // ---- total byte budget -------------------------------------------------------------------

    public function test_extraction_stops_at_the_declared_total_byte_bound(): void
    {
        $fileA = str_repeat('a', 100);
        $fileB = str_repeat('b', 100);

        $zip = $this->buildZip([
            ['name' => 'manifest.json', 'content' => '{}'],
            ['name' => 'files/attachments/a.bin', 'content' => $fileA],
            ['name' => 'files/attachments/b.bin', 'content' => $fileB],
        ]);

        // Manifest declares a total far smaller than the sum of its own
        // per-file declared sizes — the second file must be rejected
        // before it is ever streamed.
        $manifest = ['attachments' => [
            'file_count' => 2,
            'total_size_bytes' => 100,
            'files' => [
                ['path' => 'a.bin', 'sha256' => hash('sha256', $fileA), 'size' => 100],
                ['path' => 'b.bin', 'sha256' => hash('sha256', $fileB), 'size' => 100],
            ],
        ]];

        try {
            $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
            $this->fail('Expected RestoreArchiveExtractionException.');
        } catch (RestoreArchiveExtractionException $e) {
            $this->assertSame('byte_limit_exceeded', $e->reasonCode);
        }
    }

    // ---- failure never touches live disks ------------------------------------------------

    public function test_failure_never_touches_live_attachments_disk(): void
    {
        Storage::disk('attachments')->put('receipts/real-live-file.jpg', 'must-not-change');

        $zip = $this->buildZip([['name' => 'manifest.json', 'content' => '{}']]);
        $manifest = ['attachments' => $this->attachmentsManifest(['receipts/missing.jpg' => 'x'])];

        try {
            $this->extractor()->extract($zip, $manifest, BackupScope::Files, $this->workspace());
        } catch (RestoreArchiveExtractionException) {
            // expected
        }

        $this->assertSame('must-not-change', Storage::disk('attachments')->get('receipts/real-live-file.jpg'));
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
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
