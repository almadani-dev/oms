<?php

namespace App\Console\Commands;

use App\Models\BackupOperation;
use App\Notifications\BackupNotificationEvent;
use App\Services\Backup\BackupNotifier;
use App\Services\Restore\RestoreStaleDetector;
use App\Services\Restore\StaleRestoreObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OMS Task 7C.7 — DETECTION ONLY, run on a schedule (see bootstrap/app.php).
 * Logs and, where a database row is still available, sends a bounded
 * persistent notification for every stale/ambiguous restore
 * RestoreStaleDetector reports. Never retries, resumes, relaunches,
 * acquires any lock, mutates a restore's status, rolls anything back, or
 * touches maintenance mode — see RestoreStaleDetector's own docblock.
 *
 * Notification spam is bounded via a plain Cache cooldown key per
 * (restore UUID, reason code) — deliberately not a new database column or
 * table: a stale restore is not expected to resolve itself between runs, so
 * the exact same observation would otherwise fire every single minute this
 * command runs. The cooldown is reset the moment the restore reaches a
 * terminal state (its progress file becomes terminal, or its DB row leaves
 * `Restoring`), since detect() then simply stops reporting it at all.
 */
class RestoreWatchdogCommand extends Command
{
    protected $signature = 'oms:restore-watchdog';

    protected $description = 'Detect stale or ambiguous restore operations and report them (never mutates or resumes anything).';

    /**
     * The entire body is wrapped so this purely-advisory, scheduled command
     * can never surface as an uncontrolled scheduler exception or interfere
     * with a real restore in progress — an unexpected failure here is
     * logged and swallowed, never rethrown. RestoreStaleDetector itself
     * already degrades gracefully (never throws) when the database is
     * unavailable; this outer guard is defense in depth for anything else
     * (e.g. a notification/cache failure this method's own inner
     * try/catches somehow didn't anticipate).
     */
    public function handle(RestoreStaleDetector $detector, BackupNotifier $notifier): int
    {
        try {
            $cooldownMinutes = max(1, (int) config('oms.backup.restore.watchdog_notification_cooldown_minutes', 60));

            foreach ($detector->detect() as $observation) {
                $this->reportObservation($observation, $notifier, $cooldownMinutes);
            }
        } catch (\Throwable $e) {
            Log::warning('oms:restore-watchdog encountered an unexpected error; this is monitoring-only and never affects any restore.', [
                'exception' => $e::class,
            ]);
        }

        return self::SUCCESS;
    }

    private function reportObservation(StaleRestoreObservation $observation, BackupNotifier $notifier, int $cooldownMinutes): void
    {
        $cacheKey = "restore-watchdog-notified:{$observation->restoreUuid}:{$observation->reasonCode}";

        // Cache is best-effort, monitoring-only bookkeeping (see class
        // docblock) — if the cache store itself is unavailable (e.g. the
        // database-backed cache driver during a database-affecting
        // restore), treat it as "not yet notified" rather than let the
        // failure skip the log/notification entirely.
        if ($this->cacheHasBestEffort($cacheKey)) {
            return;
        }

        Log::warning('Stale or ambiguous restore operation detected.', [
            'restore_uuid' => $observation->restoreUuid,
            'reason_code' => $observation->reasonCode,
            'phase' => $observation->phase,
            'heartbeat_age_minutes' => $observation->heartbeatAgeMinutes,
        ]);

        $this->notifyBestEffort($observation, $notifier);

        $this->cachePutBestEffort($cacheKey, $cooldownMinutes);
    }

    private function cacheHasBestEffort(string $cacheKey): bool
    {
        try {
            return Cache::has($cacheKey);
        } catch (\Throwable) {
            return false;
        }
    }

    private function cachePutBestEffort(string $cacheKey, int $cooldownMinutes): void
    {
        try {
            Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));
        } catch (\Throwable) {
            // Best-effort only — a lost cooldown write means, at worst, a
            // repeated notification next run; never a correctness issue.
        }
    }

    private function notifyBestEffort(StaleRestoreObservation $observation, BackupNotifier $notifier): void
    {
        try {
            $row = BackupOperation::query()->where('uuid', $observation->restoreUuid)->first();

            if ($row === null) {
                // The signed progress file logged above already carries the
                // observation — nothing to attach a persistent DB
                // notification to.
                return;
            }

            $notifier->notify($row, BackupNotificationEvent::RestoreStale, 'This restore operation appears stale or requires manual review.');
        } catch (\Throwable) {
            // Best-effort only — a notification failure must never affect
            // the log entry already written above.
        }
    }
}
