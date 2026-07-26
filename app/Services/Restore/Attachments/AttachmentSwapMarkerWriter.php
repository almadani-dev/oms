<?php

namespace App\Services\Restore\Attachments;

use App\Services\Restore\Contracts\RestoreProgressDurability;
use App\Services\Restore\Exceptions\RestoreAttachmentMarkerException;
use App\Services\Restore\NativeRestoreProgressDurability;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — the only place a signed
 * attachment-swap marker is ever written. Reuses the exact same crash-safe
 * durability discipline RestoreProgressWriter already established (see that
 * class's docblock for the full platform-by-platform reasoning) rather than
 * inventing a second one, and reuses the SAME injectable
 * RestoreProgressDurability seam/production implementation:
 *
 *   1. serialize the marker + sign it (AttachmentSwapMarkerSigner);
 *   2. write that payload to a uniquely-named temp file in the SAME
 *      directory as the final marker path (same filesystem — the final
 *      rename is an in-place metadata swap, never a cross-filesystem copy);
 *   3. fwrite must fully succeed, fflush must succeed, and (where fsync
 *      exists) fsync must succeed — any failure leaves the current valid
 *      marker (if any) completely untouched and cleans up the temp file;
 *   4. close the stream, then rename the fully-flushed/fsync'd/closed temp
 *      file onto the final marker path — the valid marker is NEVER unlinked
 *      before a complete replacement exists, on either platform;
 *   5. on Linux, best-effort fsync the containing directory after a
 *      successful rename so the rename's directory entry survives an OS
 *      crash (documented Windows limitation: directory fsync is
 *      unsupported there — this never affects the correctness of the
 *      already-swapped file itself, only crash-durability of its rename
 *      metadata, exactly as for restore progress files).
 *
 * A failed write throws a sanitized RestoreAttachmentMarkerException and
 * never leaves partially-written authoritative JSON at the final path.
 */
final class AttachmentSwapMarkerWriter
{
    private readonly RestoreProgressDurability $durability;

    public function __construct(?RestoreProgressDurability $durability = null)
    {
        $this->durability = $durability ?? new NativeRestoreProgressDurability();
    }

    /**
     * @throws RestoreAttachmentMarkerException
     */
    public function write(AttachmentSwapMarker $marker, string $markerPath): void
    {
        $directory = rtrim(dirname($markerPath), '/\\');

        if (! is_dir($directory)) {
            throw RestoreAttachmentMarkerException::directoryUnavailable();
        }

        $tempPath = $directory.DIRECTORY_SEPARATOR.'.attachment-swap-'.bin2hex(random_bytes(8)).'.tmp';
        $json = $this->buildSignedJson($marker);

        $handle = @fopen($tempPath, 'wb');

        if ($handle === false) {
            throw RestoreAttachmentMarkerException::cannotOpenTempFile();
        }

        try {
            if (fwrite($handle, $json) === false) {
                throw RestoreAttachmentMarkerException::cannotWriteTempFile();
            }

            if (! fflush($handle)) {
                throw RestoreAttachmentMarkerException::flushFailed();
            }

            if (! $this->durability->syncFile($handle)) {
                throw RestoreAttachmentMarkerException::syncFailed();
            }
        } catch (RestoreAttachmentMarkerException $e) {
            fclose($handle);
            @unlink($tempPath);

            throw $e;
        }

        fclose($handle);
        @chmod($tempPath, 0600);

        if (! @rename($tempPath, $markerPath)) {
            @unlink($tempPath);

            throw RestoreAttachmentMarkerException::cannotPublish();
        }

        $this->durability->syncDirectory($directory);
    }

    private function buildSignedJson(AttachmentSwapMarker $marker): string
    {
        $body = $marker->toCanonicalArray();
        $canonicalJson = $this->encode($body);

        $body['signature'] = AttachmentSwapMarkerSigner::sign($canonicalJson);

        return $this->encode($body);
    }

    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw RestoreAttachmentMarkerException::cannotEncode();
        }

        return $json;
    }
}
