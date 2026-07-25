<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Storage;

/**
 * OMS Task 7C.2 — the restore subsystem's authoritative, non-TTL mutex: a
 * real OS `flock()` on a fixed file under the private restores disk
 * ({restores disk}/.locks/subsystem.lock), never a database-backed Cache
 * lock. This is deliberate: `config('cache.default')` is `database` in this
 * app (production and local — see bootstrap/app.php's scheduler comment),
 * so a Cache lock's own backing row lives in the very database a restore
 * replaces and cannot be trusted to survive the operation it is supposed to
 * serialize. A flock() has no such dependency and is released automatically
 * by the OS if the holding process dies, without ever silently letting a
 * new operation start while the crashed one might still be mid-write (see
 * BackupSubsystemLockHandle's docblock and RestoreActivityGuard, which is
 * what actually decides whether a *new* restore may start).
 *
 * Acquisition is always non-blocking (LOCK_NB) — every caller fails fast
 * and reports "already locked" rather than waiting, matching the approved
 * lock-order design: restore acquires exclusive() once, for its whole
 * lifetime, and nothing else; every ordinary backup-subsystem operation
 * acquires shared() for its whole duration, then whatever existing
 * Cache/per-backup lock it already used. Multiple shared holders may
 * coexist; a shared and an exclusive holder may never coexist.
 */
final class BackupSubsystemLock
{
    private readonly string $lockPath;

    public function __construct(?string $lockPath = null)
    {
        $this->lockPath = $lockPath ?? $this->resolveDefaultLockPath();
    }

    public function acquireExclusive(): ?BackupSubsystemLockHandle
    {
        return $this->acquire(LockMode::Exclusive);
    }

    public function acquireShared(): ?BackupSubsystemLockHandle
    {
        return $this->acquire(LockMode::Shared);
    }

    /**
     * The single place a handle is cross-checked before an internal
     * lock-bypass path (BackupCreationOrchestrator::runWithLockAlreadyHeld())
     * is allowed to trust it: it must still be live, it must be the
     * expected mode, and it must have been issued for *this* lock's own
     * canonical path — not merely be an instance of the right class. A
     * dead, released, wrong-mode, or foreign-path handle is rejected.
     */
    public function validateHandle(BackupSubsystemLockHandle $handle, LockMode $expectedMode): bool
    {
        return $handle->mode() === $expectedMode
            && $handle->belongsToPath($this->lockPath)
            && $handle->isLive();
    }

    private function acquire(LockMode $mode): ?BackupSubsystemLockHandle
    {
        $this->ensureLockFileExists();

        $resource = @fopen($this->lockPath, 'c');

        if ($resource === false) {
            return null;
        }

        $flag = $mode === LockMode::Exclusive ? LOCK_EX : LOCK_SH;

        if (! flock($resource, $flag | LOCK_NB)) {
            fclose($resource);

            return null;
        }

        return new BackupSubsystemLockHandle($resource, $mode, $this->lockPath);
    }

    /**
     * Creates the `.locks` directory and the lock file itself with
     * restrictive permissions if either is missing — safe to call on every
     * acquisition attempt (idempotent, never truncates an existing file).
     */
    private function ensureLockFileExists(): void
    {
        $directory = dirname($this->lockPath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        if (! is_file($this->lockPath)) {
            $handle = @fopen($this->lockPath, 'c');

            if ($handle !== false) {
                fclose($handle);
                @chmod($this->lockPath, 0600);
            }
        }
    }

    private function resolveDefaultLockPath(): string
    {
        $disk = (string) config('oms.backup.restore.disk', 'restores');

        return rtrim(Storage::disk($disk)->path('.locks'), '/\\').DIRECTORY_SEPARATOR.'subsystem.lock';
    }
}
