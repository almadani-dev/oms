<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.3 — injectable seam around "which physical filesystem/volume
 * does this path live on," used by RestorePreflightChecker to reject an
 * incompatible layout (restore workspace vs. live attachments parent on
 * different filesystems) before maintenance mode. Bound to
 * NativeFilesystemIdentity in production; tests inject a fake that reports
 * arbitrary paths as the same or different filesystem deterministically,
 * mirroring the existing SymlinkDetector seam's pattern.
 */
interface FilesystemIdentity
{
    /**
     * Two paths are considered the same filesystem/volume iff this method
     * returns identical strings for both. The string's shape is
     * implementation-defined and never compared against anything but
     * another call's return value.
     */
    public function identityFor(string $path): string;
}
