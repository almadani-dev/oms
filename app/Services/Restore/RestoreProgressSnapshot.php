<?php

namespace App\Services\Restore;

use App\Models\BackupOperation;
use App\Services\Restore\Metadata\RestoreReconciliationSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * OMS Task 7C.2 — the single bounded, validated value object every restore
 * progress file's business content is built from. There is no public
 * constructor: create() is the only way to obtain an instance, and it
 * validates every field before returning one — a writer can never be
 * handed an arbitrary, unvalidated array (RestoreProgressWriter's only
 * parameter is this class, never `array`).
 *
 * Deliberately excludes `schema_version` and `signature` — those are
 * write-time/verification-time concerns owned by RestoreProgressWriter and
 * RestoreProgressReader respectively, not business data this value object
 * itself is responsible for.
 *
 * Never carries: a confirmation phrase, a password, an encryption key, a
 * raw command line, a raw stack trace, or an unbounded exception message —
 * nothing here has a field for any of those, by construction.
 *
 * OMS Task 7C.5 correction pass: `reconciliationSnapshot` is an OPTIONAL,
 * nullable, bounded `RestoreReconciliationSnapshot` — the authoritative
 * source-backup/safety-backup/restore-operation metadata a future
 * orchestrator writes into the signed progress file immediately BEFORE the
 * database import runs, so RestoreMetadataUpserter can still reconstruct
 * all three `backup_operations` rows even if the process crashes after
 * import and the original database (and its live rows) is gone. Backward
 * compatible by construction: a progress file written before this field
 * existed simply has no `reconciliation_snapshot` key at all, which decodes
 * to `null` here exactly like an explicit absence — no schema_version bump
 * was needed. RestoreProgressReader's signature check verifies the raw
 * decoded body exactly as persisted, never a value freshly rebuilt from
 * this class's current shape, so an old file's signature is unaffected by
 * this field's addition either way.
 */
final class RestoreProgressSnapshot
{
    public const ALLOWED_SCOPES = ['database', 'files', 'full'];

    /**
     * The full phase vocabulary of the approved Task 7C restore flow
     * (corrected ordering: preflight before maintenance mode, attachments
     * staged/swapped before the database import). Orchestration logic
     * itself is not implemented until Task 7C.6+ — this list exists now so
     * the progress-file schema is already fixed and cannot silently drift
     * once that phase is built.
     *
     * OMS Task 7C.4 correction pass: `launching` was added ahead of
     * `lock_acquired` — RestoreLaunchService (the parent web request) writes
     * the INITIAL progress file the moment it claims the row, which is
     * strictly before the detached `oms:restore` child has acquired its own
     * lifetime exclusive BackupSubsystemLock (it only retries for that
     * after being spawned). Reporting `lock_acquired` during that gap would
     * be inaccurate — nothing has acquired the lifetime lock yet. Only the
     * command itself, after truly acquiring that lock, ever writes
     * `lock_acquired`.
     *
     * OMS Task 7C.8 — `crashed_acknowledged` was added as a
     * `restore_failed_phase`-only value (never written to `phase` or
     * `phase_history`): it is what the explicit, human-reviewed Super Admin
     * "confirm this stale restore is stopped" action
     * (RestoreStaleAcknowledgmentService) writes so the terminalized record
     * is honestly distinguishable from a restore the engine itself decided
     * to fail. Purely additive — an older progress file with no such value
     * decodes exactly as before.
     */
    public const ALLOWED_PHASES = [
        'launching',
        'lock_acquired',
        'validating',
        'preflight',
        'maintenance_enabled',
        'safety_backup_running',
        'safety_backup_completed',
        'staging',
        'attachments_swapped',
        'database_restoring',
        'database_restored',
        'reconciling',
        'finalizing',
        'maintenance_disabled',
        'restored',
        'restore_partial',
        'restore_failed',
        'crashed_acknowledged',
    ];

    public const ALLOWED_RESULTS = ['restored', 'restore_partial', 'restore_failed'];

    public const MAX_REASON_LENGTH = 2000;

    public const MAX_NAME_LENGTH = 255;

    public const MAX_EMAIL_LENGTH = 255;

    public const MAX_ERROR_SUMMARY_LENGTH = 2000;

    /**
     * Reuses the same bound BackupOperation::restore_metadata already
     * documented in Task 7C.1 — one single source of truth for "how many
     * phase-history entries are ever allowed," never two separately
     * maintained numbers.
     */
    public const MAX_PHASE_HISTORY_ENTRIES = BackupOperation::RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES;

    /**
     * Canonical timestamp format — DATE_ATOM (e.g. 2026-07-23T10:00:00+00:00).
     * A single fixed format, never multiple accepted variants, so the
     * signed canonical JSON is always byte-identical for the same logical
     * moment.
     */
    public const TIMESTAMP_FORMAT = DATE_ATOM;

    /**
     * @param  array{user_id: int|null, name: string, email: string}  $requestedBy
     * @param  list<array{phase: string, at: string}>  $phaseHistory
     */
    private function __construct(
        public readonly string $restoreUuid,
        public readonly array $requestedBy,
        public readonly string $requestedAt,
        public readonly string $reason,
        public readonly string $scope,
        public readonly string $sourceBackupUuid,
        public readonly ?string $preRestoreSafetyBackupUuid,
        public readonly string $phase,
        public readonly array $phaseHistory,
        public readonly string $lastHeartbeatAt,
        public readonly ?string $result,
        public readonly ?string $restoreFailedPhase,
        public readonly ?string $errorSummary,
        public readonly ?RestoreReconciliationSnapshot $reconciliationSnapshot,
    ) {
    }

    /**
     * @param  array{user_id: int|null, name: string, email: string}  $requestedBy
     * @param  list<array{phase: string, at: string}>  $phaseHistory
     *
     * @throws InvalidArgumentException
     */
    public static function create(
        string $restoreUuid,
        array $requestedBy,
        string $requestedAt,
        string $reason,
        string $scope,
        string $sourceBackupUuid,
        ?string $preRestoreSafetyBackupUuid,
        string $phase,
        array $phaseHistory,
        string $lastHeartbeatAt,
        ?string $result,
        ?string $restoreFailedPhase,
        ?string $errorSummary,
        ?RestoreReconciliationSnapshot $reconciliationSnapshot = null,
    ): self {
        self::assertUuid($restoreUuid, 'restore_uuid');
        self::assertRequestedBy($requestedBy);
        self::assertTimestamp($requestedAt, 'requested_at');
        self::assertBoundedString($reason, self::MAX_REASON_LENGTH, 'reason');
        self::assertAllowed($scope, self::ALLOWED_SCOPES, 'scope');
        self::assertUuid($sourceBackupUuid, 'source_backup_uuid');

        if ($preRestoreSafetyBackupUuid !== null) {
            self::assertUuid($preRestoreSafetyBackupUuid, 'pre_restore_safety_backup_uuid');
        }

        self::assertAllowed($phase, self::ALLOWED_PHASES, 'phase');
        self::assertPhaseHistory($phaseHistory);
        self::assertTimestamp($lastHeartbeatAt, 'last_heartbeat_at');

        if ($result !== null) {
            self::assertAllowed($result, self::ALLOWED_RESULTS, 'result');
        }

        if ($restoreFailedPhase !== null) {
            self::assertAllowed($restoreFailedPhase, self::ALLOWED_PHASES, 'restore_failed_phase');
        }

        if ($errorSummary !== null) {
            self::assertBoundedString($errorSummary, self::MAX_ERROR_SUMMARY_LENGTH, 'error_summary');
        }

        return new self(
            restoreUuid: $restoreUuid,
            requestedBy: [
                'user_id' => $requestedBy['user_id'],
                'name' => $requestedBy['name'],
                'email' => $requestedBy['email'],
            ],
            requestedAt: $requestedAt,
            reason: $reason,
            scope: $scope,
            sourceBackupUuid: $sourceBackupUuid,
            preRestoreSafetyBackupUuid: $preRestoreSafetyBackupUuid,
            phase: $phase,
            phaseHistory: $phaseHistory,
            lastHeartbeatAt: $lastHeartbeatAt,
            result: $result,
            restoreFailedPhase: $restoreFailedPhase,
            errorSummary: $errorSummary,
            reconciliationSnapshot: $reconciliationSnapshot,
        );
    }

    public function isTerminal(): bool
    {
        return $this->result !== null;
    }

    /**
     * Fixed key order, business fields only (no schema_version, no
     * signature) — the exact structure RestoreProgressWriter/Reader must
     * serialize identically on both sides of a signature check.
     *
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'restore_uuid' => $this->restoreUuid,
            'requested_by' => $this->requestedBy,
            'requested_at' => $this->requestedAt,
            'reason' => $this->reason,
            'scope' => $this->scope,
            'source_backup_uuid' => $this->sourceBackupUuid,
            'pre_restore_safety_backup_uuid' => $this->preRestoreSafetyBackupUuid,
            'phase' => $this->phase,
            'phase_history' => $this->phaseHistory,
            'last_heartbeat_at' => $this->lastHeartbeatAt,
            'result' => $this->result,
            'restore_failed_phase' => $this->restoreFailedPhase,
            'error_summary' => $this->errorSummary,
            'reconciliation_snapshot' => $this->reconciliationSnapshot?->toArray(),
        ];
    }

    private static function assertUuid(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid UUID for {$field}.");
        }
    }

    private static function assertTimestamp(string $value, string $field): void
    {
        if (DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $value) === false) {
            throw new InvalidArgumentException("Invalid timestamp for {$field} (expected DATE_ATOM format).");
        }
    }

    private static function assertBoundedString(string $value, int $max, string $field): void
    {
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$field} exceeds the maximum allowed length of {$max}.");
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function assertAllowed(string $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid value for {$field}: {$value}.");
        }
    }

    /**
     * @param  array{user_id?: mixed, name?: mixed, email?: mixed}  $requestedBy
     */
    private static function assertRequestedBy(array $requestedBy): void
    {
        if (! array_key_exists('user_id', $requestedBy) || ! array_key_exists('name', $requestedBy) || ! array_key_exists('email', $requestedBy)) {
            throw new InvalidArgumentException('requested_by must include user_id, name, and email keys.');
        }

        if ($requestedBy['user_id'] !== null && ! is_int($requestedBy['user_id'])) {
            throw new InvalidArgumentException('requested_by.user_id must be an integer or null.');
        }

        if (! is_string($requestedBy['name']) || ! is_string($requestedBy['email'])) {
            throw new InvalidArgumentException('requested_by.name and requested_by.email must be strings.');
        }

        self::assertBoundedString($requestedBy['name'], self::MAX_NAME_LENGTH, 'requested_by.name');
        self::assertBoundedString($requestedBy['email'], self::MAX_EMAIL_LENGTH, 'requested_by.email');
    }

    /**
     * @param  list<array{phase: string, at: string}>  $phaseHistory
     */
    private static function assertPhaseHistory(array $phaseHistory): void
    {
        if (count($phaseHistory) > self::MAX_PHASE_HISTORY_ENTRIES) {
            throw new InvalidArgumentException('phase_history exceeds the maximum allowed entry count of '.self::MAX_PHASE_HISTORY_ENTRIES.'.');
        }

        foreach ($phaseHistory as $entry) {
            if (! is_array($entry) || ! isset($entry['phase'], $entry['at']) || ! is_string($entry['phase']) || ! is_string($entry['at'])) {
                throw new InvalidArgumentException('Each phase_history entry must have string phase and at keys.');
            }

            self::assertAllowed($entry['phase'], self::ALLOWED_PHASES, 'phase_history.phase');
            self::assertTimestamp($entry['at'], 'phase_history.at');
        }
    }
}
