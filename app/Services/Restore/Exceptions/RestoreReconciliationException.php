<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * OMS Task 7C.5 — every reason RestoreReconciler stops before completing
 * its required post-import sequence. Every message is a fixed, generic
 * sentence — never interpolated with a path, credential, or raw command/
 * exception string. The reasonCode identifies exactly which of the seven
 * required steps failed; $previous (never surfaced in the message text) is
 * kept only for internal diagnostics.
 */
final class RestoreReconciliationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionResetFailed(?Throwable $previous = null): self
    {
        return new self('Unable to establish a fresh database connection after restore.', 'connection_reset_failed', $previous);
    }

    public static function migrationFailed(?Throwable $previous = null): self
    {
        return new self('Post-restore migrations failed.', 'migration_failed', $previous);
    }

    public static function permissionSyncFailed(?Throwable $previous = null): self
    {
        return new self('Post-restore permission synchronization failed.', 'permission_sync_failed', $previous);
    }

    public static function permissionCacheResetFailed(?Throwable $previous = null): self
    {
        return new self('Post-restore permission cache reset failed.', 'permission_cache_reset_failed', $previous);
    }

    public static function metadataReconstructionFailed(?Throwable $previous = null): self
    {
        return new self('Post-restore backup/restore metadata reconstruction failed.', 'metadata_reconstruction_failed', $previous);
    }

    public static function ephemeralCleanupFailed(?Throwable $previous = null): self
    {
        return new self('Post-restore ephemeral table cleanup failed.', 'ephemeral_cleanup_failed', $previous);
    }

    public static function queueRestartFailed(?Throwable $previous = null): self
    {
        return new self('Post-restore queue restart failed.', 'queue_restart_failed', $previous);
    }
}
