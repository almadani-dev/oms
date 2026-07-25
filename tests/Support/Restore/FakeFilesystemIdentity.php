<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\FilesystemIdentity;

/**
 * Deterministic test double for App\Services\Restore\Contracts\FilesystemIdentity
 * — reports a fixed identity string per exact absolute path, defaulting to
 * a shared "same filesystem" identity for anything not explicitly
 * overridden. Lets RestorePreflightChecker's same-filesystem check be
 * proven both ways without depending on real mount points.
 */
final class FakeFilesystemIdentity implements FilesystemIdentity
{
    /**
     * @param  array<string, string>  $overrides  absolute path => identity string
     */
    public function __construct(
        private readonly array $overrides = [],
        private readonly string $defaultIdentity = 'same-fs',
    ) {
    }

    public function identityFor(string $path): string
    {
        return $this->overrides[$path] ?? $this->defaultIdentity;
    }
}
