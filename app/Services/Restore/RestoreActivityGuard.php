<?php

namespace App\Services\Restore;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use Illuminate\Support\Facades\Storage;

/**
 * OMS Task 7C.2 — the dual restore-activity gate: a restore is considered
 * active when EITHER a non-terminal `type = restore` BackupOperation row
 * exists in the database, OR a valid signed non-terminal progress file
 * exists on the private restores disk — OR logic, deliberately not
 * DB-only. This is required, not defensive extra: the database itself can
 * be mid-replacement during a real restore (see the Task 7C delta plan's
 * post-import reconciliation design), so the DB row alone cannot be
 * trusted to reflect reality during exactly the window this guard matters
 * most.
 *
 * A progress file that fails signature/schema/UUID/content validation is
 * never silently treated as "no restore running" — it surfaces as
 * TamperedOrInvalid, which blocks a new restore exactly like a genuinely
 * active one (RestoreActivityState::blocksNewRestore()). Progress files
 * are safety state here, never an authorization credential — this guard
 * only ever answers "should a new restore be refused," never "is this
 * request allowed to act."
 *
 * Deliberately has no acknowledgment/recovery method — clearing a stale or
 * tampered state is a later phase's explicit, audited Super-Admin action,
 * never something this guard performs itself.
 */
final class RestoreActivityGuard
{
    public function __construct(
        private readonly RestoreProgressReader $reader = new RestoreProgressReader(),
    ) {
    }

    /**
     * OMS Task 7C.3: $excludeRestoreUuid lets a restore's own preflight
     * check ignore its own (not-yet-active, or already-claimed) UUID while
     * still blocking on any OTHER active/tampered restore — required for a
     * later orchestration phase to re-run preflight for the very restore it
     * is about to continue without being blocked by itself. Passing null
     * (the default, and every existing caller) preserves the original
     * "scan everything" behavior exactly.
     */
    public function isActive(?string $excludeRestoreUuid = null): RestoreActivityState
    {
        $progressState = $this->scanForNonTerminalProgress($excludeRestoreUuid);

        if ($progressState === RestoreActivityState::TamperedOrInvalid) {
            return RestoreActivityState::TamperedOrInvalid;
        }

        if ($progressState === RestoreActivityState::Active || $this->hasActiveDbRow($excludeRestoreUuid)) {
            return RestoreActivityState::Active;
        }

        return RestoreActivityState::Inactive;
    }

    /**
     * OMS Task 7C.4 correction pass — the gate ordinary backup-subsystem
     * operations (create/retention/verify/delete/download) must consult
     * while still holding BackupSubsystemLock::acquireShared(), closing the
     * parent-launch-to-child-lock-acquisition handoff gap: between the
     * moment RestoreLaunchService claims a row and writes its initial
     * progress file, and the moment the detached `oms:restore` child
     * obtains its own lifetime exclusive lock, no ordinary shared-lock
     * operation may start.
     *
     * Deliberately NOT the same test as isActive()/blocksNewRestore():
     * a merely `Queued` restore row (no progress file yet — nothing has
     * been launched) must never block an ordinary operation, so only a
     * genuinely claimed/running restore (`status = Restoring`) counts here,
     * not every status BackupStatus::isActive() would otherwise include.
     * The progress-file half is unchanged from isActive()'s own scan: a
     * valid non-terminal file blocks, and a malformed/unsigned/tampered one
     * blocks for manual review (TamperedOrInvalid) exactly the same way —
     * "fail closed when restore activity cannot be safely determined."
     */
    public function blocksOrdinaryOperations(): bool
    {
        $progressState = $this->scanForNonTerminalProgress(null);

        if ($progressState === RestoreActivityState::TamperedOrInvalid) {
            return true;
        }

        return $progressState === RestoreActivityState::Active || $this->hasClaimedDbRow();
    }

    private function hasClaimedDbRow(): bool
    {
        return BackupOperation::query()
            ->where('type', BackupType::Restore->value)
            ->where('status', BackupStatus::Restoring->value)
            ->exists();
    }

    private function hasActiveDbRow(?string $excludeRestoreUuid): bool
    {
        $activeStatusValues = array_map(
            static fn (BackupStatus $status): string => $status->value,
            array_values(array_filter(BackupStatus::cases(), static fn (BackupStatus $status): bool => $status->isActive())),
        );

        return BackupOperation::query()
            ->where('type', BackupType::Restore->value)
            ->whereIn('status', $activeStatusValues)
            ->when($excludeRestoreUuid !== null, fn ($query) => $query->where('uuid', '!=', $excludeRestoreUuid))
            ->exists();
    }

    /**
     * Canonical UUID v-agnostic shape — the exact form RestoreProgressWriter
     * names every restore directory (a restore's own BackupOperation::uuid).
     */
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /**
     * Deterministic, bounded search: only ever lists the immediate
     * subdirectories of the restores disk root (one per restore UUID, per
     * RestoreProgressWriter's own layout) and reads at most one
     * progress.json per directory — never an unbounded or recursive scan.
     *
     * Only directories whose name is a valid UUID are ever inspected: a
     * dot/system directory (`.locks`, the subsystem-lock file's home) or
     * any other non-UUID directory is skipped WITHOUT being read, so an
     * unrelated stray directory can never masquerade as a restore or
     * produce a false permanent "tampered" block. A malformed/tampered
     * progress.json inside a genuine restore-UUID directory still surfaces
     * as TamperedOrInvalid (blocking for manual review) — the distinction
     * being: we only ever treat a read failure as "tampered" for a
     * directory that legitimately claims to be a restore in the first
     * place. RestoreProgressReader independently re-checks that the file's
     * own restore_uuid matches the containing directory's UUID, so a valid
     * signature lifted from one restore's file into another restore's
     * directory is rejected too.
     *
     * $excludeRestoreUuid (OMS Task 7C.3) never skips validating the
     * excluded directory's own progress file — it only ever suppresses
     * treating a VALID, non-terminal snapshot for that exact UUID as
     * "active." A malformed/unsigned/invalid-signature/UUID-mismatched
     * file is still read, still fails the same way, and still marks
     * $sawInvalid regardless of exclusion — exclusion can never turn a
     * corrupt/untrusted state into "safe." This is deliberately NOT a
     * `continue` before the read: excluding a directory from the read
     * entirely would let a tampered file for the excluded UUID slip past
     * undetected, which is exactly the "exclusion turns corrupt state
     * into safe" failure this guard must never allow.
     */
    private function scanForNonTerminalProgress(?string $excludeRestoreUuid): RestoreActivityState
    {
        $disk = Storage::disk((string) config('oms.backup.restore.disk', 'restores'));
        $sawInvalid = false;

        foreach ($disk->directories() as $directory) {
            $uuid = basename($directory);

            if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
                continue;
            }

            if (! $disk->exists($directory.'/progress.json')) {
                continue;
            }

            try {
                $snapshot = $this->reader->read($uuid);
            } catch (RestoreProgressIntegrityException) {
                $sawInvalid = true;

                continue;
            }

            $isExcluded = $excludeRestoreUuid !== null && hash_equals($excludeRestoreUuid, $uuid);

            if (! $snapshot->isTerminal() && ! $isExcluded) {
                return RestoreActivityState::Active;
            }
        }

        return $sawInvalid ? RestoreActivityState::TamperedOrInvalid : RestoreActivityState::Inactive;
    }
}
