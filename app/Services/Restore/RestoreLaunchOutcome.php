<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.4 — RestoreLaunchService::launch()'s bounded return value.
 * `reasonCode` is always a fixed, non-interpolated string (see the codes
 * used in RestoreLaunchService) — safe to log or return to the client,
 * never a raw exception message.
 */
final class RestoreLaunchOutcome
{
    private function __construct(
        public readonly RestoreLaunchOutcomeStatus $status,
        public readonly string $reasonCode,
        public readonly ?string $restoreUuid = null,
        public readonly ?string $restoreStatus = null,
    ) {
    }

    public static function accepted(string $restoreUuid, string $restoreStatus): self
    {
        return new self(RestoreLaunchOutcomeStatus::Accepted, 'accepted', $restoreUuid, $restoreStatus);
    }

    public static function conflict(string $reasonCode): self
    {
        return new self(RestoreLaunchOutcomeStatus::Conflict, $reasonCode);
    }

    public static function locked(): self
    {
        return new self(RestoreLaunchOutcomeStatus::Locked, 'subsystem_locked');
    }

    public static function failed(string $reasonCode): self
    {
        return new self(RestoreLaunchOutcomeStatus::Failed, $reasonCode);
    }
}
