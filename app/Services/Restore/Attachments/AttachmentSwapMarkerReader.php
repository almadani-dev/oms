<?php

namespace App\Services\Restore\Attachments;

use App\Services\Restore\Exceptions\RestoreAttachmentMarkerException;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — the only place a signed
 * attachment-swap marker is ever read back. The signature is verified
 * before anything else about the content is trusted, on every single read
 * — never cached, never skipped.
 *
 * read() returns null ONLY when no marker file exists at all — a
 * legitimate, expected state for a restore that never began activation.
 * Every other problem (unreadable, oversized, malformed JSON, missing/
 * invalid signature, UUID mismatch, an unrecognized phase, an unexpected or
 * missing schema key) throws RestoreAttachmentMarkerException — the marker
 * is never partially trusted or guessed at. Callers (AttachmentSwapStateInspector)
 * always convert any such exception to InconsistentNeedsManualReview, never
 * to a normal state.
 */
final class AttachmentSwapMarkerReader
{
    /**
     * Bounded — this schema is 3 short fields plus a signature; generous
     * headroom over the longest possible encoding still catches a
     * corrupted/oversized file before json_decode() ever sees it.
     */
    private const MAX_FILE_BYTES = 4096;

    private const EXPECTED_KEYS = ['phase', 'restore_uuid', 'signature', 'updated_at'];

    /**
     * @throws RestoreAttachmentMarkerException
     */
    public function read(string $markerPath, string $expectedRestoreUuid): ?AttachmentSwapMarker
    {
        if (! is_file($markerPath)) {
            return null;
        }

        clearstatcache(true, $markerPath);
        $size = @filesize($markerPath);

        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw RestoreAttachmentMarkerException::oversized();
        }

        $raw = @file_get_contents($markerPath);

        if ($raw === false) {
            throw RestoreAttachmentMarkerException::unreadable();
        }

        return $this->parse($raw, $expectedRestoreUuid);
    }

    /**
     * @throws RestoreAttachmentMarkerException
     */
    private function parse(string $raw, string $expectedRestoreUuid): AttachmentSwapMarker
    {
        if (strlen($raw) > self::MAX_FILE_BYTES) {
            throw RestoreAttachmentMarkerException::oversized();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw RestoreAttachmentMarkerException::malformed();
        }

        $signature = $decoded['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            throw RestoreAttachmentMarkerException::unsigned();
        }

        $body = $decoded;
        unset($body['signature']);

        $canonicalJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($canonicalJson === false || ! AttachmentSwapMarkerSigner::verify($canonicalJson, $signature)) {
            throw RestoreAttachmentMarkerException::invalidSignature();
        }

        // Strict bounded schema — no arbitrary/extra keys, none missing.
        $actualKeys = array_keys($decoded);
        sort($actualKeys);

        if ($actualKeys !== self::EXPECTED_KEYS) {
            throw RestoreAttachmentMarkerException::malformed();
        }

        $restoreUuid = $body['restore_uuid'] ?? null;

        if (! is_string($restoreUuid) || ! hash_equals($expectedRestoreUuid, $restoreUuid)) {
            throw RestoreAttachmentMarkerException::uuidMismatch();
        }

        $phaseValue = $body['phase'] ?? null;
        $phase = is_string($phaseValue) ? AttachmentSwapPhase::tryFrom($phaseValue) : null;

        if ($phase === null) {
            throw RestoreAttachmentMarkerException::unsupportedPhase();
        }

        $updatedAt = $body['updated_at'] ?? null;

        if (! is_string($updatedAt) || $updatedAt === '') {
            throw RestoreAttachmentMarkerException::malformed();
        }

        try {
            return AttachmentSwapMarker::create($restoreUuid, $phase, $updatedAt);
        } catch (RestoreAttachmentMarkerException $e) {
            throw $e;
        }
    }
}
