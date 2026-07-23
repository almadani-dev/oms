<?php

namespace App\Services\Backup\Contracts;

use App\Services\Backup\Exceptions\BackupIntegrityException;

/**
 * "Is this decrypted ZIP archive's content exactly what its own manifest —
 * and the operation it claims to belong to — says it should be." Shared by
 * BackupCreationOrchestrator (verifying an unpublished candidate archive
 * before it is ever published/marked completed) and BackupIntegrityVerifier
 * (re-verifying an already-published backup later) — both depend on this
 * contract, not on the concrete implementation, so tests can inject a fake
 * that simulates a verification failure without subclassing a final class.
 */
interface BackupArchiveContentVerifier
{
    /**
     * @return array<string,mixed> the decoded manifest, on success
     *
     * @throws BackupIntegrityException
     */
    public function verify(string $plainZipPath, string $expectedUuid, string $expectedType, string $expectedScope): array;
}
