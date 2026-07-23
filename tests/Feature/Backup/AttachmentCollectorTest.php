<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\AttachmentCollector;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\FakeSymlinkDetector;

/**
 * Covers the attachment-collection portion of the OMS Task 7B.1
 * "ARCHIVE / MANIFEST" test category (items 28, 31) plus the collector's
 * own deterministic-ordering guarantee. Always Storage::fake('attachments')
 * — no real attachment file is ever read.
 */
class AttachmentCollectorTest extends BackupTestCase
{
    // ---- 31. empty attachment set works ---------------------------------------

    public function test_empty_attachment_directory_returns_empty_result(): void
    {
        $result = (new AttachmentCollector('attachments'))->collect();

        $this->assertSame(0, $result->fileCount);
        $this->assertSame(0, $result->totalSizeBytes);
        $this->assertSame([], $result->files);
    }

    // ---- 28. deterministic attachment hashes + sorted ordering -----------------

    public function test_collects_files_with_deterministic_sorted_hashes(): void
    {
        Storage::disk('attachments')->put('general-expenses/b.jpg', 'bbb-content');
        Storage::disk('attachments')->put('general-expenses/a.jpg', 'aaa-content');
        Storage::disk('attachments')->put('receipts/c.pdf', 'ccc-content');

        $result = (new AttachmentCollector('attachments'))->collect();

        $this->assertSame(3, $result->fileCount);
        $this->assertSame(
            strlen('bbb-content') + strlen('aaa-content') + strlen('ccc-content'),
            $result->totalSizeBytes,
        );

        $paths = array_column($result->files, 'path');
        $sorted = $paths;
        sort($sorted);
        $this->assertSame($sorted, $paths, 'Files must be returned in deterministic sorted order.');

        foreach ($result->files as $file) {
            $this->assertSame(64, strlen($file['sha256']));
            $this->assertFalse(str_starts_with($file['path'], '/'));
        }

        $aEntry = collect($result->files)->firstWhere('path', 'general-expenses/a.jpg');
        $this->assertSame(hash('sha256', 'aaa-content'), $aEntry['sha256']);
    }

    public function test_collection_is_deterministic_across_two_calls(): void
    {
        Storage::disk('attachments')->put('x/1.jpg', 'one');
        Storage::disk('attachments')->put('x/2.jpg', 'two');

        $collector = new AttachmentCollector('attachments');

        $this->assertSame($collector->collect()->files, $collector->collect()->files);
    }

    /**
     * OS-level rehearsal: only meaningful on a host that permits creating
     * real symlinks without elevated privileges (not this Windows/CI
     * environment by default — gracefully skipped here). A genuine Linux
     * symlink-escape rehearsal remains part of Task 7D's staging
     * acceptance pass. The deterministic tests below (using
     * FakeSymlinkDetector) cover the same rejection logic without needing
     * real OS symlink support.
     */
    public function test_symlinks_are_never_followed(): void
    {
        Storage::disk('attachments')->put('real/file.txt', 'real content');

        $absoluteRoot = Storage::disk('attachments')->path('');
        $target = $absoluteRoot.'real'.DIRECTORY_SEPARATOR.'file.txt';
        $linkDir = $absoluteRoot.'linked';

        if (! is_dir($linkDir)) {
            @mkdir($linkDir, 0777, true);
        }

        $linkPath = $linkDir.DIRECTORY_SEPARATOR.'escape.txt';

        // Symlink creation can fail without elevated privileges on Windows —
        // skip gracefully rather than failing the whole suite on a
        // permissions limitation unrelated to the collector's own logic.
        if (! @symlink($target, $linkPath)) {
            $this->markTestSkipped('This environment does not permit creating symlinks.');
        }

        $result = (new AttachmentCollector('attachments'))->collect();
        $paths = array_column($result->files, 'path');

        $this->assertContains('real/file.txt', $paths);
        $this->assertFalse(
            collect($paths)->contains(fn (string $path): bool => str_contains($path, 'linked')),
            'A symlinked entry must never be included in the collected attachment set.',
        );
    }

    // ---- correction 5: deterministic symlink coverage (no OS privileges needed) -------------

    public function test_a_source_identified_as_a_symlink_is_rejected_deterministically(): void
    {
        Storage::disk('attachments')->put('real/file.txt', 'real content');
        Storage::disk('attachments')->put('linked/escape.txt', 'pretend-symlink-content');

        $linkedAbsolute = Storage::disk('attachments')->path('linked/escape.txt');
        $detector = new FakeSymlinkDetector([$linkedAbsolute]);

        $result = (new AttachmentCollector('attachments', $detector))->collect();
        $paths = array_column($result->files, 'path');

        $this->assertContains('real/file.txt', $paths);
        $this->assertNotContains('linked/escape.txt', $paths);
        $this->assertSame(1, $result->fileCount);
    }

    public function test_a_symlink_resolving_outside_the_approved_root_is_rejected_deterministically(): void
    {
        // Simulates a symlink whose *target* resolves outside
        // storage/app/private/attachments (e.g. into /etc or a sibling
        // directory) — from the collector's point of view this is
        // reported identically to any other symlink by the detector,
        // which is exactly why every symlink is rejected unconditionally
        // rather than only ones that "look like" they escape.
        Storage::disk('attachments')->put('escape-attempt.txt', 'attacker-controlled-content');

        $escapeAbsolute = Storage::disk('attachments')->path('escape-attempt.txt');
        $detector = new FakeSymlinkDetector([$escapeAbsolute]);

        $result = (new AttachmentCollector('attachments', $detector))->collect();

        $this->assertSame([], $result->files);
        $this->assertSame(0, $result->fileCount);
    }
}
