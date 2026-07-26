<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\RestoreProgressDurability;

/**
 * Test double for RestoreProgressDurability — drives the two crash-durability
 * failure/observation paths honestly without depending on real OS crash
 * behavior. `$onSyncDirectory` lets a test capture filesystem state at the
 * exact moment the post-rename directory sync is attempted, proving ordering
 * (rename-then-sync) rather than merely asserting a call count.
 */
final class FakeRestoreProgressDurability implements RestoreProgressDurability
{
    public int $syncFileCalls = 0;

    public int $syncDirectoryCalls = 0;

    public ?string $lastSyncedDirectory = null;

    /** @var callable|null */
    public $onSyncDirectory = null;

    /**
     * Optional per-call override: receives the 1-based call index and
     * returns the result for that specific call, so a test can make e.g.
     * only the 2nd syncFile() call fail. Falls back to $syncFileResult when
     * null — existing callers that never set this see unchanged behavior.
     *
     * @var (callable(int): bool)|null
     */
    public $onSyncFile = null;

    public function __construct(
        public bool $syncFileResult = true,
        public bool $syncDirectoryResult = true,
    ) {
    }

    public function syncFile($handle): bool
    {
        $this->syncFileCalls++;

        if ($this->onSyncFile !== null) {
            return ($this->onSyncFile)($this->syncFileCalls);
        }

        return $this->syncFileResult;
    }

    public function syncDirectory(string $absoluteDirectory): bool
    {
        $this->syncDirectoryCalls++;
        $this->lastSyncedDirectory = $absoluteDirectory;

        if ($this->onSyncDirectory !== null) {
            ($this->onSyncDirectory)($absoluteDirectory);
        }

        return $this->syncDirectoryResult;
    }
}
