<?php

namespace Tests\Unit\Services\Restore\Attachments;

use App\Services\Restore\Attachments\RestoreAttachmentManifest;
use App\Services\Restore\Attachments\RestoreAttachmentRevalidator;
use App\Services\Restore\Exceptions\RestoreAttachmentValidationException;
use Tests\Support\Backup\FakeSymlinkDetector;
use Tests\TestCase;

/**
 * OMS Task 7C.6 — RestoreAttachmentRevalidator: the complete revalidation of
 * a staged attachment tree immediately before its first live rename. Uses a
 * real local temp directory (never Storage::fake) so hash_file()/filesize()
 * exercise real filesystem behavior. Manifest-shape validation itself
 * (duplicate paths, malformed hashes, unsafe paths) is covered by
 * RestoreAttachmentManifestTest — this file only covers revalidation against
 * an already-valid RestoreAttachmentManifest.
 */
class RestoreAttachmentRevalidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-revalidator-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);

        config(['oms.backup.restore.attachments_denied_extensions' => [
            'php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'dll', 'htaccess',
        ]]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($dir);
    }

    private function writeStagedFile(string $relativePath, string $content): void
    {
        $full = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        @mkdir(dirname($full), 0700, true);
        file_put_contents($full, $content);
    }

    private function manifestEntryFor(string $relativePath, string $content): array
    {
        return [
            'path' => $relativePath,
            'sha256' => hash('sha256', $content),
            'size' => strlen($content),
        ];
    }

    private function manifestFor(array $entries, int $totalBytes): RestoreAttachmentManifest
    {
        return RestoreAttachmentManifest::fromManifestFiles($entries, $totalBytes);
    }

    public function test_a_valid_matching_tree_passes(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'aaa');
        $this->writeStagedFile('receipts/b.jpg', 'bbbb');

        $manifest = $this->manifestFor([
            $this->manifestEntryFor('receipts/a.jpg', 'aaa'),
            $this->manifestEntryFor('receipts/b.jpg', 'bbbb'),
        ], 7);

        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);

        $this->assertTrue(true);
    }

    public function test_missing_file_fails(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'aaa');

        $manifest = $this->manifestFor([
            $this->manifestEntryFor('receipts/a.jpg', 'aaa'),
            $this->manifestEntryFor('receipts/b.jpg', 'bbbb'),
        ], 7);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_unexpected_extra_file_fails(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'aaa');
        $this->writeStagedFile('receipts/unexpected.jpg', 'zzz');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/a.jpg', 'aaa')], 3);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_changed_size_fails(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'changed-content');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/a.jpg', 'aaa')], 3);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_changed_hash_with_same_size_fails(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'xyz');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/a.jpg', 'aaa')], 3);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_total_byte_mismatch_fails_even_when_every_individual_file_matches(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'aaa');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/a.jpg', 'aaa')], 999);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_lowercase_denied_extension_fails(): void
    {
        $this->writeStagedFile('receipts/shell.php', 'malicious');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/shell.php', 'malicious')], 9);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_uppercase_and_mixed_case_denied_extension_fails(): void
    {
        $this->writeStagedFile('receipts/SHELL.PHP', 'malicious');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/SHELL.PHP', 'malicious')], 9);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_compound_extension_variant_fails(): void
    {
        $this->writeStagedFile('receipts/shell.PhP.jpg', 'malicious');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/shell.PhP.jpg', 'malicious')], 9);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_bare_htaccess_dotfile_fails(): void
    {
        $this->writeStagedFile('.htaccess', 'deny all');

        $manifest = $this->manifestFor([$this->manifestEntryFor('.htaccess', 'deny all')], 8);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);
    }

    public function test_a_historically_valid_hash_verified_extension_not_on_the_denylist_still_passes(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'aaa');

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/a.jpg', 'aaa')], 3);

        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);

        $this->assertTrue(true);
    }

    public function test_symlinked_parent_directory_fails(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'aaa');

        $linkedAbsolute = rtrim($this->root, '/\\').DIRECTORY_SEPARATOR.'receipts';
        $detector = new FakeSymlinkDetector([$linkedAbsolute]);

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/a.jpg', 'aaa')], 3);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator($detector))->revalidate($this->root, $manifest);
    }

    public function test_symlinked_root_fails(): void
    {
        $this->writeStagedFile('receipts/a.jpg', 'aaa');

        $detector = new FakeSymlinkDetector([rtrim($this->root, '/\\')]);

        $manifest = $this->manifestFor([$this->manifestEntryFor('receipts/a.jpg', 'aaa')], 3);

        $this->expectException(RestoreAttachmentValidationException::class);
        (new RestoreAttachmentRevalidator($detector))->revalidate($this->root, $manifest);
    }

    public function test_empty_staged_tree_with_empty_manifest_passes(): void
    {
        $manifest = $this->manifestFor([], 0);

        (new RestoreAttachmentRevalidator())->revalidate($this->root, $manifest);

        $this->assertTrue(true);
    }

    /**
     * The raw-array/duplicate-path/unsafe-path rejections now happen at
     * RestoreAttachmentManifest construction time (see
     * RestoreAttachmentManifestTest) — proving here that an arbitrary raw
     * array can never reach the revalidator at all (a type error, not a
     * validation exception) is what closes the "no unvalidated arbitrary
     * array" requirement end to end.
     */
    public function test_revalidate_cannot_be_called_with_a_raw_array_instead_of_a_manifest(): void
    {
        $this->expectException(\TypeError::class);

        // @phpstan-ignore-next-line intentional type violation for this proof
        (new RestoreAttachmentRevalidator())->revalidate($this->root, [$this->manifestEntryFor('receipts/a.jpg', 'aaa')]);
    }
}
