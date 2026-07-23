<?php

namespace App\Services\Backup;

use App\Services\Backup\Contracts\SymlinkDetector;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only enumeration of storage/app/private/attachments (the 'attachments'
 * disk — see config/filesystems.php). This is the only directory Task 7B.1's
 * approved design includes in a backup archive; storage/app/public is
 * explicitly excluded from v1.
 *
 * Never copies or reads file contents into a long-lived PHP string —
 * hash_file() streams internally. Never exposes an absolute filesystem
 * path in its return value; only disk-relative paths.
 */
final class AttachmentCollector
{
    public function __construct(
        private readonly string $disk = 'attachments',
        private readonly ?SymlinkDetector $symlinkDetector = null,
    ) {
    }

    public function collect(): AttachmentCollectionResult
    {
        $storage = Storage::disk($this->disk);
        $detector = $this->symlinkDetector ?? new NativeSymlinkDetector();

        $entries = [];
        $totalSize = 0;

        foreach ($storage->allFiles() as $relativePath) {
            $absolutePath = $storage->path($relativePath);

            // Defense in depth against a symlink escaping the approved
            // source directory, regardless of the local Flysystem adapter's
            // own link-handling default. Every symlink is rejected
            // unconditionally — never followed to check where it resolves
            // to, which also covers "resolves outside the approved root"
            // as a strict subset of "is a symlink at all".
            if ($detector->isLink($absolutePath)) {
                continue;
            }

            if (! is_file($absolutePath)) {
                continue;
            }

            $size = (int) $storage->size($relativePath);
            $hash = (string) hash_file('sha256', $absolutePath);

            $entries[] = ['path' => $relativePath, 'sha256' => $hash, 'size' => $size];
            $totalSize += $size;
        }

        usort($entries, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return new AttachmentCollectionResult(
            files: $entries,
            fileCount: count($entries),
            totalSizeBytes: $totalSize,
        );
    }
}
