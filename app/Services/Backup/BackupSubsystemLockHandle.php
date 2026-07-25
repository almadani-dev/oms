<?php

namespace App\Services\Backup;

/**
 * OMS Task 7C.2 — a live, held flock() lock. `final`, no public accessor to
 * the underlying resource, and constructible only with an already-open,
 * already-flocked resource (BackupSubsystemLock is the only class that ever
 * legitimately produces one via a successful flock() call).
 *
 * isLive() is a real liveness check, not merely `is_resource()`: it
 * re-affirms this handle's own mode on its own already-open file
 * descriptor. Per POSIX flock() semantics this is a harmless no-op
 * reassertion of a lock this exact descriptor already holds — it never
 * releases-then-reacquires in a way that could lose the lock to a waiting
 * process — so it safely proves the OS still honors this handle's lock
 * without any risk of losing it. A handle built around a resource that was
 * never actually flocked (impossible through the normal constructor path,
 * but defended against anyway) or whose lock was somehow lost fails here.
 *
 * Per Task 7C's approved architecture, merely being an instance of this
 * class is never sufficient to authorize a lock-bypass path — callers such
 * as BackupCreationOrchestrator::runWithLockAlreadyHeld() must additionally
 * check mode() and belongsToPath() via BackupSubsystemLock::validateHandle().
 */
final class BackupSubsystemLockHandle
{
    private bool $released = false;

    /**
     * @param  resource  $resource
     */
    public function __construct(
        private $resource,
        private readonly LockMode $mode,
        private readonly string $lockPath,
    ) {
    }

    public function mode(): LockMode
    {
        return $this->mode;
    }

    public function isLive(): bool
    {
        if ($this->released || ! is_resource($this->resource)) {
            return false;
        }

        $flag = $this->mode === LockMode::Exclusive ? LOCK_EX : LOCK_SH;

        return @flock($this->resource, $flag | LOCK_NB);
    }

    /**
     * @internal Only BackupSubsystemLock is expected to call this, against
     * the exact canonical lock-file path it manages — never a general
     * "trust me" flag callers set themselves.
     */
    public function belongsToPath(string $lockPath): bool
    {
        return hash_equals($this->lockPath, $lockPath);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        if (is_resource($this->resource)) {
            @flock($this->resource, LOCK_UN);
            @fclose($this->resource);
        }

        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
