<?php

namespace App\Services\Backup;

final class DumpResult
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }
}
