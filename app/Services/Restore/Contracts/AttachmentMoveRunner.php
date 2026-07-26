<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.6 — injectable seam around "move/rename this directory,"
 * used by RestoreAttachmentActivationService for every live<->quarantine
 * swap step. Bound to NativeAttachmentMoveRunner in production; tests inject
 * a fake that fails a configured number of times before succeeding (or
 * always fails), so retry/backoff/rollback-on-failure behavior can be
 * proven deterministically without depending on real transient OS
 * conditions (open handles, AV scanners).
 *
 * Implementations MUST throw on failure — never return a boolean — so a
 * caller cannot accidentally continue past a failed move.
 */
interface AttachmentMoveRunner
{
    public function move(string $fromAbsolutePath, string $toAbsolutePath): void;
}
