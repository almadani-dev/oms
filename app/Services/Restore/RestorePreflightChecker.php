<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Models\BackupOperation;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\Exceptions\BackupKeyConfigurationException;
use App\Services\Backup\SecretstreamEnvelope;
use App\Services\Backup\Support\SafeBackupPath;
use App\Services\Restore\Contracts\FilesystemIdentity;
use App\Services\Restore\Exceptions\RestoreInsufficientDiskSpaceException;
use App\Services\Restore\Exceptions\RestorePreflightException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * OMS Task 7C.3 — the single non-destructive gate a restore must pass
 * before maintenance mode. Never decrypts the full archive, never touches
 * the live database, never runs mysql/mysqldump, and never creates the
 * restore workspace itself — it only reads metadata, peeks the encrypted
 * archive's cleartext header, inspects configuration, and measures current
 * filesystem state.
 *
 * check() fails closed on the first violated rule and throws either
 * RestorePreflightException (sanitized reasonCode, see that class) or
 * RestoreInsufficientDiskSpaceException.
 */
final class RestorePreflightChecker
{
    public function __construct(
        private readonly BackupKeyRing $keyRing,
        private readonly SecretstreamEnvelope $envelope,
        private readonly RestoreActivityGuard $activityGuard,
        private readonly RestoreDiskSpaceEstimator $diskSpaceEstimator,
        private readonly FilesystemIdentity $filesystemIdentity,
    ) {
    }

    /**
     * @throws RestorePreflightException
     * @throws RestoreInsufficientDiskSpaceException
     */
    public function check(string $sourceBackupUuid, BackupScope $selectedScope, ?string $currentRestoreUuid = null): RestorePreflightResult
    {
        $sourceBackup = $this->resolveSourceBackup($sourceBackupUuid);

        $this->assertScopeCompatible($selectedScope, $sourceBackup->scope);
        $this->assertArchiveEncryptionResolvable($sourceBackup);

        if ($selectedScope->includesDatabase()) {
            $this->assertMysqlClientAvailable();
            $this->assertDatabaseConnectionConfigured();
        }

        if ($selectedScope->includesFiles()) {
            $this->assertFilesystemCompatible();
        }

        $this->assertNoConflictingRestoreActivity($currentRestoreUuid);

        $restoreDisk = (string) config('oms.backup.restore.disk', 'restores');
        $estimate = $this->diskSpaceEstimator->estimate($sourceBackup, $selectedScope, Storage::disk($restoreDisk)->path(''));

        if (! $estimate->isSufficient()) {
            throw RestoreInsufficientDiskSpaceException::forBytes($estimate->requiredBytes, $estimate->availableBytes);
        }

        return new RestorePreflightResult($sourceBackup, $selectedScope, $estimate->requiredBytes, $estimate->availableBytes);
    }

    private function resolveSourceBackup(string $sourceBackupUuid): BackupOperation
    {
        $backup = BackupOperation::withTrashed()->where('uuid', $sourceBackupUuid)->first();

        if ($backup === null) {
            throw RestorePreflightException::sourceNotFound();
        }

        if ($backup->trashed()) {
            throw RestorePreflightException::sourceSoftDeleted();
        }

        if ($backup->isRestoreOperation()) {
            throw RestorePreflightException::sourceIsRestoreOperation();
        }

        if (! $backup->isCompleted()) {
            throw RestorePreflightException::sourceNotCompleted();
        }

        if ($backup->verified_at === null) {
            throw RestorePreflightException::sourceNotVerified();
        }

        if ($backup->stored_path === null || ! SafeBackupPath::isSafe($backup->stored_path) || ! Storage::disk($backup->disk)->exists($backup->stored_path)) {
            throw RestorePreflightException::sourceArchiveMissing();
        }

        return $backup;
    }

    private function assertScopeCompatible(BackupScope $selectedScope, BackupScope $sourceScope): void
    {
        $compatible = match ($selectedScope) {
            BackupScope::Database => $sourceScope->includesDatabase(),
            BackupScope::Files => $sourceScope->includesFiles(),
            BackupScope::Full => $sourceScope === BackupScope::Full,
        };

        if (! $compatible) {
            throw RestorePreflightException::scopeIncompatible();
        }
    }

    private function assertArchiveEncryptionResolvable(BackupOperation $sourceBackup): void
    {
        $absolutePath = Storage::disk($sourceBackup->disk)->path($sourceBackup->stored_path);

        try {
            $keyId = $this->envelope->peekKeyId($absolutePath);
        } catch (Throwable) {
            throw RestorePreflightException::archiveHeaderInvalid();
        }

        try {
            $this->keyRing->resolve($keyId);
        } catch (BackupKeyConfigurationException) {
            throw RestorePreflightException::encryptionKeyUnavailable();
        }
    }

    private function assertMysqlClientAvailable(): void
    {
        $path = trim((string) config('oms.backup.mysql_client_path', ''));

        if ($path === '' || ! is_file($path)) {
            throw RestorePreflightException::mysqlClientMissing();
        }

        // Windows local-dev limitation: is_executable() does not reliably
        // reflect whether an arbitrary file is runnable (no POSIX exec
        // bit) — an existing regular file is treated as sufficient there.
        // Linux (production) requires the real executable bit.
        if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($path)) {
            throw RestorePreflightException::mysqlClientNotExecutable();
        }
    }

    private function assertDatabaseConnectionConfigured(): void
    {
        $connectionName = (string) (config('oms.backup.database_connection') ?: config('database.default'));
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            throw RestorePreflightException::databaseConnectionIncomplete();
        }

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if (! array_key_exists($key, $connection) || (string) $connection[$key] === '') {
                throw RestorePreflightException::databaseConnectionIncomplete();
            }
        }
    }

    private function assertFilesystemCompatible(): void
    {
        $restoreDisk = (string) config('oms.backup.restore.disk', 'restores');
        $restoreRoot = Storage::disk($restoreDisk)->path('');
        $attachmentsParent = dirname(rtrim(Storage::disk('attachments')->path(''), '/\\'));

        if ($this->filesystemIdentity->identityFor($restoreRoot) !== $this->filesystemIdentity->identityFor($attachmentsParent)) {
            throw RestorePreflightException::filesystemIncompatible();
        }
    }

    private function assertNoConflictingRestoreActivity(?string $currentRestoreUuid): void
    {
        $state = $this->activityGuard->isActive($currentRestoreUuid);

        if ($state === RestoreActivityState::TamperedOrInvalid) {
            throw RestorePreflightException::restoreStateRequiresReview();
        }

        if ($state === RestoreActivityState::Active) {
            throw RestorePreflightException::restoreAlreadyActive();
        }
    }
}
