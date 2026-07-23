<?php

namespace App\Services\Backup;

use App\Services\Backup\Contracts\SymlinkDetector;

/**
 * Production implementation — a thin wrapper around PHP's own is_link().
 * A real Linux symlink-escape rehearsal (creating an actual symlink whose
 * target resolves outside storage/app/private/attachments and confirming
 * this class + the callers using it reject it) remains part of Task 7D's
 * staging acceptance pass, since this Windows/CI environment cannot
 * reliably create symlinks without elevated privileges.
 */
final class NativeSymlinkDetector implements SymlinkDetector
{
    public function isLink(string $absolutePath): bool
    {
        return is_link($absolutePath);
    }
}
