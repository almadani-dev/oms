<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\RestoreEphemeralTableCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Task 7C.5 — the approved selective ephemeral-table policy, applied
 * once after metadata reconstruction and before `queue:restart`. Every
 * table name is read from configuration, never hardcoded, and every table
 * is checked for existence before use — a table this app happens not to
 * have configured/migrated is silently skipped, never an error.
 *
 * What is preserved, and why: `failed_jobs` and `notifications` are
 * business/audit history unrelated to which queue a restore happened to
 * run on — deleting them would erase legitimate records that have nothing
 * to do with the restore itself. `job_batches` is preserved for the same
 * reason, and because no policy exists yet to safely identify a
 * backup-only batch (a future, explicitly-scoped addition, not this one).
 * Every OTHER queue's jobs are preserved too — only the configured backups
 * queue's own rows are ever removed.
 *
 * OMS Task 7C.5 correction pass (approved v1 behavior): ALL rows on the
 * backups queue are deleted, regardless of `reserved_at` — not just
 * unreserved ones. A reserved-but-not-yet-completed row left behind would
 * become eligible for redelivery once its `retry_after` window elapses
 * (Laravel's database queue driver's own reservation-timeout mechanism),
 * which could replay a pre-restore backup/verify/retention job against the
 * just-restored system — exactly the ambiguous stale-transport-work risk
 * this policy exists to close. The backups queue carries only backup/
 * restore transport work in this architecture (see `config('oms.backup.queue')`
 * and every dispatch site) and is intentionally treated as fully disposable
 * during restore reconciliation, unlike every other (business-domain)
 * queue, which is left completely untouched.
 *
 * What is cleared, and why: `cache`/`cache_locks` may hold pre-restore
 * application state that no longer matches the restored database.
 * `sessions` is cleared so every user must authenticate again after a
 * restore — a stale session pointing at pre-restore identity/permission
 * state must never silently continue to work.
 */
final class RestoreEphemeralTablePolicy implements RestoreEphemeralTableCleaner
{
    public function clean(): void
    {
        $this->clearBackupsQueueJobs();
        $this->truncateIfExists($this->cacheTable());
        $this->truncateIfExists($this->cacheLocksTable());
        $this->truncateIfExists((string) config('session.table', 'sessions'));
    }

    /**
     * Every row on the exact configured backups queue — reserved, delayed,
     * or plain pending — is removed; every other queue is left completely
     * untouched. See the class docblock for why "reserved" is deliberately
     * not exempted here.
     */
    private function clearBackupsQueueJobs(): void
    {
        $table = (string) config('queue.connections.database.table', 'jobs');
        $backupsQueue = (string) config('oms.backup.queue', 'backups');

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->where('queue', $backupsQueue)->delete();
    }

    private function cacheTable(): string
    {
        return (string) config('cache.stores.database.table', 'cache');
    }

    /**
     * Mirrors Illuminate\Cache\DatabaseStore's own default: an unconfigured
     * lock_table falls back to "{$table}_locks", never a hardcoded literal.
     */
    private function cacheLocksTable(): string
    {
        $configured = config('cache.stores.database.lock_table');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->cacheTable().'_locks';
    }

    private function truncateIfExists(string $table): void
    {
        if ($table === '' || ! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->truncate();
    }
}
