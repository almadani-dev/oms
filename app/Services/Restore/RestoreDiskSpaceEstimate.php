<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.3 — RestoreDiskSpaceEstimator's result: a required byte total
 * (already margin- and reserve-adjusted) and the bytes actually free on the
 * filesystem that will hold the restore workspace.
 */
final class RestoreDiskSpaceEstimate
{
    public function __construct(
        public readonly int $requiredBytes,
        public readonly int $availableBytes,
    ) {
    }

    public function isSufficient(): bool
    {
        return $this->availableBytes >= $this->requiredBytes;
    }
}
