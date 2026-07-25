<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.3 — RestoreArchiveExtractor's internal result, consumed only
 * by RestoreArchivePreparer to build the bounded, public PreparedRestore.
 * `manifestSummary` is already bounded (no per-file attachment list — see
 * RestoreArchiveExtractor::buildManifestSummary()).
 */
final class RestoreExtractionResult
{
    /**
     * @param  array<string, mixed>  $manifestSummary
     */
    public function __construct(
        public readonly ?string $stagedDumpRelativePath,
        public readonly int $stagedAttachmentsCount,
        public readonly array $manifestSummary,
        public readonly int $declaredTotalBytes,
        public readonly int $extractedTotalBytes,
    ) {
    }
}
