<?php

namespace App\Services\Restore;

use App\Services\Audit\BackupRestore\RestoreAuditRecorder;
use App\Services\Restore\Contracts\ArtisanCommandRunner;
use App\Services\Restore\Contracts\RestoreDatabaseConnectionResetter;
use App\Services\Restore\Contracts\RestoreEphemeralTableCleaner;
use App\Services\Restore\Contracts\RestoreMetadataReconstructor;
use App\Services\Restore\Exceptions\RestoreReconciliationException;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use RuntimeException;
use Throwable;

/**
 * OMS Task 7C.5 — the only place a successful `mysql` database import
 * (DatabaseRestorer) is turned back into a trustworthy, migrated,
 * permission-synced application state with authoritative backup/restore
 * audit rows. Must run only AFTER the import succeeds, and only while the
 * future orchestrator (not built here) still holds the exclusive
 * BackupSubsystemLock and maintenance mode — this class acquires neither,
 * and never lifts maintenance mode itself.
 *
 * Deliberately NOT one big DB::transaction(): `migrate --force` may commit
 * implicitly per-migration, `oms:sync-permissions`/`permission:cache-reset`/
 * `queue:restart` may use their own connections, and the schema itself
 * changes mid-run — wrapping all of it in one outer transaction would be
 * both incorrect and impossible to roll back meaningfully. Each of the
 * seven required steps either fully succeeds or this method throws
 * immediately with a distinct, sanitized reasonCode — nothing after a
 * failed step ever runs, and a caller can always tell exactly which step
 * failed without any raw exception text leaking out.
 *
 * OMS Task 9B.6 adds an eighth, deliberately NON-FATAL step after those
 * seven: replaying this restore's own audit lifecycle into the restored
 * `audit_events` table (see the step's own comment in reconcile() and
 * App\Services\Audit\BackupRestore\RestoreAuditRecorder). It is not one of
 * the "seven required steps" above and can never make reconciliation fail —
 * an audit-write problem must not downgrade a genuinely successful restore.
 *
 * OMS Task 7C.5 correction pass — database-connection policy: this class
 * never accepts a caller-supplied connection name. It always resets
 * `config('database.default')` — the exact same connection `migrate`,
 * every Eloquent model, and every `DB::table()` call in the metadata/
 * ephemeral steps below implicitly target — so reconciliation can never be
 * pointed at a different physical database than the one those steps
 * actually operate on. `DatabaseRestorer` independently fails closed if the
 * connection it's about to `mysql`-import into isn't that same default
 * connection (see its own docblock) — the two halves of a restore can
 * never silently disagree about which database is being restored.
 */
final class RestoreReconciler
{
    private readonly RestoreAuditRecorder $auditRecorder;

    public function __construct(
        private readonly RestoreDatabaseConnectionResetter $connectionResetter,
        private readonly ArtisanCommandRunner $artisan,
        private readonly RestoreMetadataReconstructor $metadataReconstructor,
        private readonly RestoreEphemeralTableCleaner $ephemeralTableCleaner,
        ?RestoreAuditRecorder $auditRecorder = null,
    ) {
        $this->auditRecorder = $auditRecorder ?? app(RestoreAuditRecorder::class);
    }

    /**
     * OMS Task 7C.7 hardening pass — $onTick, when given, is invoked once
     * immediately after each of the seven steps below succeeds (never
     * mid-step — each step here is expected to be individually bounded/
     * short) so a restore's signed progress heartbeat can stay alive across
     * the whole reconciliation sequence even if one or more steps
     * (`migrate --force` especially) takes a while. OMS Task 9B.6's non-fatal
     * audit-replay step gets the same tick treatment, for the same reason.
     *
     * @throws RestoreReconciliationException
     */
    public function reconcile(
        BackupOperationSnapshot $sourceBackup,
        BackupOperationSnapshot $safetyBackup,
        RestoreOperationSnapshot $restoreOperation,
        ?callable $onTick = null,
    ): void {
        $this->step(
            fn () => $this->connectionResetter->reset((string) config('database.default')),
            RestoreReconciliationException::connectionResetFailed(...),
        );
        if ($onTick !== null) { $onTick(); }

        $this->step(
            fn () => $this->runArtisan('migrate', ['--force' => true]),
            RestoreReconciliationException::migrationFailed(...),
        );
        if ($onTick !== null) { $onTick(); }

        $this->step(
            fn () => $this->runArtisan('oms:sync-permissions'),
            RestoreReconciliationException::permissionSyncFailed(...),
        );
        if ($onTick !== null) { $onTick(); }

        $this->step(
            fn () => $this->runArtisan('permission:cache-reset'),
            RestoreReconciliationException::permissionCacheResetFailed(...),
        );
        if ($onTick !== null) { $onTick(); }

        $this->step(
            fn () => $this->metadataReconstructor->reconstruct($sourceBackup, $safetyBackup, $restoreOperation),
            RestoreReconciliationException::metadataReconstructionFailed(...),
        );
        if ($onTick !== null) { $onTick(); }

        $this->step(
            fn () => $this->ephemeralTableCleaner->clean(),
            RestoreReconciliationException::ephemeralCleanupFailed(...),
        );
        if ($onTick !== null) { $onTick(); }

        $this->step(
            fn () => $this->runArtisan('queue:restart'),
            RestoreReconciliationException::queueRestartFailed(...),
        );
        if ($onTick !== null) { $onTick(); }

        // ---- OMS Task 9B.6 — restore audit replay (LAST, and never fatal) ---
        //
        // The `mysql` import that ran before this method replaced the whole
        // `audit_events` table with the source backup's own copy of it, so the
        // `restore_requested`/`restore_started` rows written before the import
        // are gone. This step writes the authoritative restore lifecycle back
        // into the RESTORED table, from the bounded, signed progress journal
        // these very snapshots were read out of — no second log, no new
        // external state, no schema change.
        //
        // Position is deliberate: it runs only after every step above has
        // genuinely succeeded, so `migrate --force` has already restored this
        // table's schema, `oms:sync-permissions` has run (and records nothing
        // itself, so it cannot duplicate a restore event), and
        // RestoreMetadataUpserter has rebuilt the three `backup_operations`
        // rows these events reference. RestoreEphemeralTablePolicy never
        // touches `audit_events` (see its docblock — only the backups queue,
        // cache, cache_locks and sessions), so nothing here can be truncated
        // afterwards.
        //
        // It is deliberately NOT wrapped in step(): unlike the seven steps
        // above, an audit-write problem must never fail reconciliation, because
        // that would downgrade a genuinely successful restore to RestorePartial
        // — a strictly less truthful outcome. The recorder is BestEffort and
        // swallows its own failures, and RestoreTerminalResultWriter attempts
        // the same idempotent replay again afterwards, so a transient failure
        // here is not the only chance to record it.
        $this->auditRecorder->replayAfterDatabaseReplacement($sourceBackup, $safetyBackup, $restoreOperation);
        if ($onTick !== null) { $onTick(); }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runArtisan(string $command, array $parameters = []): void
    {
        if ($this->artisan->run($command, $parameters) !== 0) {
            throw new RuntimeException("Artisan command [{$command}] exited with a non-zero status.");
        }
    }

    /**
     * @param  callable(): void  $step
     * @param  callable(?Throwable): RestoreReconciliationException  $onFailure
     */
    private function step(callable $step, callable $onFailure): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            throw $onFailure($e);
        }
    }
}
