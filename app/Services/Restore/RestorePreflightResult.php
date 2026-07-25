<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Models\BackupOperation;

/**
 * OMS Task 7C.3 — RestorePreflightChecker's success result. Carries the
 * already-loaded source BackupOperation forward (RestoreArchivePreparer
 * needs its disk/stored_path/uuid) plus the disk-space figures that were
 * used to decide "sufficient," purely for observability — nothing here is
 * secret (no key material, no connection credentials).
 */
final class RestorePreflightResult
{
    public function __construct(
        public readonly BackupOperation $sourceBackup,
        public readonly BackupScope $selectedScope,
        public readonly int $estimatedRequiredBytes,
        public readonly int $availableBytes,
    ) {
    }
}
