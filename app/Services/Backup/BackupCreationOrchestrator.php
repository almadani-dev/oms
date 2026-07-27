<?php

namespace App\Services\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\Contracts\BackupArchiveContentVerifier;
use App\Services\Backup\Exceptions\BackupLockedException;
use App\Services\Backup\Exceptions\BackupOperationException;
use App\Services\Restore\RestoreActivityGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Owns the full backup-creation flow: validate config -> lock -> dump ->
 * collect -> manifest -> archive -> encrypt to an unpublished candidate ->
 * fully verify that candidate (the exact same content rules
 * BackupIntegrityVerifier applies to an already-published backup, via the
 * shared BackupArchiveContentVerifier) -> only on full success: publish ->
 * mark completed + verified -> clean up -> release lock. Any failure at
 * any step — including a failed verification — leaves no plaintext or
 * published artifact behind, records a sanitized error_summary, and marks
 * the operation failed; it never leaves a row stuck in queued/running, and
 * it never produces a `completed` row that wasn't fully verified first.
 *
 * `enqueue()` (called by the command, outside the queue) only ever creates
 * the BackupOperation row with status=queued — it does no filesystem or
 * network work. `run()` (called by CreateBackupJob) does all of it.
 *
 * OMS Task 7C.2: `run()` first acquires BackupSubsystemLock::acquireShared()
 * for its entire duration (so a restore holding the exclusive lock always
 * excludes it), then the existing global Cache lock — in that exact order,
 * never the reverse, and never skipped. OMS Task 7C.4 correction pass:
 * immediately after acquiring the shared lock (while still holding it),
 * RestoreActivityGuard::blocksOrdinaryOperations() is checked — this closes
 * the parent-launch-to-child-lock-acquisition handoff gap, where the OS
 * flock() has already been released by the parent but the detached restore
 * child has not yet acquired its own lifetime exclusive lock.
 *
 * The actual creation pipeline lives
 * in the private execute() method so it is never duplicated: `run()` calls
 * it after acquiring both locks, and runWithLockAlreadyHeld() (used only by
 * the future restore orchestrator's pre-restore safety backup, Task 7C.6+)
 * calls it directly after validating an already-held exclusive handle,
 * acquiring neither lock itself.
 */
final class BackupCreationOrchestrator
{
    public function __construct(
        private readonly BackupKeyRing $keyRing,
        private readonly DatabaseDumper $dumper,
        private readonly AttachmentCollector $collector,
        private readonly BackupManifestBuilder $manifestBuilder,
        private readonly SecretstreamEnvelope $envelope,
        private readonly BackupArchiveContentVerifier $contentVerifier,
        private readonly BackupSubsystemLock $subsystemLock,
        private readonly RestoreActivityGuard $restoreActivityGuard = new RestoreActivityGuard(),
    ) {
    }

    /**
     * For daily/weekly, a deterministic `deduplication_key` is computed and
     * the database's UNIQUE constraint on that column is the final
     * concurrency guard: if two workers race to enqueue the same
     * scheduled backup, the loser's INSERT fails with a unique-constraint
     * violation, which is caught here and turned into "return the
     * winner's row, dispatch nothing new" — never an unhandled SQL error,
     * never a duplicate job. Manual/pre_restore always get a fresh row
     * (deduplication_key stays null, and multiple NULLs never collide
     * under a standard SQL unique index).
     */
    public function enqueue(BackupType $type, BackupScope $scope, ?string $reason, ?int $createdBy): BackupOperation
    {
        $deduplicationKey = $this->buildDeduplicationKey($type, $scope);

        $attributes = [
            'type' => $type->value,
            'scope' => $scope->value,
            'status' => BackupStatus::Queued->value,
            'deduplication_key' => $deduplicationKey,
            'disk' => (string) config('oms.backup.disk', 'backups'),
            'operation_reason' => $reason,
            'created_by' => $createdBy,
            // Deliberately NOT auto-true for manual backups (corrected during
            // OMS Task 7B.2): manual backups already can't be auto-deleted by
            // BackupRetentionService via its own independent
            // `type === BackupType::Manual` check in mustKeep() below, so
            // this flag doesn't need to duplicate that. Leaving it false by
            // default is what lets a Super Admin manually delete an
            // unwanted manual backup through BackupDeletionService — an
            // unconditional `true` here would make every manual backup
            // permanently undeletable, which is not the intended design.
            // `is_protected` is reserved for a future explicit
            // "always keep this one" flag, not implied by type.
            'is_protected' => false,
        ];

        try {
            return BackupOperation::create($attributes);
        } catch (QueryException $e) {
            if ($deduplicationKey === null || ! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            $existing = BackupOperation::query()->where('deduplication_key', $deduplicationKey)->first();

            if ($existing === null) {
                // Genuinely unexpected (constraint fired for a different
                // reason, or the winning row was hard-deleted between the
                // violation and this lookup) — surface the real error
                // rather than silently returning nothing.
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * @throws BackupLockedException when the subsystem's shared lock can't
     *                                be acquired (a restore currently holds
     *                                the exclusive lock) or another
     *                                backup/verify/retention operation
     *                                already holds the existing global
     *                                Cache lock — the operation row is left
     *                                untouched (still queued) so a retry
     *                                can proceed.
     * @throws BackupOperationException on any other failure, including a
     *                                    failed pre-publish verification —
     *                                    the operation row is already
     *                                    marked failed with a sanitized
     *                                    summary by the time this is
     *                                    thrown, and no completed row, no
     *                                    published file, and no plaintext
     *                                    artifact is ever left behind.
     */
    public function run(int $operationId): BackupOperation
    {
        $subsystemHandle = $this->subsystemLock->acquireShared();

        if ($subsystemHandle === null) {
            throw BackupLockedException::alreadyRunning();
        }

        if ($this->restoreActivityGuard->blocksOrdinaryOperations()) {
            $subsystemHandle->release();

            throw BackupLockedException::alreadyRunning();
        }

        try {
            $lock = Cache::lock(
                (string) config('oms.backup.lock_name', 'oms-backup-operation'),
                (int) config('oms.backup.lock_ttl', 3600),
            );

            if (! $lock->get()) {
                throw BackupLockedException::alreadyRunning();
            }

            try {
                return $this->execute($operationId);
            } finally {
                $lock->release();
            }
        } finally {
            $subsystemHandle->release();
        }
    }

    /**
     * Runs the identical creation pipeline as run(), for exactly one
     * caller: the future restore orchestrator's pre-restore safety backup
     * (Task 7C.6+), which already holds the subsystem's exclusive lock for
     * its entire run and must never attempt to acquire a second filesystem
     * lock or the global Cache lock on top of it (both would be redundant
     * at best and a nested-lock deadlock at worst against a non-reentrant
     * Cache lock). $handle is validated — live, Exclusive, and issued for
     * this exact BackupSubsystemLock's own path — before a single dump/file
     * operation begins; merely being an instance of BackupSubsystemLockHandle
     * is never sufficient. Ordinary callers must keep using run().
     *
     * OMS Task 7C.7 hardening pass — $onTick, when given, is forwarded to
     * DatabaseDumper::dump() so RestoreOrchestrator can keep a restore's
     * signed progress heartbeat alive for the whole duration of the
     * mandatory pre-restore safety backup, not just before/after it.
     *
     * @throws BackupOperationException if $handle fails validation, or on
     *                                    any creation-pipeline failure.
     */
    public function runWithLockAlreadyHeld(int $operationId, BackupSubsystemLockHandle $handle, ?callable $onTick = null): BackupOperation
    {
        if (! $this->subsystemLock->validateHandle($handle, LockMode::Exclusive)) {
            throw new BackupOperationException('Refusing to run the backup pipeline: the supplied lock handle is not a live, exclusive, matching subsystem lock.');
        }

        return $this->execute($operationId, $onTick);
    }

    private function execute(int $operationId, ?callable $onTick = null): BackupOperation
    {
        $operation = BackupOperation::findOrFail($operationId);
        $disk = Storage::disk($operation->disk);
        $workingDir = null;

        try {
            $operation->forceFill(['status' => BackupStatus::Running->value, 'started_at' => now()])->save();

            $activeKey = $this->keyRing->activeKey();

            $workingDir = rtrim((string) config('oms.backup.working_directory', '.work'), '/\\').'/'.$operation->uuid;
            $disk->makeDirectory($workingDir);
            $workingAbsolute = $disk->path($workingDir);

            $dump = $operation->scope->includesDatabase()
                ? $this->dumper->dump($workingAbsolute.DIRECTORY_SEPARATOR.'dump.sql', $onTick)
                : null;

            $attachments = $operation->scope->includesFiles()
                ? $this->collector->collect()
                : null;

            $operation->forceFill(['status' => BackupStatus::Verifying->value])->save();

            $manifest = $this->manifestBuilder->build(
                uuid: $operation->uuid,
                type: $operation->type,
                scope: $operation->scope,
                createdAt: CarbonImmutable::now('UTC'),
                encryptionKeyId: $activeKey['key_id'],
                envelopeVersion: SecretstreamEnvelope::VERSION,
                dump: $dump,
                attachments: $attachments,
            );

            $archivePath = $workingAbsolute.DIRECTORY_SEPARATOR.'archive.zip';
            $archive = new BackupArchiveBuilder($archivePath);
            $archive->addManifest($manifest);

            if ($dump !== null) {
                $archive->addDatabaseDump($dump->absolutePath);
            }

            if ($attachments !== null) {
                $archive->addAttachmentFiles($attachments->files, Storage::disk('attachments')->path(''));
            }

            $archive->close();

            // The candidate is encrypted here but is NOT yet the published
            // backup — it lives only under the private working directory,
            // under a name (.enc.partial) that BackupIntegrityVerifier and
            // the download route never resolve, and it is never referenced
            // by BackupOperation::stored_path until publication below.
            $candidatePath = $workingAbsolute.DIRECTORY_SEPARATOR.'archive.omsbak.enc.partial';
            $this->envelope->encryptFile($archivePath, $candidatePath, $activeKey['key_id'], $activeKey['key']);

            // Full verification of the unpublished candidate — the exact
            // same content rules (manifest schema/version, UUID/type/scope
            // match, dump + every attachment checksum, exact entry set)
            // BackupIntegrityVerifier applies to an already-published
            // backup, via the shared BackupArchiveContentVerifier. A
            // candidate that fails ANY of these checks is never published
            // and never reaches status=completed.
            $this->verifyCandidate($candidatePath, $workingAbsolute, $operation);

            clearstatcache(true, $candidatePath);
            $encryptedSize = filesize($candidatePath);
            $encryptedChecksum = (string) hash_file('sha256', $candidatePath);

            if ($encryptedSize === false) {
                throw new RuntimeException('Unable to determine final encrypted archive size.');
            }

            $filename = $this->buildFilename($operation);

            if ($disk->exists($filename)) {
                throw new RuntimeException('Refusing to overwrite an existing backup archive.');
            }

            $finalAbsolutePath = $disk->path($filename);

            if (! @rename($candidatePath, $finalAbsolutePath)) {
                throw new RuntimeException('Unable to publish the final encrypted backup archive.');
            }

            $operation->forceFill([
                'status' => BackupStatus::Completed->value,
                'stored_path' => $filename,
                'encrypted_filename' => $filename,
                'size_bytes' => $encryptedSize,
                'checksum_sha256' => $encryptedChecksum,
                'manifest_version' => BackupManifestBuilder::VERSION,
                'encryption_key_id' => $activeKey['key_id'],
                'file_count' => $attachments?->fileCount,
                'original_size_bytes' => ($dump?->sizeBytes ?? 0) + ($attachments?->totalSizeBytes ?? 0),
                'completed_at' => now(),
                'verified_at' => now(),
            ])->save();

            $this->cleanupWorkingDirectory($disk, $workingDir);

            return $operation->refresh();
        } catch (Throwable $e) {
            if ($workingDir !== null) {
                $this->cleanupWorkingDirectory($disk, $workingDir);
            }

            $summary = $this->sanitizeError($e->getMessage());

            $operation->forceFill([
                'status' => BackupStatus::Failed->value,
                'failed_at' => now(),
                'error_summary' => $summary,
            ])->save();

            throw new BackupOperationException("Backup creation failed: {$summary}", previous: $e);
        }
    }

    /**
     * Decrypts the unpublished candidate back out into the working
     * directory (authenticated Secretstream decryption — fails on any
     * tamper/truncation/missing-TAG_FINAL) and hands the resulting
     * plaintext ZIP to the shared content verifier. The decrypted
     * verification copy is always removed afterward, success or failure —
     * it is never the file that gets published.
     */
    private function verifyCandidate(string $candidatePath, string $workingAbsolute, BackupOperation $operation): void
    {
        $verifyPath = $workingAbsolute.DIRECTORY_SEPARATOR.'verify.zip';

        try {
            $this->envelope->decryptFile($candidatePath, $verifyPath, fn (string $keyId): string => $this->keyRing->resolve($keyId));

            $this->contentVerifier->verify(
                $verifyPath,
                $operation->uuid,
                $operation->type->value,
                $operation->scope->value,
            );
        } finally {
            if (is_file($verifyPath)) {
                @unlink($verifyPath);
            }
        }
    }

    private function buildFilename(BackupOperation $operation): string
    {
        $timezone = (string) config('oms.backup.timezone', 'Asia/Gaza');
        $timestamp = CarbonImmutable::now($timezone)->format('Ymd_His');
        $shortUuid = substr(str_replace('-', '', $operation->uuid), 0, 8);

        return "{$operation->type->value}_{$timestamp}_{$shortUuid}.omsbak.enc";
    }

    private function cleanupWorkingDirectory(FilesystemAdapter $disk, string $workingDir): void
    {
        if ($disk->exists($workingDir)) {
            $disk->deleteDirectory($workingDir);
        }
    }

    /**
     * Strips this app's own absolute filesystem roots and any accidental
     * MYSQL_PWD-looking substring before a message is persisted as
     * BackupOperation::error_summary, and bounds its length.
     */
    private function sanitizeError(string $message): string
    {
        $message = str_replace(storage_path(), '[storage]', $message);
        $message = str_replace(base_path(), '[app]', $message);
        $message = preg_replace('/MYSQL_PWD=\S*/i', 'MYSQL_PWD=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 2000);
    }

    /**
     * Scheduled types only — Asia/Gaza-derived regardless of
     * config('app.timezone'). Null for manual/pre_restore (see class
     * docblock for why that's safe under a unique index).
     */
    private function buildDeduplicationKey(BackupType $type, BackupScope $scope): ?string
    {
        $timezone = (string) config('oms.backup.timezone', 'Asia/Gaza');
        $now = CarbonImmutable::now($timezone);

        return match ($type) {
            BackupType::Daily => "daily:{$now->format('Y-m-d')}:{$scope->value}",
            BackupType::Weekly => "weekly:{$now->format('o')}-W{$now->format('W')}:{$scope->value}",
            default => null,
        };
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() === '23000') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique constraint') || str_contains($message, 'duplicate entry');
    }
}
