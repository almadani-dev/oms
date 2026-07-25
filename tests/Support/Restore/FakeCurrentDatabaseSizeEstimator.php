<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\CurrentDatabaseSizeEstimator;

/**
 * Deterministic test double for
 * App\Services\Restore\Contracts\CurrentDatabaseSizeEstimator — never
 * touches a real MySQL connection. Returns a fixed byte count when given
 * one, or echoes back whatever fallback it's called with (to test the
 * "unavailable -> conservative fallback" behavior itself).
 */
final class FakeCurrentDatabaseSizeEstimator implements CurrentDatabaseSizeEstimator
{
    public function __construct(private readonly ?int $fixedBytes = null)
    {
    }

    public function estimateBytes(int $conservativeFallbackBytes): int
    {
        return $this->fixedBytes ?? $conservativeFallbackBytes;
    }
}
