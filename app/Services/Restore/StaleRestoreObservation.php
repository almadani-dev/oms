<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.7 — one bounded, read-only observation RestoreStaleDetector
 * produces for a single restore UUID. Carries no filesystem path, no
 * credential, and no capability of its own — a pure reporting value object.
 */
final class StaleRestoreObservation
{
    private function __construct(
        public readonly string $restoreUuid,
        public readonly string $reasonCode,
        public readonly ?string $phase,
        public readonly ?int $heartbeatAgeMinutes,
    ) {
    }

    public static function staleHeartbeat(string $restoreUuid, string $phase, int $heartbeatAgeMinutes): self
    {
        return new self($restoreUuid, 'stale_heartbeat', $phase, $heartbeatAgeMinutes);
    }

    public static function needsReview(string $restoreUuid, string $reasonCode): self
    {
        return new self($restoreUuid, $reasonCode, null, null);
    }
}
