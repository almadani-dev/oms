<?php

namespace Tests\Unit\Services\Restore\Attachments;

use App\Services\Restore\Attachments\RestoreAttachmentManifest;
use App\Services\Restore\Exceptions\RestoreAttachmentValidationException;
use Tests\TestCase;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — RestoreAttachmentManifest:
 * the only way RestoreAttachmentRevalidator ever receives an "expected file
 * set" — never a raw, arbitrary array. Every entry is validated and
 * normalized once, at construction.
 */
class RestoreAttachmentManifestTest extends TestCase
{
    private function entry(string $path, string $content, ?int $sizeOverride = null): array
    {
        return [
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'size' => $sizeOverride ?? strlen($content),
        ];
    }

    public function test_a_valid_file_list_constructs_successfully(): void
    {
        $manifest = RestoreAttachmentManifest::fromManifestFiles([
            $this->entry('receipts/a.jpg', 'aaa'),
            $this->entry('receipts/b.jpg', 'bbbb'),
        ], 7);

        $this->assertSame(2, $manifest->count());
        $this->assertSame(7, $manifest->expectedTotalBytes);
    }

    public function test_empty_file_list_with_zero_total_bytes_is_valid(): void
    {
        $manifest = RestoreAttachmentManifest::fromManifestFiles([], 0);

        $this->assertSame(0, $manifest->count());
    }

    public function test_duplicate_normalized_paths_are_rejected_at_construction(): void
    {
        $entry = $this->entry('receipts/a.jpg', 'aaa');

        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([$entry, $entry], 3);
    }

    public function test_duplicate_paths_that_normalize_the_same_are_rejected(): void
    {
        $a = $this->entry('receipts/./a.jpg', 'aaa');
        $b = $this->entry('receipts/a.jpg', 'aaa');

        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([$a, $b], 6);
    }

    public function test_missing_required_key_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([['path' => 'a.jpg', 'sha256' => hash('sha256', 'a')]], 1);
    }

    public function test_non_string_path_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([['path' => 123, 'sha256' => hash('sha256', 'a'), 'size' => 1]], 1);
    }

    public function test_unsafe_path_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([['path' => '../../etc/passwd', 'sha256' => hash('sha256', 'a'), 'size' => 1]], 1);
    }

    public function test_malformed_hash_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([['path' => 'a.jpg', 'sha256' => 'not-a-real-hash', 'size' => 1]], 1);
    }

    public function test_short_hash_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([['path' => 'a.jpg', 'sha256' => 'abcd', 'size' => 1]], 1);
    }

    public function test_uppercase_hash_is_normalized_and_accepted(): void
    {
        $manifest = RestoreAttachmentManifest::fromManifestFiles([
            ['path' => 'a.jpg', 'sha256' => strtoupper(hash('sha256', 'aaa')), 'size' => 3],
        ], 3);

        $this->assertSame(hash('sha256', 'aaa'), $manifest->entriesByNormalizedPath()['a.jpg']['sha256']);
    }

    public function test_negative_size_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([['path' => 'a.jpg', 'sha256' => hash('sha256', 'a'), 'size' => -1]], 0);
    }

    public function test_oversized_declared_size_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([
            ['path' => 'a.jpg', 'sha256' => hash('sha256', 'a'), 'size' => 20_000_000_000],
        ], 20_000_000_000);
    }

    public function test_negative_expected_total_bytes_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles([], -1);
    }

    public function test_non_array_entry_is_rejected(): void
    {
        $this->expectException(RestoreAttachmentValidationException::class);
        RestoreAttachmentManifest::fromManifestFiles(['not-an-array'], 0);
    }

    public function test_a_historically_valid_extension_not_on_any_denylist_is_still_accepted_here(): void
    {
        // RestoreAttachmentManifest never applies the executable/script
        // denylist itself — that is RestoreAttachmentRevalidator's own,
        // separate responsibility against the staged tree.
        $manifest = RestoreAttachmentManifest::fromManifestFiles([$this->entry('receipts/shell.php', 'x')], 1);

        $this->assertSame(1, $manifest->count());
    }
}
