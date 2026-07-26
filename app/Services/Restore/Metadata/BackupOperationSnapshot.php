<?php

namespace App\Services\Restore\Metadata;

use App\Enums\BackupScope;
use App\Enums\BackupType;
use App\Services\Backup\Support\SafeBackupPath;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * OMS Task 7C.5 — the single bounded, validated value object an authoritative
 * source-backup or safety-backup row is reconstructed from after a database
 * import (see RestoreMetadataReconstructor). There is no public constructor
 * and no way to build one from an arbitrary array — create() is the only
 * entry point, and it validates every field before returning an instance,
 * mirroring RestoreProgressSnapshot's own established pattern.
 *
 * `status` is deliberately not a constructor parameter: every snapshot this
 * class can produce is, by construction, an authoritative COMPLETED and
 * VERIFIED backup — never a stale Running/Verifying/Failed state carried
 * over from the imported SQL dump (see the OMS Task 7C.5 upsert design).
 *
 * Never carries: a password, an encryption key, a nonce, a confirmation
 * phrase, or raw/unbounded exception text — nothing here has a field for
 * any of those, by construction.
 */
final class BackupOperationSnapshot
{
    public const MAX_STRING_LENGTH = 2000;

    public const MAX_NAME_LENGTH = 255;

    public const MAX_EMAIL_LENGTH = 255;

    /** Matches backup_operations.disk's column width exactly. */
    public const MAX_DISK_LENGTH = 32;

    /** Matches backup_operations.encrypted_filename's column width exactly. */
    public const MAX_ARCHIVE_FILENAME_LENGTH = 255;

    /** Matches backup_operations.encryption_key_id's column width exactly. */
    public const MAX_ENCRYPTION_KEY_ID_LENGTH = 64;

    /** Matches backup_operations.manifest_version's unsignedSmallInteger column. */
    public const MAX_MANIFEST_VERSION = 65535;

    private const TIMESTAMP_FORMAT = DATE_ATOM;

    /** @var list<string> canonical toArray()/fromArray() key set — kept in sync deliberately, never derived. */
    private const ARRAY_KEYS = [
        'uuid', 'type', 'scope', 'disk', 'archive_path', 'archive_filename',
        'size_bytes', 'checksum_sha256', 'encryption_key_id', 'manifest_version',
        'file_count', 'original_size_bytes', 'created_at', 'started_at',
        'completed_at', 'verified_at', 'created_by', 'is_protected', 'operation_reason',
    ];

    /**
     * @param  array{user_id: int|null, name: string, email: string}  $createdBy
     */
    private function __construct(
        public readonly string $uuid,
        public readonly string $type,
        public readonly string $scope,
        public readonly string $disk,
        public readonly string $archivePath,
        public readonly ?string $archiveFilename,
        public readonly ?int $sizeBytes,
        public readonly ?string $checksumSha256,
        public readonly ?string $encryptionKeyId,
        public readonly ?int $manifestVersion,
        public readonly ?int $fileCount,
        public readonly ?int $originalSizeBytes,
        public readonly string $createdAt,
        public readonly string $startedAt,
        public readonly string $completedAt,
        public readonly string $verifiedAt,
        public readonly array $createdBy,
        public readonly bool $isProtected,
        public readonly ?string $operationReason,
    ) {
    }

    /**
     * @param  array{user_id: int|null, name: string, email: string}  $createdBy
     *
     * @throws InvalidArgumentException
     */
    public static function create(
        string $uuid,
        string $type,
        string $scope,
        string $disk,
        string $archivePath,
        ?string $archiveFilename,
        ?int $sizeBytes,
        ?string $checksumSha256,
        ?string $encryptionKeyId,
        ?int $manifestVersion,
        ?int $fileCount,
        ?int $originalSizeBytes,
        string $createdAt,
        string $startedAt,
        string $completedAt,
        string $verifiedAt,
        array $createdBy,
        bool $isProtected,
        ?string $operationReason,
    ): self {
        self::assertUuid($uuid, 'uuid');

        $backupType = BackupType::tryFrom($type);

        if ($backupType === null || $backupType === BackupType::Restore) {
            throw new InvalidArgumentException('type must be a valid non-restore BackupType value.');
        }

        if (BackupScope::tryFrom($scope) === null) {
            throw new InvalidArgumentException('scope must be a valid BackupScope value.');
        }

        if (trim($disk) === '') {
            throw new InvalidArgumentException('disk must not be empty.');
        }

        self::assertBoundedString($disk, self::MAX_DISK_LENGTH, 'disk');

        if (! SafeBackupPath::isSafe($archivePath)) {
            throw new InvalidArgumentException('archivePath must be a safe, disk-relative path.');
        }

        if ($archiveFilename !== null) {
            self::assertBoundedString($archiveFilename, self::MAX_ARCHIVE_FILENAME_LENGTH, 'archiveFilename');
        }

        if ($sizeBytes !== null && $sizeBytes < 0) {
            throw new InvalidArgumentException('sizeBytes must not be negative.');
        }

        if ($checksumSha256 !== null && preg_match('/^[0-9a-f]{64}$/i', $checksumSha256) !== 1) {
            throw new InvalidArgumentException('checksumSha256 must be a 64-character hex string.');
        }

        if ($encryptionKeyId !== null) {
            self::assertBoundedString($encryptionKeyId, self::MAX_ENCRYPTION_KEY_ID_LENGTH, 'encryptionKeyId');
        }

        if ($manifestVersion !== null && ($manifestVersion < 0 || $manifestVersion > self::MAX_MANIFEST_VERSION)) {
            throw new InvalidArgumentException('manifestVersion must be between 0 and '.self::MAX_MANIFEST_VERSION.'.');
        }

        if ($fileCount !== null && $fileCount < 0) {
            throw new InvalidArgumentException('fileCount must not be negative.');
        }

        if ($originalSizeBytes !== null && $originalSizeBytes < 0) {
            throw new InvalidArgumentException('originalSizeBytes must not be negative.');
        }

        self::assertTimestamp($createdAt, 'createdAt');
        self::assertTimestamp($startedAt, 'startedAt');
        self::assertTimestamp($completedAt, 'completedAt');
        self::assertTimestamp($verifiedAt, 'verifiedAt');
        self::assertIdentity($createdBy);

        if ($operationReason !== null) {
            self::assertBoundedString($operationReason, self::MAX_STRING_LENGTH, 'operationReason');
        }

        return new self(
            uuid: $uuid,
            type: $type,
            scope: $scope,
            disk: $disk,
            archivePath: $archivePath,
            archiveFilename: $archiveFilename,
            sizeBytes: $sizeBytes,
            checksumSha256: $checksumSha256,
            encryptionKeyId: $encryptionKeyId,
            manifestVersion: $manifestVersion,
            fileCount: $fileCount,
            originalSizeBytes: $originalSizeBytes,
            createdAt: $createdAt,
            startedAt: $startedAt,
            completedAt: $completedAt,
            verifiedAt: $verifiedAt,
            createdBy: [
                'user_id' => $createdBy['user_id'],
                'name' => $createdBy['name'],
                'email' => $createdBy['email'],
            ],
            isProtected: $isProtected,
            operationReason: $operationReason,
        );
    }

    /**
     * OMS Task 7C.5 correction pass — bounded, fixed-key-order serialization
     * so this snapshot can be embedded inside a signed RestoreProgressSnapshot
     * (see RestoreReconciliationSnapshot) and survive a database replacement.
     * Never includes anything beyond this class's own already-validated
     * fields — no key material, no nonce, no confirmation phrase, no raw
     * command/exception text.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'scope' => $this->scope,
            'disk' => $this->disk,
            'archive_path' => $this->archivePath,
            'archive_filename' => $this->archiveFilename,
            'size_bytes' => $this->sizeBytes,
            'checksum_sha256' => $this->checksumSha256,
            'encryption_key_id' => $this->encryptionKeyId,
            'manifest_version' => $this->manifestVersion,
            'file_count' => $this->fileCount,
            'original_size_bytes' => $this->originalSizeBytes,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'verified_at' => $this->verifiedAt,
            'created_by' => $this->createdBy,
            'is_protected' => $this->isProtected,
            'operation_reason' => $this->operationReason,
        ];
    }

    /**
     * The exact inverse of toArray() — re-validates every field through
     * create() exactly as if it had been freshly built, never trusting a
     * decoded array's shape. Rejects any array with a missing or an
     * unexpected extra key (never an arbitrary raw array) before looking at
     * a single field's value.
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data);

        return self::create(
            uuid: self::requireString($data, 'uuid'),
            type: self::requireString($data, 'type'),
            scope: self::requireString($data, 'scope'),
            disk: self::requireString($data, 'disk'),
            archivePath: self::requireString($data, 'archive_path'),
            archiveFilename: self::optionalString($data, 'archive_filename'),
            sizeBytes: self::optionalInt($data, 'size_bytes'),
            checksumSha256: self::optionalString($data, 'checksum_sha256'),
            encryptionKeyId: self::optionalString($data, 'encryption_key_id'),
            manifestVersion: self::optionalInt($data, 'manifest_version'),
            fileCount: self::optionalInt($data, 'file_count'),
            originalSizeBytes: self::optionalInt($data, 'original_size_bytes'),
            createdAt: self::requireString($data, 'created_at'),
            startedAt: self::requireString($data, 'started_at'),
            completedAt: self::requireString($data, 'completed_at'),
            verifiedAt: self::requireString($data, 'verified_at'),
            createdBy: self::requireIdentityArray($data, 'created_by'),
            isProtected: self::requireBool($data, 'is_protected'),
            operationReason: self::optionalString($data, 'operation_reason'),
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

    private static function optionalInt(array $data, string $key): ?int
    {
        if ($data[$key] === null) {
            return null;
        }

        if (! is_int($data[$key])) {
            throw new InvalidArgumentException("{$key} must be an integer or null.");
        }

        return $data[$key];
    }

    private static function requireBool(array $data, string $key): bool
    {
        if (! is_bool($data[$key])) {
            throw new InvalidArgumentException("{$key} must be a boolean.");
        }

        return $data[$key];
    }

    /**
     * @return array{user_id: int|null, name: string, email: string}
     */
    private static function requireIdentityArray(array $data, string $key): array
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
            throw new InvalidArgumentException('createdBy must include user_id, name, and email keys.');
        }

        if ($identity['user_id'] !== null && ! is_int($identity['user_id'])) {
            throw new InvalidArgumentException('createdBy.user_id must be an integer or null.');
        }

        if (! is_string($identity['name']) || ! is_string($identity['email'])) {
            throw new InvalidArgumentException('createdBy.name and createdBy.email must be strings.');
        }

        self::assertBoundedString($identity['name'], self::MAX_NAME_LENGTH, 'createdBy.name');
        self::assertBoundedString($identity['email'], self::MAX_EMAIL_LENGTH, 'createdBy.email');
    }
}
