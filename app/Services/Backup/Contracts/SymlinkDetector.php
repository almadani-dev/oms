<?php

namespace App\Services\Backup\Contracts;

/**
 * Injectable seam around "is this path a symlink" so AttachmentCollector
 * and BackupArchiveBuilder's symlink-rejection rule can be proven
 * deterministically in tests without needing real OS symlink-creation
 * privileges (unavailable by default on Windows/CI). Bound to
 * NativeSymlinkDetector in production; tests inject a fake that reports
 * arbitrary paths as links.
 */
interface SymlinkDetector
{
    public function isLink(string $absolutePath): bool;
}
