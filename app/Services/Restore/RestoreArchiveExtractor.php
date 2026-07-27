<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Services\Backup\Contracts\SymlinkDetector;
use App\Services\Backup\NativeSymlinkDetector;
use App\Services\Backup\Support\SafeBackupPath;
use App\Services\Restore\Exceptions\RestoreArchiveExtractionException;
use ZipArchive;

/**
 * OMS Task 7C.3 — safe, scope-limited extraction of a decrypted, ALREADY
 * FULLY VERIFIED backup archive (BackupArchiveContentVerifier must have
 * already confirmed the exact entry set, per-entry hashes, and manifest
 * schema before this class is ever invoked — it never re-derives "what
 * should be in this archive" from anything but the verified $manifest
 * array it is handed).
 *
 * Never uses ZipArchive::extractTo(). Every destination path is built
 * exclusively from RestoreWorkspace's own safe path resolution — a raw ZIP
 * entry name is never used as a filesystem destination. Every entry this
 * class writes is one this class itself chose, by iterating the verified
 * manifest's own declared components (never the ZIP's raw directory
 * listing) — so an "unexpected" entry present only in the ZIP, or absent
 * only from the ZIP, is caught as a lookup failure rather than ever being
 * silently written.
 */
final class RestoreArchiveExtractor
{
    private const STREAM_CHUNK_BYTES = 1_048_576;

    private const UNIX_FILE_TYPE_MASK = 0170000;

    private const UNIX_SPECIAL_FILE_TYPES = [
        0120000, // S_IFLNK  (symbolic link)
        0140000, // S_IFSOCK (socket)
        0060000, // S_IFBLK  (block device)
        0020000, // S_IFCHR  (character device)
        0010000, // S_IFIFO  (FIFO/pipe)
    ];

    public function __construct(
        private readonly SymlinkDetector $symlinkDetector = new NativeSymlinkDetector(),
    ) {
    }

    /**
     * @param  array<string, mixed>  $manifest  the manifest already returned
     *                                           by a successful
     *                                           BackupArchiveContentVerifier::verify()
     *                                           call against $decryptedZipPath
     *
     * OMS Task 7C.7 hardening pass — $onTick, when given, is invoked once
     * per extracted entry (the dump, and each staged attachment file) so a
     * restore's signed progress heartbeat can stay alive while staging an
     * archive with many/large files.
     *
     * @throws RestoreArchiveExtractionException
     */
    public function extract(string $decryptedZipPath, array $manifest, BackupScope $selectedScope, RestoreWorkspace $workspace, ?callable $onTick = null): RestoreExtractionResult
    {
        $zip = new ZipArchive();

        if ($zip->open($decryptedZipPath) !== true) {
            throw RestoreArchiveExtractionException::readFailed();
        }

        try {
            return $this->extractFromOpenZip($zip, $manifest, $selectedScope, $workspace, $onTick);
        } finally {
            $zip->close();
        }
    }

    private function extractFromOpenZip(ZipArchive $zip, array $manifest, BackupScope $selectedScope, RestoreWorkspace $workspace, ?callable $onTick = null): RestoreExtractionResult
    {
        // OMS Task 7C.3 correction: BackupArchiveContentVerifier already
        // proved the entry set/hashes match at verify() time, but that was
        // a separate call against the same decrypted file — independently
        // re-derive and re-check the FULL expected entry set (the whole
        // archive's manifest-declared components, not merely the ones this
        // selected scope is about to extract) against the CURRENTLY open
        // ZIP's actual listing, immediately before writing any staged
        // content. This rejects a since-changed/extra/missing entry
        // outright rather than merely ignoring it, closing the gap between
        // "verified" and "about to be extracted."
        $this->assertEntrySetMatchesManifest($zip, $manifest);

        $declaredTotal = 0;

        if ($selectedScope->includesDatabase()) {
            $declaredTotal += (int) ($manifest['dump']['size_bytes'] ?? 0);
        }

        if ($selectedScope->includesFiles()) {
            $declaredTotal += (int) ($manifest['attachments']['total_size_bytes'] ?? 0);
        }

        $budgetRemaining = $declaredTotal;
        $extractedTotal = 0;
        $stagedDumpRelativePath = null;
        $stagedAttachmentsCount = 0;

        if ($selectedScope->includesDatabase()) {
            $dump = $manifest['dump'] ?? null;

            if (! is_array($dump) || ! isset($dump['size_bytes'], $dump['sha256'])) {
                throw RestoreArchiveExtractionException::unexpectedEntry();
            }

            $destination = $workspace->stagedDumpPath();
            $extractedTotal += $this->extractSingleEntry($zip, 'database/dump.sql', $destination, (int) $dump['size_bytes'], (string) $dump['sha256'], $workspace, $budgetRemaining);
            $stagedDumpRelativePath = 'database/dump.sql';
            if ($onTick !== null) { $onTick(); }
        }

        if ($selectedScope->includesFiles()) {
            $attachments = $manifest['attachments'] ?? null;

            if (! is_array($attachments) || ! is_array($attachments['files'] ?? null)) {
                throw RestoreArchiveExtractionException::unexpectedEntry();
            }

            $seenNormalizedPaths = [];

            foreach ($attachments['files'] as $file) {
                if (! is_array($file) || ! isset($file['path'], $file['sha256'], $file['size']) || ! is_string($file['path'])) {
                    throw RestoreArchiveExtractionException::unexpectedEntry();
                }

                $relativePath = $file['path'];

                if (! SafeBackupPath::isSafe($relativePath)) {
                    throw RestoreArchiveExtractionException::unsafePath();
                }

                $normalized = $this->normalizePath($relativePath);

                if (isset($seenNormalizedPaths[$normalized])) {
                    throw RestoreArchiveExtractionException::duplicateEntry();
                }

                $seenNormalizedPaths[$normalized] = true;

                $entryName = 'files/attachments/'.$relativePath;
                $destination = $workspace->resolveStagedAttachmentPath($relativePath);

                $extractedTotal += $this->extractSingleEntry($zip, $entryName, $destination, (int) $file['size'], (string) $file['sha256'], $workspace, $budgetRemaining);
                $stagedAttachmentsCount++;
                if ($onTick !== null) { $onTick(); }
            }
        }

        return new RestoreExtractionResult(
            stagedDumpRelativePath: $stagedDumpRelativePath,
            stagedAttachmentsCount: $stagedAttachmentsCount,
            manifestSummary: $this->buildManifestSummary($manifest),
            declaredTotalBytes: $declaredTotal,
            extractedTotalBytes: $extractedTotal,
        );
    }

    /**
     * Independently rebuilds the FULL archive's expected entry set from
     * the verified manifest alone (manifest.json + database/dump.sql when
     * a dump is declared + every files/attachments/{path} when attachments
     * are declared — regardless of the selected restore SCOPE, since this
     * proves the whole archive is still exactly what its own manifest
     * claims, not merely the subset about to be staged) and compares it,
     * sorted, against the currently open ZIP's actual entry listing —
     * mirroring BackupArchiveContentVerifier::verifyExactEntrySet()'s own
     * logic exactly. Every manifest-declared attachment path is also
     * safety- and duplicate-checked here, before it ever contributes to
     * the expected set, so an unsafe or duplicate path is rejected even
     * when the ZIP happens to contain a literally-matching entry name.
     *
     * A mismatch in EITHER direction (an entry present only in the ZIP, or
     * only in the manifest) is rejected outright — never silently ignored.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function assertEntrySetMatchesManifest(ZipArchive $zip, array $manifest): void
    {
        $expectedEntries = ['manifest.json'];

        if (($manifest['dump'] ?? null) !== null) {
            $expectedEntries[] = 'database/dump.sql';
        }

        if (($manifest['attachments'] ?? null) !== null) {
            if (! is_array($manifest['attachments']['files'] ?? null)) {
                throw RestoreArchiveExtractionException::unexpectedEntry();
            }

            $seenNormalizedPaths = [];

            foreach ($manifest['attachments']['files'] as $file) {
                if (! is_array($file) || ! isset($file['path']) || ! is_string($file['path'])) {
                    throw RestoreArchiveExtractionException::unexpectedEntry();
                }

                if (! SafeBackupPath::isSafe($file['path'])) {
                    throw RestoreArchiveExtractionException::unsafePath();
                }

                $normalized = $this->normalizePath($file['path']);

                if (isset($seenNormalizedPaths[$normalized])) {
                    throw RestoreArchiveExtractionException::duplicateEntry();
                }

                $seenNormalizedPaths[$normalized] = true;
                $expectedEntries[] = 'files/attachments/'.$file['path'];
            }
        }

        $actualEntries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $actualEntries[] = $zip->getNameIndex($i);
        }

        sort($expectedEntries);
        sort($actualEntries);

        if ($expectedEntries !== $actualEntries) {
            throw RestoreArchiveExtractionException::unexpectedEntry();
        }
    }

    /**
     * @return int bytes actually written
     */
    private function extractSingleEntry(ZipArchive $zip, string $entryName, string $destinationPath, int $declaredSize, string $declaredSha256, RestoreWorkspace $workspace, int &$budgetRemaining): int
    {
        if (str_ends_with($entryName, '/')) {
            throw RestoreArchiveExtractionException::unexpectedEntry();
        }

        $index = $zip->locateName($entryName);

        if ($index === false) {
            throw RestoreArchiveExtractionException::unexpectedEntry();
        }

        if ($this->isSymlinkOrSpecialZipEntry($zip, $index)) {
            throw RestoreArchiveExtractionException::symlinkEntry();
        }

        $stat = $zip->statIndex($index);

        if ($stat === false || (int) $stat['size'] !== $declaredSize) {
            throw RestoreArchiveExtractionException::sizeMismatch();
        }

        if ($declaredSize > $budgetRemaining) {
            throw RestoreArchiveExtractionException::byteLimitExceeded();
        }

        $this->ensureDirectoryExists(dirname($destinationPath));
        $this->assertNoSymlinkAlongDestinationPath($destinationPath, $workspace);

        $written = $this->streamEntryToDisk($zip, $entryName, $destinationPath, $declaredSize, $declaredSha256);

        $budgetRemaining -= $written;

        return $written;
    }

    private function streamEntryToDisk(ZipArchive $zip, string $entryName, string $destinationPath, int $declaredSize, string $declaredSha256): int
    {
        $stream = $zip->getStream($entryName);

        if ($stream === false) {
            throw RestoreArchiveExtractionException::readFailed();
        }

        $out = @fopen($destinationPath, 'wb');

        if ($out === false) {
            fclose($stream);

            throw RestoreArchiveExtractionException::writeFailed();
        }

        $hashContext = hash_init('sha256');
        $written = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::STREAM_CHUNK_BYTES);

                if ($chunk === false) {
                    throw RestoreArchiveExtractionException::readFailed();
                }

                if ($chunk === '') {
                    continue;
                }

                $written += strlen($chunk);

                if ($written > $declaredSize) {
                    throw RestoreArchiveExtractionException::sizeMismatch();
                }

                hash_update($hashContext, $chunk);

                if (fwrite($out, $chunk) === false) {
                    throw RestoreArchiveExtractionException::writeFailed();
                }
            }
        } finally {
            fclose($stream);
            fclose($out);
        }

        if ($written !== $declaredSize) {
            @unlink($destinationPath);

            throw RestoreArchiveExtractionException::sizeMismatch();
        }

        if (! hash_equals($declaredSha256, hash_final($hashContext))) {
            @unlink($destinationPath);

            throw RestoreArchiveExtractionException::hashMismatch();
        }

        return $written;
    }

    /**
     * Detects a ZIP entry stored with Unix external attributes marking it
     * as a symlink or a device/socket/FIFO special file. An entry whose
     * external attributes cannot be read, or that was stored under a
     * non-Unix OPSYS, is treated as an ordinary file by this check alone —
     * every entry this class writes is still a real backup-manifest-listed
     * regular file by construction (OMS's own BackupArchiveBuilder never
     * archives anything else), so this is defense in depth, not the only
     * guard.
     */
    private function isSymlinkOrSpecialZipEntry(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $unixMode = ($attr >> 16) & 0xFFFF;
        $fileType = $unixMode & self::UNIX_FILE_TYPE_MASK;

        return in_array($fileType, self::UNIX_SPECIAL_FILE_TYPES, true);
    }

    /**
     * Refuses to write through any existing symlink located anywhere
     * between the destination file's immediate parent and the workspace's
     * own root (inclusive) — bounded to the workspace subtree, never an
     * unbounded walk up to the filesystem root.
     */
    private function assertNoSymlinkAlongDestinationPath(string $destinationPath, RestoreWorkspace $workspace): void
    {
        $boundary = rtrim(str_replace('\\', '/', $workspace->absoluteRoot()), '/');
        $current = dirname($destinationPath);

        while (true) {
            if ($this->symlinkDetector->isLink($current)) {
                throw RestoreArchiveExtractionException::symlinkInDestinationPath();
            }

            $normalizedCurrent = rtrim(str_replace('\\', '/', $current), '/');

            if ($normalizedCurrent === $boundary || strlen($normalizedCurrent) <= strlen($boundary)) {
                break;
            }

            $current = dirname($current);
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw RestoreArchiveExtractionException::writeFailed();
        }
    }

    private function normalizePath(string $path): string
    {
        $slashed = str_replace('\\', '/', $path);
        $segments = array_filter(explode('/', $slashed), static fn (string $segment): bool => $segment !== '' && $segment !== '.');

        return implode('/', $segments);
    }

    /**
     * Bounded subset of the verified manifest — no per-file attachment
     * list, no encryption/key fields — safe to carry forward on
     * PreparedRestore.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function buildManifestSummary(array $manifest): array
    {
        return [
            'archive_version' => $manifest['archive_version'] ?? null,
            'backup_uuid' => $manifest['backup_uuid'] ?? null,
            'backup_type' => $manifest['backup_type'] ?? null,
            'backup_scope' => $manifest['backup_scope'] ?? null,
            'created_at' => $manifest['created_at'] ?? null,
            'app_version' => $manifest['app_version'] ?? null,
            'dump_size_bytes' => $manifest['dump']['size_bytes'] ?? null,
            'attachments_file_count' => $manifest['attachments']['file_count'] ?? null,
            'attachments_total_size_bytes' => $manifest['attachments']['total_size_bytes'] ?? null,
        ];
    }
}
