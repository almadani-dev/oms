<?php

namespace App\Services\Restore\Metadata;

use InvalidArgumentException;

/**
 * OMS Task 7C.5 correction pass — the small, bounded bundle of the three
 * metadata snapshots (source backup, pre-restore safety backup, restore
 * operation) a future orchestrator embeds into the signed
 * RestoreProgressSnapshot protocol BEFORE the database import runs. This is
 * the durable fallback RestoreMetadataUpserter needs if the process crashes
 * after import and the original database (and therefore the live
 * backup_operations rows) is gone — the signed progress file, not the
 * database, becomes the source of truth for reconstructing them.
 *
 * Deliberately just three already-bounded, already-validated value objects
 * — never a raw array, never a second copy of the outer progress envelope's
 * own fields (phase/result/last_heartbeat_at/error_summary stay exclusively
 * on RestoreProgressSnapshot itself).
 */
final class RestoreReconciliationSnapshot
{
    private const ARRAY_KEYS = ['source_backup', 'safety_backup', 'restore_operation'];

    private function __construct(
        public readonly BackupOperationSnapshot $sourceBackup,
        public readonly BackupOperationSnapshot $safetyBackup,
        public readonly RestoreOperationSnapshot $restoreOperation,
    ) {
    }

    public static function create(
        BackupOperationSnapshot $sourceBackup,
        BackupOperationSnapshot $safetyBackup,
        RestoreOperationSnapshot $restoreOperation,
    ): self {
        return new self($sourceBackup, $safetyBackup, $restoreOperation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_backup' => $this->sourceBackup->toArray(),
            'safety_backup' => $this->safetyBackup->toArray(),
            'restore_operation' => $this->restoreOperation->toArray(),
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $actual = array_keys($data);
        sort($actual);
        $expected = self::ARRAY_KEYS;
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidArgumentException('Reconciliation snapshot array has missing or unexpected keys.');
        }

        foreach (self::ARRAY_KEYS as $key) {
            if (! is_array($data[$key])) {
                throw new InvalidArgumentException("{$key} must be an array.");
            }
        }

        return new self(
            sourceBackup: BackupOperationSnapshot::fromArray($data['source_backup']),
            safetyBackup: BackupOperationSnapshot::fromArray($data['safety_backup']),
            restoreOperation: RestoreOperationSnapshot::fromArray($data['restore_operation']),
        );
    }
}
