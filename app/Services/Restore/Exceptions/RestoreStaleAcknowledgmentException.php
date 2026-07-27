<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.8 — every reason RestoreStaleAcknowledgmentService can refuse
 * to terminalize a stale/crashed restore. `reasonCode` lets the Filament
 * layer show a precise Arabic notification without parsing English text.
 */
final class RestoreStaleAcknowledgmentException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function reasonRequired(): self
    {
        return new self('An acknowledgment reason is required.', 'reason_required');
    }

    public static function notEligible(): self
    {
        return new self('This restore is not in a genuinely stale, acknowledgeable state.', 'not_eligible');
    }

    public static function lockHeld(): self
    {
        return new self('The restore subsystem lock is currently held by a live process.', 'lock_held');
    }
}
