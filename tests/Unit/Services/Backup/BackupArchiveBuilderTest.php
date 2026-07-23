<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupArchiveBuilder;
use RuntimeException;
use Tests\Support\Backup\FakeSymlinkDetector;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers the archive-building portion of the OMS Task 7B.1
 * "ARCHIVE / MANIFEST" test category (items 29, 30).
 */
class BackupArchiveBuilderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-archive-test-'.uniqid('', true);
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    public function test_builds_a_valid_zip_with_manifest_and_files(): void
    {
        $dumpPath = $this->workDir.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($dumpPath, 'SELECT 1;');

        $attachmentsRoot = $this->workDir.DIRECTORY_SEPARATOR.'attach'.DIRECTORY_SEPARATOR;
        mkdir($attachmentsRoot.'receipts', 0777, true);
        file_put_contents($attachmentsRoot.'receipts'.DIRECTORY_SEPARATOR.'a.txt', 'content-a');

        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'archive.zip';
        $builder = new BackupArchiveBuilder($zipPath);
        $builder->addManifest(['backup_uuid' => 'abc']);
        $builder->addDatabaseDump($dumpPath);
        $builder->addAttachmentFiles([['path' => 'receipts/a.txt', 'sha256' => hash('sha256', 'content-a'), 'size' => 9]], $attachmentsRoot);
        $builder->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('database/dump.sql'));
        $this->assertNotFalse($zip->locateName('files/attachments/receipts/a.txt'));
        $this->assertSame('content-a', $zip->getFromName('files/attachments/receipts/a.txt'));
        $zip->close();
    }

    // ---- 29. unsafe archive paths are rejected -----------------------------------

    public function test_rejects_path_traversal_entry_name(): void
    {
        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'a.zip';
        $dumpPath = $this->workDir.DIRECTORY_SEPARATOR.'d.sql';
        file_put_contents($dumpPath, 'x');

        $attachmentsRoot = $this->workDir.DIRECTORY_SEPARATOR;

        $builder = new BackupArchiveBuilder($zipPath);

        $this->expectException(RuntimeException::class);
        $builder->addAttachmentFiles([['path' => '../../etc/passwd', 'sha256' => 'x', 'size' => 1]], $attachmentsRoot);
    }

    public function test_rejects_absolute_entry_name(): void
    {
        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'b.zip';
        $builder = new BackupArchiveBuilder($zipPath);

        $this->expectException(RuntimeException::class);
        $builder->addAttachmentFiles([['path' => '/etc/passwd', 'sha256' => 'x', 'size' => 1]], $this->workDir);
    }

    public function test_rejects_drive_letter_entry_name(): void
    {
        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'c.zip';
        $builder = new BackupArchiveBuilder($zipPath);

        $this->expectException(RuntimeException::class);
        $builder->addAttachmentFiles([['path' => 'C:\\Windows\\System32\\evil.dll', 'sha256' => 'x', 'size' => 1]], $this->workDir);
    }

    public function test_rejects_null_byte_entry_name(): void
    {
        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'d.zip';
        $builder = new BackupArchiveBuilder($zipPath);

        $this->expectException(RuntimeException::class);
        $builder->addAttachmentFiles([['path' => "evil\0.txt", 'sha256' => 'x', 'size' => 1]], $this->workDir);
    }

    // ---- 30. symlink escape is rejected --------------------------------------------

    public function test_rejects_symlinked_source_file(): void
    {
        $realFile = $this->workDir.DIRECTORY_SEPARATOR.'real.txt';
        file_put_contents($realFile, 'real');

        $linkFile = $this->workDir.DIRECTORY_SEPARATOR.'link.txt';

        if (! @symlink($realFile, $linkFile)) {
            $this->markTestSkipped('This environment does not permit creating symlinks.');
        }

        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'e.zip';
        $builder = new BackupArchiveBuilder($zipPath);

        $this->expectException(RuntimeException::class);
        $builder->addAttachmentFiles([['path' => 'link.txt', 'sha256' => 'x', 'size' => 4]], $this->workDir);
    }

    // ---- correction 5: deterministic symlink coverage (no OS privileges needed) -------------

    public function test_symlinked_source_is_rejected_deterministically_via_fake_detector(): void
    {
        $realFile = $this->workDir.DIRECTORY_SEPARATOR.'pretend-link.txt';
        file_put_contents($realFile, 'content');

        $detector = new FakeSymlinkDetector([$realFile]);
        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'f.zip';
        $builder = new BackupArchiveBuilder($zipPath, $detector);

        $this->expectException(RuntimeException::class);
        $builder->addAttachmentFiles([['path' => 'pretend-link.txt', 'sha256' => 'x', 'size' => 7]], $this->workDir);
    }

    public function test_no_symlink_entry_is_added_to_the_zip_when_rejected(): void
    {
        $realFile = $this->workDir.DIRECTORY_SEPARATOR.'pretend-link2.txt';
        file_put_contents($realFile, 'content');

        $detector = new FakeSymlinkDetector([$realFile]);
        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'g.zip';
        $builder = new BackupArchiveBuilder($zipPath, $detector);
        // A real entry added first (as every real archive does with its
        // manifest) so the archive is never zero-entry — some libzip
        // versions silently discard a truly empty ZIP on close(), which
        // would make the re-open below fail for a reason unrelated to
        // what this test is actually proving.
        $builder->addManifest(['backup_uuid' => 'test']);

        try {
            $builder->addAttachmentFiles([['path' => 'pretend-link2.txt', 'sha256' => 'x', 'size' => 7]], $this->workDir);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            $builder->close();
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertFalse($zip->locateName('pretend-link2.txt'));
        $this->assertFalse($zip->locateName('files/attachments/pretend-link2.txt'));
        $zip->close();
    }

    public function test_symlink_resolving_outside_approved_root_is_rejected_deterministically(): void
    {
        // The fake detector stands in for "this path's real target
        // resolves outside the approved attachments root" — from
        // BackupArchiveBuilder's point of view every symlink is rejected
        // identically (never followed to check where it points), which is
        // exactly why an out-of-root-resolving symlink can never slip
        // through as a strict subset of "any symlink at all".
        $escapeFile = $this->workDir.DIRECTORY_SEPARATOR.'escape-attempt.txt';
        file_put_contents($escapeFile, 'attacker content');

        $detector = new FakeSymlinkDetector([$escapeFile]);
        $zipPath = $this->workDir.DIRECTORY_SEPARATOR.'h.zip';
        $builder = new BackupArchiveBuilder($zipPath, $detector);

        $this->expectException(RuntimeException::class);
        $builder->addAttachmentFiles([['path' => 'escape-attempt.txt', 'sha256' => 'x', 'size' => 17]], $this->workDir);
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
