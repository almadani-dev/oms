<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;

/**
 * OMS Task 7C.3 — the bounded, immutable result of a fully successful
 * RestoreArchivePreparer::prepare() call: everything a later orchestration
 * phase (out of scope here) needs to continue, and nothing else.
 *
 * Deliberately excludes: encryption keys, the database password, any raw
 * command string, and any open stream/resource. The workspace is exposed
 * only through the RestoreWorkspace abstraction (safe path resolution),
 * never as a raw, caller-manipulable string.
 *
 * OMS Task 7C.7 correction pass: $attachmentManifestFiles/
 * $attachmentsTotalSizeBytes carry forward the verified archive manifest's
 * own `attachments.files`/`attachments.total_size_bytes` (empty/zero when
 * the selected scope excludes files) — the exact, and only, source
 * RestoreOrchestrator is permitted to build a RestoreAttachmentManifest
 * from before attachment activation (see RestoreAttachmentManifest's own
 * docblock). This is raw manifest data, already fully authenticated by
 * BackupArchiveContentVerifier before RestoreArchivePreparer ever staged a
 * single file — never a second, independent trust source, and never
 * accepted from the UI or from progress.json.
 */
final class PreparedRestore
{
    /**
     * @param  array<string, mixed>  $manifestSummary  bounded — see
     *                                                  RestoreArchiveExtractor::buildManifestSummary()
     * @param  array<int, array{path: string, sha256: string, size: int}>  $attachmentManifestFiles
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
        public readonly array $attachmentManifestFiles = [],
        public readonly int $attachmentsTotalSizeBytes = 0,
    ) {
    }
}
