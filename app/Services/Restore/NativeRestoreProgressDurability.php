<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\RestoreProgressDurability;

/**
 * OMS Task 7C.2 (durability hardening) — production RestoreProgressDurability.
 *
 * File sync uses fsync() (core PHP since 8.1; this project runs 8.3). A
 * genuinely-absent fsync (sub-8.1 only) is reported as "durable enough"
 * (true) since fflush already ran; a present-but-failed fsync is reported
 * as a real failure (false), which the writer escalates to a hard write
 * failure.
 *
 * Directory sync is inherently best-effort: PHP exposes no portable API to
 * fsync a directory, and Windows does not support it at all. On Linux the
 * containing directory is opened read-only and fsync'd so the rename's
 * directory entry is persisted across an OS crash; anywhere that isn't
 * possible (open fails, fsync missing, Windows), it simply returns false
 * and the caller proceeds — progress.json is already the correct file by
 * then, so this never affects content correctness.
 */
final class NativeRestoreProgressDurability implements RestoreProgressDurability
{
    public function syncFile($handle): bool
    {
        if (! function_exists('fsync')) {
            return true;
        }

        return @fsync($handle);
    }

    public function syncDirectory(string $absoluteDirectory): bool
    {
        if (! function_exists('fsync')) {
            return false;
        }

        $handle = @fopen($absoluteDirectory, 'r');

        if ($handle === false) {
            // Not openable as a stream (notably Windows) — documented
            // best-effort: directory-entry durability is simply unavailable
            // here, never an error.
            return false;
        }

        try {
            return @fsync($handle);
        } finally {
            @fclose($handle);
        }
    }
}
