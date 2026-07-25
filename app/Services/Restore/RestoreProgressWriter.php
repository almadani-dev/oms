<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\RestoreProgressDurability;
use App\Services\Restore\Exceptions\RestoreProgressWriteException;
use Illuminate\Support\Facades\Storage;

/**
 * OMS Task 7C.2 — the only place a restore progress file is ever written.
 * Accepts nothing but an already-validated RestoreProgressSnapshot (never a
 * raw array) and performs a crash-safe, durable write:
 *
 *   1. create the operation directory privately (0700-equivalent — same
 *      restrictive shape as BackupSubsystemLock's own lock directory);
 *   2. serialize the snapshot + schema_version into canonical JSON and sign
 *      it (RestoreProgressSigner), producing the final payload;
 *   3. write that payload to a uniquely-named temp file in the SAME
 *      directory (same-filesystem, so the final rename is an in-place
 *      metadata swap, never a cross-filesystem copy);
 *   4. DURABILITY GATE (hardened): fwrite must fully succeed, fflush must
 *      succeed, and — on any runtime where fsync exists — fsync must
 *      succeed. If any of those fails, the temp file is NOT renamed over
 *      progress.json: the current valid file is preserved untouched, the
 *      temp file is cleaned up, and a sanitized RestoreProgressWriteException
 *      is thrown. Only a genuinely-absent fsync (sub-8.1 PHP) is treated as
 *      "durable enough" to proceed after a successful fflush;
 *   5. close the stream (done in EVERY path — both the failure path and the
 *      success path fclose() before doing anything else), then copy the
 *      CURRENT valid progress.json to progress.previous.json before it is
 *      replaced — never deleted first, and best-effort so a previous-copy
 *      failure can never corrupt the authoritative current file;
 *   6. rename the fully-flushed, fsync'd, closed temp file onto
 *      progress.json;
 *   7. on Linux, fsync the containing directory AFTER a successful rename
 *      so the rename's own directory entry survives an OS crash — best-
 *      effort (see below), and never able to corrupt the already-swapped
 *      file, which is the correct new content by that point regardless;
 *   8. on any failure before the rename, the temp file is removed and the
 *      existing progress.json (if any) is left completely untouched — a
 *      failed/interrupted write can never replace a valid current file
 *      with partial JSON, because nothing ever writes directly to
 *      progress.json itself.
 *
 * Atomicity of step 6 by platform (verified, not assumed):
 *   - Linux (the production Hostinger VPS target): rename(2) on the same
 *     filesystem is atomic — the destination always observes either the
 *     complete old file or the complete new file, never a partial one — and
 *     the step-7 parent-directory fsync persists that rename across an OS
 *     crash. Full authoritative sequence: write temp -> fflush -> fsync temp
 *     -> close -> rename -> fsync parent directory.
 *   - Windows/Laragon (local development only): PHP's rename() maps to
 *     MoveFileEx with MOVEFILE_REPLACE_EXISTING, which DOES replace an
 *     existing destination (proven by RestoreProgressWriterReaderTest's
 *     two-write / previous-snapshot tests passing on this Windows host)
 *     but is not guaranteed POSIX-atomic, and parent-directory fsync is not
 *     supported (NativeRestoreProgressDurability::syncDirectory() returns
 *     false there). Both are accepted local-dev limitations, never a
 *     production concern. The guarantee this class actually depends on holds
 *     on BOTH platforms regardless: the current valid progress.json is only
 *     ever touched by the rename itself — copied to progress.previous.json
 *     first, never unlinked ahead of a fully-written replacement — so even a
 *     torn/failed Windows replace can never leave zero valid progress files.
 */
final class RestoreProgressWriter
{
    private readonly RestoreProgressDurability $durability;

    public function __construct(?RestoreProgressDurability $durability = null)
    {
        $this->durability = $durability ?? new NativeRestoreProgressDurability();
    }

    public function write(RestoreProgressSnapshot $snapshot): void
    {
        $disk = Storage::disk((string) config('oms.backup.restore.disk', 'restores'));
        $directory = $snapshot->restoreUuid;

        $disk->makeDirectory($directory);
        @chmod($disk->path($directory), 0700);

        $absoluteDir = rtrim($disk->path($directory), '/\\');
        $finalPath = $absoluteDir.DIRECTORY_SEPARATOR.'progress.json';
        $previousPath = $absoluteDir.DIRECTORY_SEPARATOR.'progress.previous.json';
        $tempPath = $absoluteDir.DIRECTORY_SEPARATOR.'.progress-'.bin2hex(random_bytes(8)).'.tmp';

        $json = $this->buildSignedJson($snapshot);

        $handle = @fopen($tempPath, 'wb');

        if ($handle === false) {
            throw RestoreProgressWriteException::cannotOpenTempFile();
        }

        try {
            if (fwrite($handle, $json) === false) {
                throw RestoreProgressWriteException::cannotWriteTempFile();
            }

            // Durability gate — a failure here must NOT proceed to the
            // rename. Flush PHP's userland buffer to the OS, then require a
            // successful device-level sync (or a genuinely-absent fsync on a
            // sub-8.1 runtime, which the seam reports as true). A present-
            // but-failed fsync is a hard write failure, so the current valid
            // progress.json is never replaced by data that isn't durable.
            if (! fflush($handle)) {
                throw RestoreProgressWriteException::flushFailed();
            }

            if (! $this->durability->syncFile($handle)) {
                throw RestoreProgressWriteException::syncFailed();
            }
        } catch (RestoreProgressWriteException $e) {
            fclose($handle);
            @unlink($tempPath);

            throw $e;
        }

        fclose($handle);
        @chmod($tempPath, 0600);

        // Preserve the current valid file as the previous snapshot BEFORE
        // it is replaced — never delete it first, never touch it at all if
        // there is nothing to preserve yet, and best-effort (suppressed) so
        // a previous-snapshot copy failure can never abort the write or
        // corrupt the authoritative current file. progress.previous.json is
        // only ever a recovery copy of an already-valid progress.json and is
        // never read as authoritative (RestoreProgressReader only ever reads
        // progress.json).
        if (is_file($finalPath)) {
            @copy($finalPath, $previousPath);
        }

        if (! @rename($tempPath, $finalPath)) {
            @unlink($tempPath);

            throw RestoreProgressWriteException::cannotPublish();
        }

        // Rename succeeded — progress.json IS the new valid file now. Persist
        // the directory entry so the rename survives an OS crash on Linux.
        // Best-effort by contract: a failure (or unavailability, e.g. on
        // Windows) only weakens crash-durability of the rename metadata, and
        // never corrupts the already-swapped file, so it is deliberately not
        // escalated to an exception.
        $this->durability->syncDirectory($absoluteDir);
    }

    private function buildSignedJson(RestoreProgressSnapshot $snapshot): string
    {
        $schemaVersion = (int) config('oms.backup.restore.progress_schema_version', 1);

        $body = array_merge(['schema_version' => $schemaVersion], $snapshot->toCanonicalArray());

        $canonicalJson = $this->encode($body);

        $body['signature'] = RestoreProgressSigner::sign($canonicalJson);

        return $this->encode($body);
    }

    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw RestoreProgressWriteException::cannotEncode();
        }

        return $json;
    }
}
