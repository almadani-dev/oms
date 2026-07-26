<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\RestoreMetadataReconstructor;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use RuntimeException;

final class FakeRestoreMetadataReconstructor implements RestoreMetadataReconstructor
{
    public int $callCount = 0;

    public function __construct(
        private readonly RestoreReconciliationOrderLog $log,
        private readonly bool $shouldFail = false,
    ) {
    }

    public function reconstruct(
        BackupOperationSnapshot $sourceBackup,
        BackupOperationSnapshot $safetyBackup,
        RestoreOperationSnapshot $restoreOperation,
    ): void {
        $this->callCount++;
        $this->log->record('metadata_reconstruction');

        if ($this->shouldFail) {
            throw new RuntimeException('fake metadata reconstruction failure');
        }
    }
}
