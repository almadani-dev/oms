<?php

namespace App\Services\Backup;

/**
 * Read-only mirror of BackupDeletionService::delete()'s own rule set — the
 * single result type consumed by both the delete() rejection path and the
 * Filament management page's "إمكانية الحذف" badge, so the two can never
 * silently disagree about why a given backup is or isn't deletable.
 */
final readonly class BackupDeletionEligibility
{
    private function __construct(
        public bool $allowed,
        public ?string $reasonCode,
    ) {
    }

    public static function allowed(): self
    {
        return new self(true, null);
    }

    public static function blocked(string $reasonCode): self
    {
        return new self(false, $reasonCode);
    }
}
