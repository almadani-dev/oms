<?php

namespace Tests\Support\Backup;

use App\Services\Backup\Contracts\SymlinkDetector;

/**
 * Deterministic test double for App\Services\Backup\Contracts\SymlinkDetector
 * — reports exactly the configured absolute paths as symlinks, regardless
 * of what the real filesystem/OS actually supports. Lets symlink-rejection
 * behavior be proven on Windows/CI without needing real symlink-creation
 * privileges.
 */
final class FakeSymlinkDetector implements SymlinkDetector
{
    /**
     * @param  list<string>  $linkedAbsolutePaths
     */
    public function __construct(private readonly array $linkedAbsolutePaths = [])
    {
    }

    public function isLink(string $absolutePath): bool
    {
        return in_array($absolutePath, $this->linkedAbsolutePaths, true);
    }
}
