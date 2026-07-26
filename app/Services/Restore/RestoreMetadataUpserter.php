<?php

namespace App\Services\Restore;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Restore\Contracts\RestoreMetadataReconstructor;
use App\Services\Restore\Exceptions\RestoreMetadataReconciliationException;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OMS Task 7C.5 — reconstructs the three authoritative backup_operations
 * rows a database import may have left stale or entirely missing: the
 * source backup, the pre-restore safety backup, and the restore operation's
 * own row. Always upserts in that exact order — source, then safety, then
 * restore — since restore's own row resolves source_backup_id/
 * pre_restore_safety_backup_id from the freshly-upserted rows' real
 * (possibly new) auto-increment IDs, never from an old numeric ID trusted
 * to still mean the same thing after a database replacement. Every FK is
 * resolved by UUID lookup, never by assuming an ID survived the restore.
 *
 * A row is looked up by its own UUID (withTrashed(), and un-deleted if
 * found soft-deleted) and either updated in place or created — never
 * inserted twice, never left duplicated.
 *
 * created_by is resolved against the just-migrated `users` table: if the
 * snapshot's user_id still exists there, it is preserved; otherwise
 * created_by is set to null and the name/email identity snapshot is
 * preserved instead, inside restore_metadata — a restored database can
 * genuinely be missing the original actor's user row, and this must never
 * produce a dangling foreign key.
 */
final class RestoreMetadataUpserter implements RestoreMetadataReconstructor
{
    public function reconstruct(
        BackupOperationSnapshot $sourceBackup,
        BackupOperationSnapshot $safetyBackup,
        RestoreOperationSnapshot $restoreOperation,
    ): void {
        try {
            $sourceId = $this->upsertBackup($sourceBackup);
        } catch (Throwable $e) {
            throw RestoreMetadataReconciliationException::sourceUpsertFailed($e);
        }

        try {
            $safetyId = $this->upsertBackup($safetyBackup);
        } catch (Throwable $e) {
            throw RestoreMetadataReconciliationException::safetyUpsertFailed($e);
        }

        try {
            $this->upsertRestore($restoreOperation, $sourceId, $safetyId);
        } catch (Throwable $e) {
            throw RestoreMetadataReconciliationException::restoreUpsertFailed($e);
        }
    }

    private function upsertBackup(BackupOperationSnapshot $snapshot): int
    {
        return DB::transaction(function () use ($snapshot): int {
            $createdBy = $this->resolveUserId($snapshot->createdBy['user_id']);

            $metadata = $createdBy === null ? ['creator_identity' => $snapshot->createdBy] : null;

            $row = $this->upsertRow($snapshot->uuid, [
                'type' => $snapshot->type,
                'scope' => $snapshot->scope,
                'status' => BackupStatus::Completed->value,
                'disk' => $snapshot->disk,
                'stored_path' => $snapshot->archivePath,
                'encrypted_filename' => $snapshot->archiveFilename,
                'size_bytes' => $snapshot->sizeBytes,
                'checksum_sha256' => $snapshot->checksumSha256,
                'encryption_key_id' => $snapshot->encryptionKeyId,
                'manifest_version' => $snapshot->manifestVersion,
                'file_count' => $snapshot->fileCount,
                'original_size_bytes' => $snapshot->originalSizeBytes,
                'created_at' => $snapshot->createdAt,
                'started_at' => $snapshot->startedAt,
                'completed_at' => $snapshot->completedAt,
                'verified_at' => $snapshot->verifiedAt,
                'failed_at' => null,
                'created_by' => $createdBy,
                'operation_reason' => $snapshot->operationReason,
                'error_summary' => null,
                'is_protected' => $snapshot->isProtected,
                'restore_metadata' => $metadata,
            ]);

            return $row->id;
        });
    }

    private function upsertRestore(RestoreOperationSnapshot $snapshot, int $sourceBackupId, int $safetyBackupId): void
    {
        DB::transaction(function () use ($snapshot, $sourceBackupId, $safetyBackupId): void {
            $createdBy = $this->resolveUserId($snapshot->requestedBy['user_id']);

            $metadata = [
                'requester' => $snapshot->requestedBy,
                'confirmed_at' => $snapshot->confirmedAt,
                'source_backup_uuid' => $snapshot->sourceUuid,
                'pre_restore_safety_backup_uuid' => $snapshot->safetyUuid,
                'phase_history' => $snapshot->phaseHistory,
            ];

            if ($createdBy === null) {
                $metadata['creator_identity'] = $snapshot->requestedBy;
            }

            if ($snapshot->resultContext !== null) {
                $metadata['result_context'] = $snapshot->resultContext;
            }

            $this->upsertRow($snapshot->restoreUuid, [
                'type' => BackupType::Restore->value,
                'scope' => $snapshot->scope,
                'status' => BackupStatus::Restoring->value,
                'disk' => (string) config('oms.backup.restore.disk', 'restores'),
                'started_at' => $snapshot->startedAt,
                'created_by' => $createdBy,
                'operation_reason' => $snapshot->reason,
                'source_backup_id' => $sourceBackupId,
                'pre_restore_safety_backup_id' => $safetyBackupId,
                'restore_metadata' => $metadata,
                'launch_nonce' => null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertRow(string $uuid, array $attributes): BackupOperation
    {
        $row = BackupOperation::withTrashed()->firstOrNew(['uuid' => $uuid]);
        $row->forceFill($attributes)->save();

        if ($row->trashed()) {
            $row->restore();
        }

        return $row;
    }

    private function resolveUserId(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        return User::query()->whereKey($userId)->exists() ? $userId : null;
    }
}
