<?php

namespace App\Services\Restore\Attachments;

use App\Services\Backup\Contracts\SymlinkDetector;
use App\Services\Backup\NativeSymlinkDetector;
use App\Services\Restore\Exceptions\RestoreAttachmentValidationException;

/**
 * OMS Task 7C.6 — the complete revalidation of a staged attachment tree
 * immediately before its first live rename. RestoreArchiveExtractor already
 * verified/extracted this content once (Task 7C.3), but staged plaintext on
 * disk could theoretically change before it becomes live — this
 * independently re-derives and re-checks everything against an already
 * strictly-validated RestoreAttachmentManifest, never a raw caller-supplied
 * array.
 *
 * Deliberately does NOT consult AttachmentStorageService's mutable upload
 * allowlist (App\Services\Attachments\AttachmentUploadService's
 * ALLOWED_DIRECTORIES/ALLOWED_PREFIXES, or any Filament FileUpload
 * acceptedFileTypes) — a historically valid, already hash-verified
 * attachment must never be rejected because today's upload rules changed.
 * The only content-shape rule enforced here is the fixed, configured
 * executable/script extension denylist (defense in depth, applied
 * regardless of what the manifest says).
 *
 * Fails closed on the first violated rule and never touches the live
 * 'attachments' disk — every check here only ever reads the staged tree.
 */
final class RestoreAttachmentRevalidator
{
    public function __construct(
        private readonly SymlinkDetector $symlinkDetector = new NativeSymlinkDetector(),
    ) {
    }

    /**
     * @throws RestoreAttachmentValidationException
     */
    public function revalidate(string $stagedAttachmentsRoot, RestoreAttachmentManifest $manifest): void
    {
        $denylist = array_map('strtolower', array_map('strval', (array) config('oms.backup.restore.attachments_denied_extensions', [])));

        $expectedByNormalizedPath = $manifest->entriesByNormalizedPath();
        $actualByNormalizedPath = $this->scanStagedTree($stagedAttachmentsRoot);

        $expectedKeys = array_keys($expectedByNormalizedPath);
        $actualKeys = array_keys($actualByNormalizedPath);
        sort($expectedKeys);
        sort($actualKeys);

        if ($expectedKeys !== $actualKeys) {
            throw RestoreAttachmentValidationException::fileSetMismatch();
        }

        $totalBytes = 0;

        foreach ($expectedByNormalizedPath as $normalized => $expected) {
            $absolute = $actualByNormalizedPath[$normalized];

            $this->assertNotDeniedExtension($expected['path'], $denylist);

            $actualSize = @filesize($absolute);

            if ($actualSize === false || $actualSize !== $expected['size']) {
                throw RestoreAttachmentValidationException::sizeMismatch();
            }

            $actualHash = @hash_file('sha256', $absolute);

            if ($actualHash === false || ! hash_equals($expected['sha256'], $actualHash)) {
                throw RestoreAttachmentValidationException::hashMismatch();
            }

            $totalBytes += $actualSize;
        }

        if ($totalBytes !== $manifest->expectedTotalBytes) {
            throw RestoreAttachmentValidationException::totalBytesMismatch();
        }
    }

    /**
     * @return array<string, string> normalized relative path => absolute path
     */
    private function scanStagedTree(string $root): array
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        if (! is_dir($root)) {
            return [];
        }

        if ($this->symlinkDetector->isLink($root)) {
            throw RestoreAttachmentValidationException::symlinkDetected();
        }

        $result = [];
        $this->walk($normalizedRoot, $root, $result);

        return $result;
    }

    /**
     * @param  array<string, string>  $result
     */
    private function walk(string $normalizedRoot, string $directory, array &$result): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            throw RestoreAttachmentValidationException::stagedTreeUnreadable();
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$entry;

            if ($this->symlinkDetector->isLink($full)) {
                throw RestoreAttachmentValidationException::symlinkDetected();
            }

            if (is_dir($full)) {
                $this->walk($normalizedRoot, $full, $result);

                continue;
            }

            if (! is_file($full)) {
                throw RestoreAttachmentValidationException::specialFileDetected();
            }

            $normalizedFull = str_replace('\\', '/', $full);
            $relative = ltrim(substr($normalizedFull, strlen($normalizedRoot)), '/');
            $normalized = $this->normalize($relative);

            if (isset($result[$normalized])) {
                throw RestoreAttachmentValidationException::duplicateNormalizedPath();
            }

            $result[$normalized] = $full;
        }
    }

    private function normalize(string $path): string
    {
        $slashed = str_replace('\\', '/', $path);
        $segments = array_filter(explode('/', $slashed), static fn (string $segment): bool => $segment !== '' && $segment !== '.');

        return implode('/', $segments);
    }

    /**
     * Rejects a denied extension appearing as ANY dot-separated segment of
     * the basename (case-insensitively) — not merely the final extension —
     * so a compound name such as "shell.php.jpg" is caught the same way a
     * bare "shell.php" is, and a dotfile such as ".htaccess" is caught via
     * its own non-empty segment.
     *
     * @param  list<string>  $denylistLower
     */
    private function assertNotDeniedExtension(string $manifestPath, array $denylistLower): void
    {
        $basename = strtolower(basename(str_replace('\\', '/', $manifestPath)));
        $segments = array_filter(explode('.', $basename), static fn (string $segment): bool => $segment !== '');

        foreach ($segments as $segment) {
            if (in_array($segment, $denylistLower, true)) {
                throw RestoreAttachmentValidationException::deniedExtension();
            }
        }
    }
}
