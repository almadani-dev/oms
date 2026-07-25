<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.2 (durability hardening) — the small injectable seam
 * RestoreProgressWriter uses for the two crash-durability steps that must
 * be exercised in tests without depending on real OS crash behavior: force
 * a temp-file sync failure, and observe/force the post-rename parent
 * directory sync. Bound to NativeRestoreProgressDurability in production;
 * a test double drives the failure paths.
 */
interface RestoreProgressDurability
{
    /**
     * Durably synchronize an open temp-file handle to the physical device.
     *
     * Returns true when the data is durable (fsync succeeded) OR when fsync
     * is genuinely unavailable on this runtime (sub-8.1 PHP) and fflush is
     * the best that can be done — both are acceptable states to proceed
     * from. Returns false ONLY for a real fsync FAILURE on a runtime where
     * fsync exists: that is treated by the writer as a hard write failure
     * (the temp file is not renamed over the current valid progress.json).
     *
     * @param  resource  $handle
     */
    public function syncFile($handle): bool;

    /**
     * Best-effort persistence of the containing directory's entry after a
     * successful rename, so the rename itself survives an OS crash on
     * Linux. Never throws and never affects the already-swapped file's
     * content — a failure here (or genuine unavailability, e.g. on Windows,
     * where directory fsync is not supported) only weakens crash-durability
     * of the rename metadata, never the correctness of progress.json, which
     * is already the intended new file by the time this is called. Returns
     * whether the sync actually happened (for diagnostics/tests only).
     */
    public function syncDirectory(string $absoluteDirectory): bool;
}
