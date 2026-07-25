<?php

namespace App\Services\Restore;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OMS Task 7C.2 — the only place a restore progress file is ever written.
 * Accepts nothing but an already-validated RestoreProgressSnapshot (never a
 * raw array) and performs a crash-safe write:
 *
 *   1. create the operation directory privately (0700-equivalent — same
 *      restrictive shape as BackupSubsystemLock's own lock directory);
 *   2. serialize the snapshot + schema_version into canonical JSON and sign
 *      it (RestoreProgressSigner), producing the final payload;
 *   3. write that payload to a uniquely-named temp file in the SAME
 *      directory (same-filesystem, so the final rename is an in-place
 *      metadata swap, never a cross-filesystem copy);
 *   4. fflush() to push PHP's userland buffer to the OS, then fsync() to
 *      force the OS to flush it to the physical device — fsync() has been
 *      part of core PHP since 8.1 (this project runs 8.3), so it is
 *      genuinely called here; the function_exists() guard only protects a
 *      hypothetical sub-8.1 runtime. A fsync() failure is deliberately
 *      non-fatal (durability best-effort only): the data is already in the
 *      OS page cache from fflush(), and the correctness of the swap itself
 *      never depends on fsync having succeeded — only on the rename in
 *      step 6;
 *   5. close the stream (done in EVERY path — the catch block and the
 *      success path both fclose() before doing anything else), then copy
 *      the CURRENT valid progress.json to progress.previous.json before it
 *      is replaced — never deleted first;
 *   6. rename the fully-flushed, closed temp file onto progress.json;
 *   7. on any failure before the rename, the temp file is removed and the
 *      existing progress.json (if any) is left completely untouched — a
 *      failed/interrupted write can never replace a valid current file
 *      with partial JSON, because nothing ever writes directly to
 *      progress.json itself.
 *
 * Atomicity of step 6 by platform (verified, not assumed):
 *   - Linux (the production Hostinger VPS target): rename(2) on the same
 *     filesystem is atomic — the destination always observes either the
 *     complete old file or the complete new file, never a partial one.
 *   - Windows/Laragon (local development only): PHP's rename() maps to
 *     MoveFileEx with MOVEFILE_REPLACE_EXISTING, which DOES replace an
 *     existing destination (proven by RestoreProgressWriterReaderTest's
 *     two-write / previous-snapshot tests passing on this Windows host)
 *     but is not guaranteed POSIX-atomic. This is an accepted local-dev
 *     limitation and never a production concern. The guarantee this class
 *     actually depends on holds on BOTH platforms regardless of that
 *     distinction: the current valid progress.json is only ever touched by
 *     the rename itself — it is copied to progress.previous.json first and
 *     never unlinked ahead of a fully-written replacement — so even a torn
 *     or failed Windows replace can never leave zero valid progress files.
 */
final class RestoreProgressWriter
{
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
            throw new RuntimeException('Unable to open the restore progress temp file for writing.');
        }

        try {
            if (fwrite($handle, $json) === false) {
                throw new RuntimeException('Unable to write the restore progress temp file.');
            }

            // Flush PHP's userland buffer to the OS first, then force the
            // OS to flush to the device. fsync() is core PHP as of 8.1
            // (this project runs 8.3, so it is really called); the guard
            // only covers a hypothetical sub-8.1 runtime. A fsync() failure
            // is intentionally non-fatal — durability is best-effort and the
            // swap's correctness depends only on the rename below, never on
            // fsync having succeeded.
            fflush($handle);

            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } catch (RuntimeException $e) {
            fclose($handle);
            @unlink($tempPath);

            throw $e;
        }

        fclose($handle);
        @chmod($tempPath, 0600);

        // Preserve the current valid file as the previous snapshot BEFORE
        // it is replaced — never delete it first, and never touch it at
        // all if there is nothing to preserve yet.
        if (is_file($finalPath)) {
            @copy($finalPath, $previousPath);
        }

        if (! @rename($tempPath, $finalPath)) {
            @unlink($tempPath);

            throw new RuntimeException('Unable to publish the restore progress file.');
        }
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
            throw new RuntimeException('Unable to encode the restore progress payload as JSON.');
        }

        return $json;
    }
}
