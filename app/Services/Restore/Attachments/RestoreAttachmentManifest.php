<?php

namespace App\Services\Restore\Attachments;

use App\Services\Backup\Support\SafeBackupPath;
use App\Services\Restore\Exceptions\RestoreAttachmentValidationException;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — the bounded, immutable,
 * strictly-validated set of expected staged attachments RestoreAttachmentRevalidator
 * checks the staged tree against. Replaces accepting a raw, arbitrary
 * manifest array directly — every entry is validated and normalized once,
 * at construction, never re-derived or re-trusted piecemeal by the
 * revalidator itself.
 *
 * Constructible only through fromManifestFiles(), which mirrors the exact
 * shape RestoreArchiveExtractor already consumes
 * (manifest['attachments']['files'][] = ['path','sha256','size']) — the
 * future RestoreOrchestrator (Task 7C.7+) recreates this object from the
 * already-verified archive manifest immediately before activation; nothing
 * here persists the full per-file list anywhere (no progress.json payload).
 */
final class RestoreAttachmentManifest
{
    private const MAX_FILE_SIZE_BYTES = 10_737_418_240; // 10 GiB — sanity bound, defense in depth only.

    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @param  array<string, array{path: string, sha256: string, size: int}>  $entriesByNormalizedPath
     */
    private function __construct(
        private readonly array $entriesByNormalizedPath,
        public readonly int $expectedTotalBytes,
    ) {
    }

    /**
     * @param  array<int, mixed>  $files  raw manifest['attachments']['files'] entries
     *
     * @throws RestoreAttachmentValidationException
     */
    public static function fromManifestFiles(array $files, int $expectedTotalBytes): self
    {
        if ($expectedTotalBytes < 0 || $expectedTotalBytes > self::MAX_FILE_SIZE_BYTES) {
            throw RestoreAttachmentValidationException::manifestSizeOutOfBounds();
        }

        $entries = [];

        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['path'], $file['sha256'], $file['size']) || ! is_string($file['path'])) {
                throw RestoreAttachmentValidationException::malformedManifestEntry();
            }

            if (! SafeBackupPath::isSafe($file['path'])) {
                throw RestoreAttachmentValidationException::unsafeManifestPath();
            }

            $sha256 = $file['sha256'];

            if (! is_string($sha256) || preg_match(self::SHA256_PATTERN, strtolower($sha256)) !== 1) {
                throw RestoreAttachmentValidationException::invalidHashFormat();
            }

            $size = $file['size'];

            if (! is_int($size) || $size < 0 || $size > self::MAX_FILE_SIZE_BYTES) {
                throw RestoreAttachmentValidationException::manifestSizeOutOfBounds();
            }

            $normalized = self::normalize($file['path']);

            if (isset($entries[$normalized])) {
                throw RestoreAttachmentValidationException::duplicateManifestPath();
            }

            $entries[$normalized] = [
                'path' => $file['path'],
                'sha256' => strtolower($sha256),
                'size' => $size,
            ];
        }

        return new self($entries, $expectedTotalBytes);
    }

    public function count(): int
    {
        return count($this->entriesByNormalizedPath);
    }

    /**
     * @return array<string, array{path: string, sha256: string, size: int}>
     */
    public function entriesByNormalizedPath(): array
    {
        return $this->entriesByNormalizedPath;
    }

    private static function normalize(string $path): string
    {
        $slashed = str_replace('\\', '/', $path);
        $segments = array_filter(explode('/', $slashed), static fn (string $segment): bool => $segment !== '' && $segment !== '.');

        return implode('/', $segments);
    }
}
