<?php

namespace App\Services\Restore;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * OMS Task 7C.7 hardening pass — the single reusable throttled heartbeat
 * ticker used by RestoreOrchestrator for every long-running phase (mandatory
 * safety backup creation, source archive decrypt/verify/staging, the
 * streamed `mysql` import, post-import reconciliation, attachment
 * revalidation). One instance is created per phase, handed a `tick`able
 * callable into the long operation, and its `current()` snapshot is read
 * back afterward to continue the phase sequence with the freshest durable
 * `last_heartbeat_at`.
 *
 * Never a second progress protocol: every heartbeat write goes through the
 * existing signed, durable RestoreProgressWriter, and never touches
 * `phase`/`phase_history`/`result`/`restore_failed_phase`/`error_summary` —
 * only `last_heartbeat_at` changes, so a heartbeat write is indistinguishable
 * in shape from any other non-terminal progress write, just far more
 * frequent and cheap (no phase_history growth).
 *
 * Throttling and "now" are both `Carbon::now()`-based (never `time()`/
 * `microtime()`) so tests can drive a simulated long operation via
 * `Carbon::setTestNow()` time travel instead of a real `sleep()` — this is
 * also why RestoreStaleDetector reads timestamps the same way (see its own
 * docblock note).
 *
 * A write failure here is NEVER silently swallowed — it propagates to the
 * caller exactly like any other RestoreProgressWriteException, so the
 * existing pre-/post-database-import compensation rules (abort cleanly
 * before the import boundary, RestorePartial after it) apply identically to
 * a heartbeat write failure as to a phase-transition write failure. "Bounded
 * errors" here means the exception itself stays the existing sanitized
 * RestoreProgressWriteException — never a raw filesystem/credential detail —
 * not that the failure is hidden.
 */
final class RestoreHeartbeat
{
    private ?Carbon $lastBeatAt = null;

    public function __construct(
        private RestoreProgressSnapshot $progress,
        private readonly RestoreProgressWriter $writer = new RestoreProgressWriter(),
        private readonly int $intervalSeconds = 5,
    ) {
    }

    /**
     * Safe to call as often as the caller likes (once per file, once per
     * chunk, once per poll tick) — actually writes at most once per
     * configured interval. Never call this from inside a tight, genuinely
     * per-byte loop expecting it to no-op cheaply enough to matter; callers
     * still control their own call frequency (see each integration point's
     * own docblock for how often it invokes this).
     *
     * @throws Exceptions\RestoreProgressWriteException
     */
    public function tick(): void
    {
        $now = Carbon::now();

        if ($this->lastBeatAt !== null && $now->diffInSeconds($this->lastBeatAt, true) < $this->intervalSeconds) {
            return;
        }

        $this->lastBeatAt = $now;
        $this->progress = $this->beat($this->progress, $now);
    }

    public function current(): RestoreProgressSnapshot
    {
        return $this->progress;
    }

    /**
     * @return callable(): void a bound closure suitable for passing as a
     *                          tick callback into a lower-level service —
     *                          never exposes $this or the mutable snapshot
     *                          directly.
     */
    public function ticker(): callable
    {
        return function (): void {
            $this->tick();
        };
    }

    /**
     * Same as tick(), except a write failure is caught and logged rather
     * than propagated — for phases where the Task 7C.7 compensation matrix
     * has already decided a heartbeat failure must never itself abort the
     * surrounding operation (see RestoreOrchestrator's use of this for
     * substep-level ticks where the write failure would otherwise mask a
     * more meaningful surrounding failure). Prefer tick() by default; only
     * use this where the orchestrator explicitly documents why.
     */
    public function tickBestEffort(): void
    {
        try {
            $this->tick();
        } catch (Throwable) {
            // Intentionally swallowed — see docblock. The surrounding
            // long operation continues; the next successful tick (or the
            // phase-transition write immediately after it) will catch up.
        }
    }

    private function beat(RestoreProgressSnapshot $progress, Carbon $now): RestoreProgressSnapshot
    {
        $formatted = $now->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        $next = RestoreProgressSnapshot::create(
            restoreUuid: $progress->restoreUuid,
            requestedBy: $progress->requestedBy,
            requestedAt: $progress->requestedAt,
            reason: $progress->reason,
            scope: $progress->scope,
            sourceBackupUuid: $progress->sourceBackupUuid,
            preRestoreSafetyBackupUuid: $progress->preRestoreSafetyBackupUuid,
            phase: $progress->phase,
            phaseHistory: $progress->phaseHistory,
            lastHeartbeatAt: $formatted,
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
            reconciliationSnapshot: $progress->reconciliationSnapshot,
        );

        $this->writer->write($next);

        return $next;
    }
}
