<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\FilesystemIdentity;

/**
 * Production implementation of FilesystemIdentity.
 *
 * Linux (the production target): uses stat()'s 'dev' field — the real
 * device identifier the kernel assigns per mounted filesystem — which is a
 * reliable, standard way to answer "are these two paths on the same
 * filesystem" regardless of bind mounts or symlinked storage roots.
 *
 * Windows (local development only): there is no cheap, permission-free way
 * to read a true NTFS volume serial number from userland PHP, so this
 * compares only the drive letter (e.g. "C:") or UNC share root. This is a
 * DOCUMENTED LIMITATION — two distinct mount points/junctions under the
 * same drive letter are not distinguished — acceptable because production
 * never runs on Windows; local Laragon testing only needs a safe, coarse
 * approximation that doesn't false-negative on the common single-drive
 * dev setup.
 */
final class NativeFilesystemIdentity implements FilesystemIdentity
{
    public function identityFor(string $path): string
    {
        $existing = $this->nearestExistingAncestor($path);

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->windowsVolumeIdentity($existing);
        }

        $stat = @stat($existing);

        if ($stat === false || ! isset($stat['dev'])) {
            return 'unresolvable:'.$existing;
        }

        return 'dev:'.$stat['dev'];
    }

    private function windowsVolumeIdentity(string $existing): string
    {
        $absolute = realpath($existing) ?: $existing;

        if (preg_match('#^([A-Za-z]:)#', $absolute, $matches) === 1) {
            return 'drive:'.strtoupper($matches[1]);
        }

        if (preg_match('#^(\\\\\\\\[^\\\\]+\\\\[^\\\\]+)#', $absolute, $matches) === 1) {
            return 'unc:'.strtoupper($matches[1]);
        }

        return 'unresolvable:'.strtoupper($absolute);
    }

    private function nearestExistingAncestor(string $path): string
    {
        $current = $path;

        while (! file_exists($current)) {
            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return $current;
    }
}
