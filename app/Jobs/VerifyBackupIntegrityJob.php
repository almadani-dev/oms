<?php

namespace App\Jobs;

use App\Models\BackupOperation;
use App\Notifications\BackupNotificationEvent;
use App\Services\Backup\BackupIntegrityVerifier;
use App\Services\Backup\BackupNotifier;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Re-verifies exactly one completed backup. Updates verified_at on success
 * or a sanitized error_summary on failure — never deletes the backup
 * itself; that stays entirely BackupRetentionService's responsibility.
 */
class VerifyBackupIntegrityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $backupOperationId)
    {
        $this->onQueue((string) config('oms.backup.queue', 'backups'));
        $this->timeout = (int) config('oms.backup.job_timeout', 3600);
    }

    /**
     * Cache key the Filament "فحص السلامة" row action uses to refuse a
     * second verification request for the same backup while one is
     * already queued/running — cleared here, success or failure, so a
     * later legitimate request is never permanently blocked by a stale
     * flag.
     */
    public static function pendingCacheKey(string $backupUuid): string
    {
        return "oms-backup-verify-pending:{$backupUuid}";
    }

    public function handle(BackupIntegrityVerifier $verifier): void
    {
        $operation = BackupOperation::findOrFail($this->backupOperationId);

        try {
            $verifier->verify($operation);
            app(BackupNotifier::class)->notify($operation->refresh(), BackupNotificationEvent::VerificationSucceeded);
        } catch (BackupIntegrityException $e) {
            $operation->forceFill(['error_summary' => mb_substr($e->getMessage(), 0, 2000)])->save();
            app(BackupNotifier::class)->notify($operation, BackupNotificationEvent::VerificationFailed, $operation->error_summary);

            throw $e;
        } finally {
            Cache::forget(self::pendingCacheKey($operation->uuid));
        }
    }
}
