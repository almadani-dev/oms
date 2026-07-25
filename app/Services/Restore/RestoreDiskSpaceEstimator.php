<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Models\BackupOperation;
use App\Services\Restore\Contracts\CurrentDatabaseSizeEstimator;
use Illuminate\Support\Facades\Storage;

/**
 * OMS Task 7C.3 — a conservative, deterministic, itemized disk-space
 * estimate for a restore's private workspace. Deliberately NOT a flat
 * "3 x encrypted archive size" multiplier — every summand below corresponds
 * to a real file (or set of files) that can legitimately coexist on disk at
 * the same time, so the total is itemized and still generously conservative
 * wherever an exact figure isn't available.
 *
 * CRITICAL correctness point (corrected after initial review): the
 * mandatory pre-restore safety backup is always FULL — it backs up the
 * entire current live system regardless of which scope (database/files/
 * full) was SELECTED for the restore itself. So the safety-backup terms
 * below are never conditioned on the selected restore scope; only the
 * source-side terms are.
 *
 * Final itemized formula (all in bytes):
 *
 *   A. Source-restore workspace requirements (selected scope only)
 *   ------------------------------------------------------------
 *   1. source decrypted archive        = original_size_bytes (or fallback)
 *      — decryption always processes the WHOLE source archive regardless
 *      of the selected restore scope.
 *   2. selected source staging          = original_size_bytes (or fallback)
 *      — one combined term (never split per component, since the source
 *      manifest's dump/attachments split isn't available without opening
 *      it, which lightweight preflight must not do): exact for a `full`
 *      restore (dump + attachments together really do sum to this column),
 *      a safe upper bound for a database-only/files-only restore of a
 *      mixed-scope source.
 *
 *   B. Current FULL safety-backup requirements (always, any selected scope)
 *   ------------------------------------------------------------------------
 *   3. current safety DB dump           = CurrentDatabaseSizeEstimator
 *      (information_schema aggregate; conservative non-zero fallback when
 *      unavailable)
 *   4. current safety attachments       = real current size of the live
 *      'attachments' disk
 *   5. safety archive/encrypted candidate/verification workspace
 *      = 3 x (current DB dump + current attachments) — BackupCreationOrchestrator's
 *      own pipeline (traced directly) keeps archive.zip, the encrypted
 *      candidate, AND a temporary decrypted verification copy on disk
 *      simultaneously before the candidate is ever published, each one a
 *      full copy of the combined dump+attachments content.
 *   6. current attachment quarantine    = current attachments size, but
 *      ONLY when the selected restore scope includes files (only then does
 *      a later phase need to move the current live attachments aside
 *      before swapping in the restored ones).
 *
 *   C. Overhead and safety margins
 *   -------------------------------
 *   7. atomic temporary-write overhead  = fixed ATOMIC_WRITE_OVERHEAD_BYTES
 *
 *   required = max(
 *       ceil((1+2+3+4+5+6+7) * (1 + free_space_margin_percent/100)),
 *       min_free_space_reserve_bytes
 *   )
 *
 * Every term above has a distinct, named filesystem justification — no
 * value is blindly reused across unrelated categories.
 */
final class RestoreDiskSpaceEstimator
{
    /**
     * Fixed allowance for the transient extra copy an atomic rename-based
     * write keeps on disk briefly. Not sourced from config — this is an
     * internal implementation-overhead constant, not an operator-tunable
     * policy value like the margin percent or minimum reserve.
     */
    public const ATOMIC_WRITE_OVERHEAD_BYTES = 104_857_600; // 100 MiB

    /**
     * Conservative expansion factor applied to the encrypted archive's own
     * on-disk size when a backup row is somehow missing its declared
     * original_size_bytes (should not happen for a Completed backup, but
     * "unavailable => conservative estimate, never zero" per design).
     */
    private const FALLBACK_EXPANSION_FACTOR = 4;

    /**
     * BackupCreationOrchestrator's own working directory keeps
     * archive.zip, the encrypted candidate, and a temporary decrypted
     * verification copy on disk at once (traced directly against
     * execute()/verifyCandidate()) — three full copies of the combined
     * dump+attachments content, on top of the raw dump.sql itself.
     */
    private const SAFETY_PIPELINE_COPIES = 3;

    /**
     * Absolute floor under the current-database-size fallback, so a tiny
     * or missing source-backup size estimate can never make the "current
     * database" proxy implausibly small.
     */
    private const MIN_CURRENT_DATABASE_FALLBACK_BYTES = 52_428_800; // 50 MiB

    private readonly CurrentDatabaseSizeEstimator $currentDatabaseSizeEstimator;

    public function __construct(?CurrentDatabaseSizeEstimator $currentDatabaseSizeEstimator = null)
    {
        $this->currentDatabaseSizeEstimator = $currentDatabaseSizeEstimator ?? new MysqlInformationSchemaDatabaseSizeEstimator();
    }

    public function estimate(BackupOperation $sourceBackup, BackupScope $selectedScope, string $workspaceTargetPath): RestoreDiskSpaceEstimate
    {
        return new RestoreDiskSpaceEstimate(
            requiredBytes: $this->computeRequiredBytes($sourceBackup, $selectedScope),
            availableBytes: $this->freeBytesAt($workspaceTargetPath),
        );
    }

    /**
     * Pure, deterministic given its inputs (plus the current size of the
     * live 'attachments' disk, the injected current-database estimate, and
     * current config) — the part covered by "deterministic byte-estimation
     * tests, including margin and 1 GiB reserve".
     */
    public function computeRequiredBytes(BackupOperation $sourceBackup, BackupScope $selectedScope): int
    {
        $sourceOriginalBytes = $this->resolveSourceOriginalBytes($sourceBackup);

        // A. Source-restore workspace requirements (selected scope only).
        $sourceDecryptedArchiveBytes = $sourceOriginalBytes;
        $selectedSourceStagingBytes = $sourceOriginalBytes;

        // B. Current FULL safety-backup requirements — always, regardless
        // of the selected restore scope.
        $currentAttachmentsBytes = $this->currentLiveAttachmentsBytes();
        $currentDatabaseFallback = max($sourceOriginalBytes, self::MIN_CURRENT_DATABASE_FALLBACK_BYTES);
        $currentDatabaseBytes = $this->currentDatabaseSizeEstimator->estimateBytes($currentDatabaseFallback);

        $safetyPipelineWorkspaceBytes = ($currentDatabaseBytes + $currentAttachmentsBytes) * self::SAFETY_PIPELINE_COPIES;

        $attachmentQuarantineBytes = $selectedScope->includesFiles() ? $currentAttachmentsBytes : 0;

        // C. Overhead.
        $rawTotal = $sourceDecryptedArchiveBytes
            + $selectedSourceStagingBytes
            + $currentDatabaseBytes
            + $currentAttachmentsBytes
            + $safetyPipelineWorkspaceBytes
            + $attachmentQuarantineBytes
            + self::ATOMIC_WRITE_OVERHEAD_BYTES;

        $marginPercent = max(0, (int) config('oms.backup.restore.free_space_margin_percent', 20));
        $minReserve = max(0, (int) config('oms.backup.restore.min_free_space_reserve_bytes', 1_073_741_824));

        $withMargin = (int) ceil($rawTotal * (1 + $marginPercent / 100));

        return max($withMargin, $minReserve);
    }

    public function freeBytesAt(string $path): int
    {
        $existing = $path;

        while (! is_dir($existing)) {
            $parent = dirname($existing);

            if ($parent === $existing) {
                break;
            }

            $existing = $parent;
        }

        $free = @disk_free_space($existing);

        return $free === false ? 0 : (int) $free;
    }

    private function resolveSourceOriginalBytes(BackupOperation $sourceBackup): int
    {
        $declared = $sourceBackup->original_size_bytes;

        if (is_int($declared) && $declared > 0) {
            return $declared;
        }

        return max(0, (int) $sourceBackup->size_bytes) * self::FALLBACK_EXPANSION_FACTOR;
    }

    private function currentLiveAttachmentsBytes(): int
    {
        $disk = Storage::disk('attachments');
        $total = 0;

        foreach ($disk->allFiles() as $path) {
            $total += (int) $disk->size($path);
        }

        return $total;
    }
}
