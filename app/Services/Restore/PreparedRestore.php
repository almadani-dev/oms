<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;

/**
 * OMS Task 7C.3 — the bounded, immutable result of a fully successful
 * RestoreArchivePreparer::prepare() call: everything a later orchestration
 * phase (out of scope here) needs to continue, and nothing else.
 *
 * Deliberately excludes: encryption keys, the database password, any raw
 * command string, the full per-file attachment manifest list, and any open
 * stream/resource. The workspace is exposed only through the
 * RestoreWorkspace abstraction (safe path resolution), never as a raw,
 * caller-manipulable string.
 */
final class PreparedRestore
{
    /**
     * @param  array<string, mixed>  $manifestSummary  bounded — see
     *                                                  RestoreArchiveExtractor::buildManifestSummary()
     */
    public function __construct(
        public readonly string $restoreUuid,
        public readonly BackupScope $selectedScope,
        public readonly RestoreWorkspace $workspace,
        public readonly string $sourceBackupUuid,
        public readonly ?string $stagedDumpRelativePath,
        public readonly int $stagedAttachmentsCount,
        public readonly array $manifestSummary,
        public readonly int $declaredTotalBytes,
        public readonly int $extractedTotalBytes,
    ) {
    }
}
