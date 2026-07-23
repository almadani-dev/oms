<?php

namespace App\Services\Backup;

use App\Services\Backup\Contracts\BackupArchiveContentVerifier as BackupArchiveContentVerifierContract;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use ZipArchive;

/**
 * The single implementation of "is this decrypted ZIP archive's content
 * exactly what its own manifest — and the operation it claims to belong
 * to — says it should be." Shared by BackupCreationOrchestrator (verifying
 * an unpublished candidate archive before it is ever published/marked
 * completed) and BackupIntegrityVerifier (re-verifying an already-
 * published backup later). Deliberately the only place these rules live —
 * creation and later verification must never drift apart.
 *
 * Never touches encryption, the filesystem beyond the given plaintext ZIP
 * path, or BackupOperation — pure content verification.
 *
 * `final` — both consumers depend on the
 * App\Services\Backup\Contracts\BackupArchiveContentVerifier interface, not
 * this concrete class, so a test proving "creation halts on a verification
 * failure" injects a fake implementation of that interface instead of
 * subclassing this one. The hash/entry-mismatch rejection cases themselves
 * are covered directly against this class in
 * BackupArchiveContentVerifierTest.
 */
final class BackupArchiveContentVerifier implements BackupArchiveContentVerifierContract
{
    /**
     * @return array<string,mixed> the decoded manifest, on success
     *
     * @throws BackupIntegrityException
     */
    public function verify(string $plainZipPath, string $expectedUuid, string $expectedType, string $expectedScope): array
    {
        $zip = new ZipArchive();

        if ($zip->open($plainZipPath) !== true) {
            throw new BackupIntegrityException('Unable to open the decrypted archive.');
        }

        try {
            $manifest = $this->readManifest($zip, $expectedUuid, $expectedType, $expectedScope);
            $expectedEntries = $this->verifyComponents($zip, $manifest, $expectedScope);
            $this->verifyExactEntrySet($zip, $expectedEntries);

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readManifest(ZipArchive $zip, string $expectedUuid, string $expectedType, string $expectedScope): array
    {
        $manifestJson = $zip->getFromName('manifest.json');

        if ($manifestJson === false) {
            throw new BackupIntegrityException('Decrypted archive is missing manifest.json.');
        }

        $manifest = json_decode($manifestJson, true);

        if (! is_array($manifest) || ($manifest['archive_version'] ?? null) !== BackupManifestBuilder::VERSION) {
            throw new BackupIntegrityException('Backup manifest failed schema/version validation.');
        }

        if (($manifest['backup_uuid'] ?? null) !== $expectedUuid) {
            throw new BackupIntegrityException('Backup manifest UUID does not match this operation.');
        }

        if (($manifest['backup_type'] ?? null) !== $expectedType) {
            throw new BackupIntegrityException('Backup manifest type does not match this operation.');
        }

        if (($manifest['backup_scope'] ?? null) !== $expectedScope) {
            throw new BackupIntegrityException('Backup manifest scope does not match this operation.');
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<string> the full expected archive entry list
     */
    private function verifyComponents(ZipArchive $zip, array $manifest, string $expectedScope): array
    {
        $expectedEntries = ['manifest.json'];
        $scopeNeedsDatabase = in_array($expectedScope, ['database', 'full'], true);
        $scopeNeedsFiles = in_array($expectedScope, ['files', 'full'], true);

        if ($scopeNeedsDatabase) {
            if (($manifest['dump'] ?? null) === null) {
                throw new BackupIntegrityException('Backup scope requires a database dump but the manifest has none.');
            }

            $expectedEntries[] = 'database/dump.sql';
            $actualHash = $this->hashZipEntry($zip, 'database/dump.sql');

            if ($actualHash === null) {
                throw new BackupIntegrityException('Backup archive is missing database/dump.sql.');
            }

            if (! hash_equals((string) $manifest['dump']['sha256'], $actualHash)) {
                throw new BackupIntegrityException('Database dump checksum mismatch.');
            }
        }

        if ($scopeNeedsFiles) {
            if (($manifest['attachments'] ?? null) === null) {
                throw new BackupIntegrityException('Backup scope requires attachment files but the manifest has none.');
            }

            foreach ($manifest['attachments']['files'] as $file) {
                $entryName = 'files/attachments/'.$file['path'];
                $expectedEntries[] = $entryName;

                $actualHash = $this->hashZipEntry($zip, $entryName);

                if ($actualHash === null) {
                    throw new BackupIntegrityException("Backup archive is missing attachment entry: {$file['path']}");
                }

                if (! hash_equals((string) $file['sha256'], $actualHash)) {
                    throw new BackupIntegrityException("Attachment checksum mismatch: {$file['path']}");
                }
            }
        }

        return $expectedEntries;
    }

    /**
     * @param  list<string>  $expectedEntries
     */
    private function verifyExactEntrySet(ZipArchive $zip, array $expectedEntries): void
    {
        $actualEntries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $actualEntries[] = $zip->getNameIndex($i);
        }

        sort($expectedEntries);
        sort($actualEntries);

        if ($expectedEntries !== $actualEntries) {
            throw new BackupIntegrityException('Backup archive contains missing or unexpected entries.');
        }
    }

    /**
     * Streams a single ZIP entry through a SHA-256 hash context — never
     * loads the entry's full content into one PHP string.
     */
    private function hashZipEntry(ZipArchive $zip, string $entryName): ?string
    {
        $stream = $zip->getStream($entryName);

        if ($stream === false) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
