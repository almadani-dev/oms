<?php

namespace App\Services\Backup;

final class AttachmentCollectionResult
{
    /**
     * @param  list<array{path: string, sha256: string, size: int}>  $files  sorted by path, relative to the source disk
     */
    public function __construct(
        public readonly array $files,
        public readonly int $fileCount,
        public readonly int $totalSizeBytes,
    ) {
    }
}
