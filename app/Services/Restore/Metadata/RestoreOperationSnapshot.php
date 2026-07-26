<?php

namespace App\Services\Restore\Metadata;

use App\Enums\BackupScope;
use App\Models\BackupOperation;
use App\Services\Restore\RestoreProgressSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * OMS Task 7C.5 — the single bounded, validated value object the restore
 * operation's OWN authoritative backup_operations row is reconstructed from
 * after a database import (see RestoreMetadataReconstructor). Mirrors
 * BackupOperationSnapshot's pattern: no public constructor, create() is the
 * only entry point, every field validated before an instance exists.
 *
 * `status` is deliberately not a constructor parameter — it always remains
 * BackupStatus::Restoring; only the future orchestrator decides the
 * terminal result (Restored/RestorePartial/RestoreFailed), never this
 * reconciliation step.
 *
 * Never carries: a password, an encryption key, a nonce, a confirmation
 * phrase, or raw/unbounded exception text.
 */
final class RestoreOperationSnapshot
{
    public const MAX_REASON_LENGTH = RestoreProgressSnapshot::MAX_REASON_LENGTH;

    public const MAX_NAME_LENGTH = RestoreProgressSnapshot::MAX_NAME_LENGTH;

    public const MAX_EMAIL_LENGTH = RestoreProgressSnapshot::MAX_EMAIL_LENGTH;

    public const MAX_RESULT_CONTEXT_LENGTH = RestoreProgressSnapshot::MAX_ERROR_SUMMARY_LENGTH;

    public const MAX_PHASE_HISTORY_ENTRIES = BackupOperation::RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES;

    private const TIMESTAMP_FORMAT = DATE_ATOM;

    /** @var list<string> canonical toArray()/fromArray() key set — kept in sync deliberately, never derived. */
    private const ARRAY_KEYS = [
        'restore_uuid', 'source_uuid', 'safety_uuid', 'scope', 'requested_by',
        'reason', 'confirmed_at', 'started_at', 'phase_history', 'result_context',
    ];

    /**
     * @param  array{user_id: int|null, name: string, email: string}  $requestedBy
     * @param  list<array{phase: string, at: string}>  $phaseHistory
     */
    private function __construct(
        public readonly string $restoreUuid,
        public readonly string $sourceUuid,
        public readonly ?string $safetyUuid,
        public readonly string $scope,
        public readonly array $requestedBy,
        public readonly string $reason,
        public readonly string $confirmedAt,
        public readonly string $startedAt,
        public readonly array $phaseHistory,
        public readonly ?string $resultContext,
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
        string $sourceUuid,
        ?string $safetyUuid,
        string $scope,
        array $requestedBy,
        string $reason,
        string $confirmedAt,
        string $startedAt,
        array $phaseHistory,
        ?string $resultContext,
    ): self {
        self::assertUuid($restoreUuid, 'restoreUuid');
        self::assertUuid($sourceUuid, 'sourceUuid');

        if ($safetyUuid !== null) {
            self::assertUuid($safetyUuid, 'safetyUuid');
        }

        if (BackupScope::tryFrom($scope) === null) {
            throw new InvalidArgumentException('scope must be a valid BackupScope value.');
        }

        self::assertIdentity($requestedBy);
        self::assertBoundedString($reason, self::MAX_REASON_LENGTH, 'reason');
        self::assertTimestamp($confirmedAt, 'confirmedAt');
        self::assertTimestamp($startedAt, 'startedAt');
        self::assertPhaseHistory($phaseHistory);

        if ($resultContext !== null) {
            self::assertBoundedString($resultContext, self::MAX_RESULT_CONTEXT_LENGTH, 'resultContext');
        }

        return new self(
            restoreUuid: $restoreUuid,
            sourceUuid: $sourceUuid,
            safetyUuid: $safetyUuid,
            scope: $scope,
            requestedBy: [
                'user_id' => $requestedBy['user_id'],
                'name' => $requestedBy['name'],
                'email' => $requestedBy['email'],
            ],
            reason: $reason,
            confirmedAt: $confirmedAt,
            startedAt: $startedAt,
            phaseHistory: $phaseHistory,
            resultContext: $resultContext,
        );
    }

    /**
     * OMS Task 7C.5 correction pass — bounded, fixed-key-order serialization
     * so this snapshot can be embedded inside a signed RestoreProgressSnapshot
     * (see RestoreReconciliationSnapshot) and survive a database replacement.
     * Never includes anything beyond this class's own already-validated
     * fields — no nonce, no confirmation phrase, no raw command/exception
     * text, and never a second full copy of the outer progress envelope.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'restore_uuid' => $this->restoreUuid,
            'source_uuid' => $this->sourceUuid,
            'safety_uuid' => $this->safetyUuid,
            'scope' => $this->scope,
            'requested_by' => $this->requestedBy,
            'reason' => $this->reason,
            'confirmed_at' => $this->confirmedAt,
            'started_at' => $this->startedAt,
            'phase_history' => $this->phaseHistory,
            'result_context' => $this->resultContext,
        ];
    }

    /**
     * The exact inverse of toArray() — re-validates every field through
     * create() exactly as if it had been freshly built. Rejects any array
     * with a missing or an unexpected extra key before looking at a single
     * field's value.
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data);

        return self::create(
            restoreUuid: self::requireString($data, 'restore_uuid'),
            sourceUuid: self::requireString($data, 'source_uuid'),
            safetyUuid: self::optionalString($data, 'safety_uuid'),
            scope: self::requireString($data, 'scope'),
            requestedBy: self::requireArray($data, 'requested_by'),
            reason: self::requireString($data, 'reason'),
            confirmedAt: self::requireString($data, 'confirmed_at'),
            startedAt: self::requireString($data, 'started_at'),
            phaseHistory: self::requireArray($data, 'phase_history'),
            resultContext: self::optionalString($data, 'result_context'),
        );
    }

    private static function assertExactKeys(array $data): void
    {
        $actual = array_keys($data);
        sort($actual);
        $expected = self::ARRAY_KEYS;
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidArgumentException('Snapshot array has missing or unexpected keys.');
        }
    }

    private static function requireString(array $data, string $key): string
    {
        if (! is_string($data[$key])) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        return $data[$key];
    }

    private static function optionalString(array $data, string $key): ?string
    {
        if ($data[$key] === null) {
            return null;
        }

        return self::requireString($data, $key);
    }

    private static function requireArray(array $data, string $key): array
    {
        if (! is_array($data[$key])) {
            throw new InvalidArgumentException("{$key} must be an array.");
        }

        return $data[$key];
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
     * @param  array{user_id?: mixed, name?: mixed, email?: mixed}  $identity
     */
    private static function assertIdentity(array $identity): void
    {
        if (! array_key_exists('user_id', $identity) || ! array_key_exists('name', $identity) || ! array_key_exists('email', $identity)) {
            throw new InvalidArgumentException('requestedBy must include user_id, name, and email keys.');
        }

        if ($identity['user_id'] !== null && ! is_int($identity['user_id'])) {
            throw new InvalidArgumentException('requestedBy.user_id must be an integer or null.');
        }

        if (! is_string($identity['name']) || ! is_string($identity['email'])) {
            throw new InvalidArgumentException('requestedBy.name and requestedBy.email must be strings.');
        }

        self::assertBoundedString($identity['name'], self::MAX_NAME_LENGTH, 'requestedBy.name');
        self::assertBoundedString($identity['email'], self::MAX_EMAIL_LENGTH, 'requestedBy.email');
    }

    /**
     * @param  list<array{phase: string, at: string}>  $phaseHistory
     */
    private static function assertPhaseHistory(array $phaseHistory): void
    {
        if (count($phaseHistory) > self::MAX_PHASE_HISTORY_ENTRIES) {
            throw new InvalidArgumentException('phaseHistory exceeds the maximum allowed entry count of '.self::MAX_PHASE_HISTORY_ENTRIES.'.');
        }

        foreach ($phaseHistory as $entry) {
            if (! is_array($entry) || ! isset($entry['phase'], $entry['at']) || ! is_string($entry['phase']) || ! is_string($entry['at'])) {
                throw new InvalidArgumentException('Each phaseHistory entry must have string phase and at keys.');
            }

            if (! in_array($entry['phase'], RestoreProgressSnapshot::ALLOWED_PHASES, true)) {
                throw new InvalidArgumentException('Invalid value for phaseHistory.phase: '.$entry['phase'].'.');
            }

            self::assertTimestamp($entry['at'], 'phaseHistory.at');
        }
    }
}
