<?php

namespace App\Services\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupType;
use App\Services\Backup\Support\SafeBackupPath;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Builds the manifest.json array embedded inside the ZIP archive (before
 * encryption). The manifest's own integrity is protected purely by living
 * inside the authenticated Secretstream envelope — it deliberately does
 * NOT contain a hash of the archive it is itself part of (that would be
 * circular/self-referential, per the approved design). The *encrypted
 * file's* SHA-256 is calculated separately, after encryption, and stored
 * only in BackupOperation::checksum_sha256 — never inside the manifest.
 *
 * Never includes: encryption secrets, database credentials, or absolute
 * filesystem paths.
 */
final class BackupManifestBuilder
{
    public const VERSION = 1;

    /**
     * Static, informational only — the actual archive only ever contains
     * what CURRENT scope produced (see included_paths); this list documents
     * the standing exclusion policy regardless of scope.
     */
    private const EXCLUDED_PATHS = [
        '.env',
        'vendor',
        'node_modules',
        'graphify-out',
        'storage/framework',
        'storage/logs',
        'storage/app/livewire-tmp',
        'storage/app/private/backups',
        'storage/app/public',
        'public/storage',
        'tests',
        'database/database.sqlite',
    ];

    public function build(
        string $uuid,
        BackupType $type,
        BackupScope $scope,
        CarbonImmutable $createdAt,
        string $encryptionKeyId,
        int $envelopeVersion,
        ?DumpResult $dump,
        ?AttachmentCollectionResult $attachments,
    ): array {
        $includedPaths = [];

        if ($scope->includesDatabase() && $dump !== null) {
            $includedPaths[] = 'database/dump.sql';
        }

        if ($scope->includesFiles() && $attachments !== null) {
            $includedPaths[] = 'files/attachments';
        }

        return [
            'archive_version' => self::VERSION,
            'created_at' => $createdAt->toIso8601String(),
            'backup_uuid' => $uuid,
            'backup_type' => $type->value,
            'backup_scope' => $scope->value,
            'app_version' => $this->resolveAppVersion(),
            'database_identifier' => $scope->includesDatabase()
                ? (string) config('database.connections.'.$this->resolveConnectionName().'.database')
                : null,
            'included_paths' => $includedPaths,
            'excluded_paths' => self::EXCLUDED_PATHS,
            'dump' => $dump === null ? null : [
                'sha256' => $dump->sha256,
                'size_bytes' => $dump->sizeBytes,
            ],
            'attachments' => $attachments === null ? null : [
                'file_count' => $attachments->fileCount,
                'total_size_bytes' => $attachments->totalSizeBytes,
                'files' => array_map(
                    static fn (array $file): array => [
                        'path' => $file['path'],
                        'sha256' => $file['sha256'],
                        'size' => $file['size'],
                    ],
                    $attachments->files,
                ),
            ],
            'encryption' => [
                'algorithm' => 'xchacha20poly1305-secretstream',
                'envelope_version' => $envelopeVersion,
                'key_id' => $encryptionKeyId,
            ],
        ];
    }

    /**
     * Best-effort short git commit hash, purely informational. Never throws
     * — any failure (git missing, not a repository, timeout) yields null,
     * exactly as the manifest schema allows.
     */
    private function resolveAppVersion(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--short=12', 'HEAD'], base_path());
            $process->setTimeout(5);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $hash = trim($process->getOutput());

            return $hash !== '' ? $hash : null;
        } catch (ProcessExceptionInterface) {
            return null;
        }
    }

    private function resolveConnectionName(): string
    {
        return (string) (config('oms.backup.database_connection') ?: config('database.default'));
    }

    /**
     * @internal exposed for BackupArchiveBuilder's own path-safety checks.
     */
    public static function assertSafeEntryName(string $entryName): void
    {
        if (! SafeBackupPath::isSafe($entryName)) {
            throw new \RuntimeException("Unsafe archive entry name rejected: {$entryName}");
        }
    }
}
