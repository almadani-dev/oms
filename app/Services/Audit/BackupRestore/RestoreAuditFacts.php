<?php

namespace App\Services\Audit\BackupRestore;

use App\Models\BackupOperation;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\RestoreProgressSnapshot;

/**
 * The bounded set of facts every restore audit event is built from
 * (OMS Task 9B.6 §5), and the one place the three genuinely different sources
 * of those facts are normalized into a single shape:
 *
 *  - fromRows()        — the LIVE, pre-restore path, where the queued restore
 *                        row and its source backup row are both still present
 *                        in the database that is about to be replaced;
 *  - fromProgress()    — the DURABLE path, read back out of the signed restore
 *                        progress journal (RestoreProgressSnapshot), which is
 *                        what still exists after that database is gone;
 *  - fromReconciliation() — the same durable facts as already unpacked by
 *                        RestoreReconciler into validated metadata snapshots.
 *
 * Normalizing here is what keeps the payload of a replayed event byte-identical
 * in shape to the payload of a live one, so an administrator reading the
 * restored `audit_events` table sees one consistent lifecycle rather than two
 * dialects.
 *
 * Every field is a bounded scalar (or a bounded three-key identity array) that
 * one of the three sources has ALREADY validated. Nothing here can carry an
 * archive path, a temporary path, a nonce, a confirmation phrase, a key, SQL,
 * or raw exception text — there is no field for any of them.
 */
final class RestoreAuditFacts
{
    /**
     * @param  array{user_id: int|null, name: string, email: string}  $requestedBy
     */
    private function __construct(
        public readonly string $restoreUuid,
        public readonly string $sourceBackupUuid,
        public readonly ?string $safetyBackupUuid,
        public readonly string $scope,
        public readonly string $reason,
        public readonly array $requestedBy,
        public readonly ?string $requestedAt,
        public readonly ?string $confirmedAt,
        public readonly ?string $startedAt,
        public readonly ?int $sourceArchiveSizeBytes,
        public readonly ?string $sourceArchiveChecksumSha256,
        public readonly ?int $sourceManifestVersion,
        public readonly ?string $sourceEncryptionKeyId,
    ) {}

    /**
     * The live pre-restore path: a freshly created or freshly claimed restore
     * row plus the backup it will read from. The requester identity comes from
     * `restore_metadata['requester']` — the snapshot RestoreRequestService
     * writes and RestoreLaunchService independently re-validates — never from
     * ambient auth state, so it means the same thing at request time and at
     * claim time.
     */
    public static function fromRows(BackupOperation $restore, ?BackupOperation $sourceBackup): self
    {
        $metadata = is_array($restore->restore_metadata) ? $restore->restore_metadata : [];

        return new self(
            restoreUuid: (string) $restore->uuid,
            sourceBackupUuid: (string) ($sourceBackup?->uuid ?? ''),
            safetyBackupUuid: $restore->preRestoreSafetyBackup?->uuid,
            scope: $restore->scope->value,
            reason: (string) $restore->operation_reason,
            requestedBy: self::identity($metadata['requester'] ?? null),
            requestedAt: $restore->created_at?->toIso8601String(),
            confirmedAt: self::timestamp($metadata['confirmed_at'] ?? null),
            startedAt: $restore->started_at?->toIso8601String(),
            sourceArchiveSizeBytes: $sourceBackup?->size_bytes,
            sourceArchiveChecksumSha256: $sourceBackup?->checksum_sha256,
            sourceManifestVersion: $sourceBackup?->manifest_version,
            sourceEncryptionKeyId: $sourceBackup?->encryption_key_id,
        );
    }

    /**
     * The durable path. The source archive's size/checksum/manifest/key-id are
     * available only when the orchestrator has already embedded the
     * reconciliation snapshot in the journal (it does so immediately after the
     * mandatory safety backup, i.e. before anything destructive happens); when
     * that is absent — a failure earlier than that point — those fields stay
     * null rather than being guessed.
     */
    public static function fromProgress(RestoreProgressSnapshot $progress): self
    {
        $source = $progress->reconciliationSnapshot?->sourceBackup;
        $restoreOperation = $progress->reconciliationSnapshot?->restoreOperation;

        return new self(
            restoreUuid: $progress->restoreUuid,
            sourceBackupUuid: $progress->sourceBackupUuid,
            safetyBackupUuid: $progress->preRestoreSafetyBackupUuid,
            scope: $progress->scope,
            reason: $progress->reason,
            requestedBy: self::identity($progress->requestedBy),
            requestedAt: $progress->requestedAt,
            confirmedAt: $restoreOperation?->confirmedAt ?? $progress->requestedAt,
            startedAt: $restoreOperation?->startedAt,
            sourceArchiveSizeBytes: $source?->sizeBytes,
            sourceArchiveChecksumSha256: $source?->checksumSha256,
            sourceManifestVersion: $source?->manifestVersion,
            sourceEncryptionKeyId: $source?->encryptionKeyId,
        );
    }

    /**
     * The reconciliation path — identical facts, already unpacked and
     * re-validated by RestoreOperationSnapshot/BackupOperationSnapshot when the
     * signed journal was read.
     */
    public static function fromReconciliation(
        RestoreOperationSnapshot $restore,
        BackupOperationSnapshot $source,
        BackupOperationSnapshot $safety,
    ): self {
        return new self(
            restoreUuid: $restore->restoreUuid,
            sourceBackupUuid: $restore->sourceUuid,
            safetyBackupUuid: $restore->safetyUuid ?? $safety->uuid,
            scope: $restore->scope,
            reason: $restore->reason,
            requestedBy: self::identity($restore->requestedBy),
            requestedAt: $restore->startedAt,
            confirmedAt: $restore->confirmedAt,
            startedAt: $restore->startedAt,
            sourceArchiveSizeBytes: $source->sizeBytes,
            sourceArchiveChecksumSha256: $source->checksumSha256,
            sourceManifestVersion: $source->manifestVersion,
            sourceEncryptionKeyId: $source->encryptionKeyId,
        );
    }

    /**
     * @return array{user_id: int|null, name: string, email: string}
     */
    private static function identity(mixed $identity): array
    {
        $identity = is_array($identity) ? $identity : [];

        return [
            'user_id' => is_int($identity['user_id'] ?? null) ? $identity['user_id'] : null,
            'name' => mb_substr(is_string($identity['name'] ?? null) ? $identity['name'] : '', 0, 255),
            'email' => mb_substr(is_string($identity['email'] ?? null) ? $identity['email'] : '', 0, 255),
        ];
    }

    private static function timestamp(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 40) : null;
    }
}
