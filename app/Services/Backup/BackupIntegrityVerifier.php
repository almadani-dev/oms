<?php

namespace App\Services\Backup;

use App\Models\BackupOperation;
use App\Services\Backup\Contracts\BackupArchiveContentVerifier;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use App\Services\Backup\Support\SafeBackupPath;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Re-verifies an already-published backup: encrypted checksum,
 * authenticated decryption, and — via the shared
 * BackupArchiveContentVerifier — manifest schema/UUID/type/scope, every
 * component checksum, and exact archive entry membership. Any mismatch
 * fails closed — `verified_at` is only ever written after every single
 * check has passed. All decrypted plaintext is cleaned up whether
 * verification succeeds or fails.
 *
 * Holds the same per-backup BackupFileLock a download or a retention
 * deletion would hold, for the duration of the read, so verification can
 * never race a concurrent deletion (and retention, which acquires the
 * same lock before deleting a file, can never race a concurrent
 * verification).
 *
 * OMS Task 7C.2: verify() additionally holds BackupSubsystemLock::acquireShared()
 * for its entire duration — including the up-front validity checks, not
 * only the locked read — then the existing per-backup BackupFileLock, in
 * that exact order. A restore holding the exclusive subsystem lock always
 * excludes a verification attempt.
 */
final class BackupIntegrityVerifier
{
    public function __construct(
        private readonly BackupKeyRing $keyRing,
        private readonly SecretstreamEnvelope $envelope,
        private readonly BackupArchiveContentVerifier $contentVerifier,
        private readonly BackupSubsystemLock $subsystemLock = new BackupSubsystemLock(),
    ) {
    }

    /**
     * @throws BackupIntegrityException
     */
    public function verify(BackupOperation $operation): void
    {
        $subsystemHandle = $this->subsystemLock->acquireShared();

        if ($subsystemHandle === null) {
            throw new BackupIntegrityException('Backup subsystem is currently locked by an active restore.');
        }

        try {
            if (! $operation->isCompleted()) {
                throw new BackupIntegrityException('Only a completed backup can be verified.');
            }

            $approvedDisk = (string) config('oms.backup.disk', 'backups');

            if ($operation->disk !== $approvedDisk) {
                throw new BackupIntegrityException('Backup operation references an unapproved disk.');
            }

            $storedPath = (string) $operation->stored_path;

            if (! SafeBackupPath::isSafe($storedPath)) {
                throw new BackupIntegrityException('Backup operation has an unsafe stored path.');
            }

            $disk = Storage::disk($operation->disk);

            if (! $disk->exists($storedPath)) {
                throw new BackupIntegrityException('Backup archive file is missing from disk.');
            }

            $lock = Cache::lock(BackupFileLock::name($operation->uuid), (int) config('oms.backup.lock_ttl', 3600));

            if (! $lock->get()) {
                throw new BackupIntegrityException('Backup archive is currently in use by another operation.');
            }

            try {
                $this->verifyLocked($operation, $disk, $storedPath);
            } finally {
                $lock->release();
            }
        } finally {
            $subsystemHandle->release();
        }
    }

    private function verifyLocked(BackupOperation $operation, FilesystemAdapter $disk, string $storedPath): void
    {
        $absolutePath = $disk->path($storedPath);
        clearstatcache(true, $absolutePath);
        $size = filesize($absolutePath);

        if ($size === false || ($operation->size_bytes !== null && $size !== (int) $operation->size_bytes)) {
            throw new BackupIntegrityException('Backup archive size does not match recorded metadata.');
        }

        $checksum = (string) hash_file('sha256', $absolutePath);

        if (! hash_equals((string) $operation->checksum_sha256, $checksum)) {
            throw new BackupIntegrityException('Backup archive checksum mismatch.');
        }

        $verifyDir = rtrim((string) config('oms.backup.working_directory', '.work'), '/\\').'/verify-'.(string) Str::uuid();
        $disk->makeDirectory($verifyDir);
        $plainPath = $disk->path($verifyDir).DIRECTORY_SEPARATOR.'archive.zip';

        try {
            // Authenticated decryption — throws on any tamper/corruption/
            // truncation/unknown-key-id/unsupported-version.
            $this->envelope->decryptFile($absolutePath, $plainPath, fn (string $keyId): string => $this->keyRing->resolve($keyId));

            $this->contentVerifier->verify(
                $plainPath,
                $operation->uuid,
                $operation->type->value,
                $operation->scope->value,
            );

            $operation->forceFill(['verified_at' => now()])->save();
        } finally {
            if (is_file($plainPath)) {
                @unlink($plainPath);
            }

            if ($disk->exists($verifyDir)) {
                $disk->deleteDirectory($verifyDir);
            }
        }
    }
}
