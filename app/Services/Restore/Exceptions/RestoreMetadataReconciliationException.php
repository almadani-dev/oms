<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * OMS Task 7C.5 — every reason RestoreMetadataUpserter fails to reconstruct
 * the source backup, safety backup, or restore operation's own authoritative
 * backup_operations row. Distinct from RestoreReconciliationException (the
 * umbrella per-phase exception RestoreReconciler itself throws) so a metadata
 * failure's own sub-step is always identifiable, and distinct per row so a
 * caller can tell "source never got upserted" apart from "safety/restore
 * upsert failed after source already succeeded."
 */
final class RestoreMetadataReconciliationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function sourceUpsertFailed(?Throwable $previous = null): self
    {
        return new self('Failed to reconstruct the source backup metadata row.', 'source_upsert_failed', $previous);
    }

    public static function safetyUpsertFailed(?Throwable $previous = null): self
    {
        return new self('Failed to reconstruct the pre-restore safety backup metadata row.', 'safety_upsert_failed', $previous);
    }

    public static function restoreUpsertFailed(?Throwable $previous = null): self
    {
        return new self('Failed to reconstruct the restore operation metadata row.', 'restore_upsert_failed', $previous);
    }
}
