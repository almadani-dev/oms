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

    public function isActive(): RestoreActivityState
    {
        $progressState = $this->scanForNonTerminalProgress();

        if ($progressState === RestoreActivityState::TamperedOrInvalid) {
            return RestoreActivityState::TamperedOrInvalid;
        }

        if ($progressState === RestoreActivityState::Active || $this->hasActiveDbRow()) {
            return RestoreActivityState::Active;
        }

        return RestoreActivityState::Inactive;
    }

    private function hasActiveDbRow(): bool
    {
        $activeStatusValues = array_map(
            static fn (BackupStatus $status): string => $status->value,
            array_values(array_filter(BackupStatus::cases(), static fn (BackupStatus $status): bool => $status->isActive())),
        );

        return BackupOperation::query()
            ->where('type', BackupType::Restore->value)
            ->whereIn('status', $activeStatusValues)
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
     */
    private function scanForNonTerminalProgress(): RestoreActivityState
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

            if (! $snapshot->isTerminal()) {
                return RestoreActivityState::Active;
            }
        }

        return $sawInvalid ? RestoreActivityState::TamperedOrInvalid : RestoreActivityState::Inactive;
    }
}
