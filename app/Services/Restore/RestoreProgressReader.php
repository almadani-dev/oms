<?php

namespace App\Services\Restore;

use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\Metadata\RestoreReconciliationSnapshot;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use TypeError;

/**
 * OMS Task 7C.2 — the only place a restore progress file is ever read back.
 * The signature is verified before anything else about the content is
 * trusted, on every single read — never cached, never skipped. A progress
 * file is never authoritative for *authorizing* an action (see
 * RestoreActivityGuard's docblock) — this class only ever answers "what
 * does this file honestly say," and throws rather than guessing whenever it
 * can't answer that safely.
 */
final class RestoreProgressReader
{
    /**
     * Defense-in-depth ceiling on the raw file size fed to json_decode(),
     * independent of RestoreProgressSnapshot's own field-level bounds —
     * generous for 50 bounded phase-history entries plus every other
     * field at its maximum length.
     */
    private const MAX_FILE_BYTES = 262144;

    /**
     * @throws RestoreProgressIntegrityException
     */
    public function read(string $restoreUuid): RestoreProgressSnapshot
    {
        $disk = Storage::disk((string) config('oms.backup.restore.disk', 'restores'));
        $path = $restoreUuid.'/progress.json';

        if (! $disk->exists($path)) {
            throw RestoreProgressIntegrityException::missing();
        }

        $absolutePath = $disk->path($path);
        clearstatcache(true, $absolutePath);
        $size = filesize($absolutePath);

        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw RestoreProgressIntegrityException::oversized();
        }

        $raw = file_get_contents($absolutePath);

        if ($raw === false) {
            throw RestoreProgressIntegrityException::malformed();
        }

        return $this->parse($raw, $restoreUuid);
    }

    /**
     * @throws RestoreProgressIntegrityException
     */
    public function parse(string $raw, string $expectedRestoreUuid): RestoreProgressSnapshot
    {
        if (strlen($raw) > self::MAX_FILE_BYTES) {
            throw RestoreProgressIntegrityException::oversized();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw RestoreProgressIntegrityException::malformed();
        }

        $signature = $decoded['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            throw RestoreProgressIntegrityException::unsigned();
        }

        $body = $decoded;
        unset($body['signature']);

        $canonicalJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($canonicalJson === false || ! RestoreProgressSigner::verify($canonicalJson, $signature)) {
            throw RestoreProgressIntegrityException::invalidSignature();
        }

        $schemaVersion = $body['schema_version'] ?? null;
        $expectedSchemaVersion = (int) config('oms.backup.restore.progress_schema_version', 1);

        if ($schemaVersion !== $expectedSchemaVersion) {
            throw RestoreProgressIntegrityException::unsupportedSchemaVersion();
        }

        $restoreUuid = $body['restore_uuid'] ?? null;

        if (! is_string($restoreUuid) || ! hash_equals($expectedRestoreUuid, $restoreUuid)) {
            throw RestoreProgressIntegrityException::uuidMismatch();
        }

        try {
            $requestedBy = $body['requested_by'] ?? null;
            $reconciliationSnapshotData = $body['reconciliation_snapshot'] ?? null;

            // Absent entirely (a progress file written before this field
            // existed) and explicitly null both mean the same thing here —
            // only a genuine array is ever handed to
            // RestoreReconciliationSnapshot::fromArray() for validation.
            $reconciliationSnapshot = is_array($reconciliationSnapshotData)
                ? RestoreReconciliationSnapshot::fromArray($reconciliationSnapshotData)
                : null;

            return RestoreProgressSnapshot::create(
                restoreUuid: $restoreUuid,
                requestedBy: is_array($requestedBy) ? $requestedBy : [],
                requestedAt: (string) ($body['requested_at'] ?? ''),
                reason: (string) ($body['reason'] ?? ''),
                scope: (string) ($body['scope'] ?? ''),
                sourceBackupUuid: (string) ($body['source_backup_uuid'] ?? ''),
                preRestoreSafetyBackupUuid: $this->nullableString($body['pre_restore_safety_backup_uuid'] ?? null),
                phase: (string) ($body['phase'] ?? ''),
                phaseHistory: is_array($body['phase_history'] ?? null) ? $body['phase_history'] : [],
                lastHeartbeatAt: (string) ($body['last_heartbeat_at'] ?? ''),
                result: $this->nullableString($body['result'] ?? null),
                restoreFailedPhase: $this->nullableString($body['restore_failed_phase'] ?? null),
                errorSummary: $this->nullableString($body['error_summary'] ?? null),
                reconciliationSnapshot: $reconciliationSnapshot,
            );
        } catch (InvalidArgumentException|TypeError $e) {
            // TypeError is caught alongside InvalidArgumentException
            // deliberately: a malformed field (e.g. a number where a
            // string was expected) that survives nullableString()'s own
            // coercion below can still reach create()'s strictly-typed
            // parameters — both failure modes mean the same thing here,
            // "this content failed validation," never an uncaught crash.
            throw RestoreProgressIntegrityException::invalidContent($e->getMessage());
        }
    }

    /**
     * @throws InvalidArgumentException when $value is neither null nor a string
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Expected a string or null value.');
        }

        return $value;
    }
}
