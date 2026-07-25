<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\Contracts\BackupArchiveContentVerifier;
use App\Services\Backup\SecretstreamEnvelope;
use App\Services\Restore\Exceptions\RestoreArchiveExtractionException;
use App\Services\Restore\Exceptions\RestoreArchivePreparationException;
use App\Services\Restore\Exceptions\RestorePreflightException;
use App\Services\Restore\Exceptions\RestoreInsufficientDiskSpaceException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * OMS Task 7C.3 — orchestrates (only) preflight -> private workspace ->
 * decrypt -> full content verification -> safe scope-limited extraction.
 * Never applies the mutable AttachmentStorageService allowlist or the
 * fixed executable/script denylist (both explicitly deferred to Task
 * 7C.6). Never runs mysql, never enables maintenance mode, never touches
 * the live database or the live 'attachments' disk.
 *
 * Required order (enforced by this method's own control flow, not by
 * caller discipline):
 *   1. RestorePreflightChecker::check() — non-destructive validation only.
 *   2. RestoreWorkspace::prepare() — creates the private workspace only
 *      after preflight passes.
 *   3. SecretstreamEnvelope::decryptFile() (existing crypto code, reused
 *      unchanged) using BackupKeyRing::resolve() as the key resolver.
 *   4. BackupArchiveContentVerifier::verify() (existing, shared with
 *      backup creation/re-verification — reused unchanged) against the
 *      decrypted plaintext ZIP.
 *   5. Only on full verification success: RestoreArchiveExtractor::extract().
 *
 * On ANY failure in steps 2-5, the workspace's plaintext is cleaned up
 * best-effort and a sanitized restore-specific exception is thrown — the
 * source backup's own encrypted archive is never modified. On success, the
 * workspace (and everything staged inside it) is deliberately left in
 * place: cleanup responsibility transfers to the later orchestrator.
 */
final class RestoreArchivePreparer
{
    public function __construct(
        private readonly RestorePreflightChecker $preflightChecker,
        private readonly BackupKeyRing $keyRing,
        private readonly SecretstreamEnvelope $envelope,
        private readonly BackupArchiveContentVerifier $contentVerifier,
        private readonly RestoreArchiveExtractor $extractor,
    ) {
    }

    /**
     * @throws RestorePreflightException
     * @throws RestoreInsufficientDiskSpaceException
     * @throws RestoreArchivePreparationException
     * @throws RestoreArchiveExtractionException
     */
    public function prepare(string $sourceBackupUuid, BackupScope $selectedScope, string $restoreUuid): PreparedRestore
    {
        // Preflight runs, and must pass, BEFORE the workspace exists at
        // all — including the disk-space check, which is why insufficient
        // space is always reported before any workspace directory is
        // created.
        $preflight = $this->preflightChecker->check($sourceBackupUuid, $selectedScope, $restoreUuid);

        $workspace = new RestoreWorkspace($restoreUuid);

        try {
            $workspace->prepare();
        } catch (Throwable) {
            throw RestoreArchivePreparationException::workspaceUnavailable();
        }

        try {
            $sourceDisk = Storage::disk($preflight->sourceBackup->disk);
            $encryptedAbsolutePath = $sourceDisk->path((string) $preflight->sourceBackup->stored_path);
            $decryptedPath = $workspace->decryptedArchivePath();

            try {
                $this->envelope->decryptFile(
                    $encryptedAbsolutePath,
                    $decryptedPath,
                    fn (string $keyId): string => $this->keyRing->resolve($keyId),
                );
            } catch (Throwable) {
                throw RestoreArchivePreparationException::decryptionFailed();
            }

            try {
                $manifest = $this->contentVerifier->verify(
                    $decryptedPath,
                    $preflight->sourceBackup->uuid,
                    $preflight->sourceBackup->type->value,
                    $preflight->sourceBackup->scope->value,
                );
            } catch (Throwable) {
                throw RestoreArchivePreparationException::verificationFailed();
            }

            $extraction = $this->extractor->extract($decryptedPath, $manifest, $selectedScope, $workspace);

            return new PreparedRestore(
                restoreUuid: $restoreUuid,
                selectedScope: $selectedScope,
                workspace: $workspace,
                sourceBackupUuid: $preflight->sourceBackup->uuid,
                stagedDumpRelativePath: $extraction->stagedDumpRelativePath,
                stagedAttachmentsCount: $extraction->stagedAttachmentsCount,
                manifestSummary: $extraction->manifestSummary,
                declaredTotalBytes: $extraction->declaredTotalBytes,
                extractedTotalBytes: $extraction->extractedTotalBytes,
            );
        } catch (RestoreArchivePreparationException|RestoreArchiveExtractionException $e) {
            $this->cleanupBestEffort($workspace);

            throw $e;
        } catch (Throwable) {
            $this->cleanupBestEffort($workspace);

            throw RestoreArchivePreparationException::unexpectedFailure();
        }
    }

    /**
     * Best-effort by contract: a workspace-cleanup failure must never mask
     * the original preparation/extraction failure that triggered it — the
     * caller always sees the real reason the restore was rejected, not a
     * secondary cleanup error.
     */
    private function cleanupBestEffort(RestoreWorkspace $workspace): void
    {
        try {
            $workspace->cleanup();
        } catch (Throwable) {
            // Intentionally swallowed — see docblock above.
        }
    }
}
