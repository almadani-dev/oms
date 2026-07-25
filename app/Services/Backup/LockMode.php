<?php

namespace App\Services\Backup;

/**
 * OMS Task 7C.2 — the two flock() modes BackupSubsystemLock issues.
 * Restore holds Exclusive for its whole lifetime; every ordinary
 * backup-subsystem operation holds Shared for its whole duration. A
 * BackupSubsystemLockHandle always knows which mode it was actually
 * granted in — never inferred from context at the call site.
 */
enum LockMode
{
    case Shared;
    case Exclusive;
}
