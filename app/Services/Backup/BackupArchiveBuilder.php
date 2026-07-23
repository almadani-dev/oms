<?php

namespace App\Services\Backup;

use App\Services\Backup\Contracts\SymlinkDetector;
use App\Services\Backup\Support\SafeBackupPath;
use RuntimeException;
use ZipArchive;

/**
 * Builds the plaintext ZIP archive (manifest.json + database/dump.sql +
 * files/attachments/**) at an absolute destination path, before it is
 * handed to SecretstreamEnvelope::encryptFile(). ZipArchive is used
 * specifically because it writes its central directory/entries to disk
 * incrementally rather than requiring the whole archive to be assembled in
 * PHP memory first.
 *
 * Every entry name is validated by SafeBackupPath before being added —
 * '..', absolute paths, drive-letter paths, and null bytes are all
 * rejected outright, regardless of where the name originated.
 */
final class BackupArchiveBuilder
{
    private ZipArchive $zip;

    private readonly SymlinkDetector $symlinkDetector;

    public function __construct(private readonly string $destinationAbsolutePath, ?SymlinkDetector $symlinkDetector = null)
    {
        $this->symlinkDetector = $symlinkDetector ?? new NativeSymlinkDetector();
        $this->zip = new ZipArchive();

        $result = $this->zip->open($this->destinationAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException("Unable to create backup archive at {$this->destinationAbsolutePath} (code {$result}).");
        }
    }

    public function addManifest(array $manifest): self
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode backup manifest as JSON.');
        }

        $this->addString('manifest.json', $json);

        return $this;
    }

    public function addDatabaseDump(string $absoluteDumpPath): self
    {
        $this->addFile('database/dump.sql', $absoluteDumpPath);

        return $this;
    }

    /**
     * @param  list<array{path: string, sha256: string, size: int}>  $files
     */
    public function addAttachmentFiles(array $files, string $attachmentsDiskRoot): self
    {
        foreach ($files as $file) {
            $absolute = rtrim($attachmentsDiskRoot, '/\\').DIRECTORY_SEPARATOR.$file['path'];
            $this->addFile('files/attachments/'.$file['path'], $absolute);
        }

        return $this;
    }

    public function close(): void
    {
        if (! $this->zip->close()) {
            throw new RuntimeException('Unable to finalize the backup archive.');
        }
    }

    private function addString(string $entryName, string $contents): void
    {
        BackupManifestBuilder::assertSafeEntryName($entryName);

        if (! $this->zip->addFromString($entryName, $contents)) {
            throw new RuntimeException("Unable to add archive entry: {$entryName}");
        }
    }

    private function addFile(string $entryName, string $absoluteSourcePath): void
    {
        BackupManifestBuilder::assertSafeEntryName($entryName);

        if ($this->symlinkDetector->isLink($absoluteSourcePath)) {
            throw new RuntimeException("Refusing to archive a symlink: {$entryName}");
        }

        if (! is_file($absoluteSourcePath)) {
            throw new RuntimeException("Archive source file does not exist: {$entryName}");
        }

        if (! $this->zip->addFile($absoluteSourcePath, $entryName)) {
            throw new RuntimeException("Unable to add archive entry: {$entryName}");
        }
    }
}
