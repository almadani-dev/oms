<?php

namespace App\Services\Restore\Attachments;

use App\Services\Restore\Exceptions\RestoreAttachmentMarkerException;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — the bounded, immutable,
 * strictly-validated content of a signed attachment-swap marker file. This
 * is the ONLY thing that lets AttachmentSwapStateInspector distinguish a
 * restore that never began activation from one that finished and was fully
 * finalized — both otherwise leave an identical live-exists/no-quarantine
 * directory layout — so its integrity is safety-critical, not diagnostic.
 *
 * Constructible only through create(), which validates the restore UUID
 * against the same canonical pattern used elsewhere in this subsystem and
 * requires a real AttachmentSwapPhase case — never an arbitrary string or
 * array. Carries no path, secret, or caller-supplied data of any kind.
 */
final class AttachmentSwapMarker
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    private function __construct(
        public readonly string $restoreUuid,
        public readonly AttachmentSwapPhase $phase,
        public readonly string $updatedAt,
    ) {
    }

    public static function create(string $restoreUuid, AttachmentSwapPhase $phase, ?string $updatedAt = null): self
    {
        if (preg_match(self::UUID_PATTERN, $restoreUuid) !== 1) {
            throw RestoreAttachmentMarkerException::malformed();
        }

        $updatedAt ??= gmdate(DATE_ATOM);

        if ($updatedAt === '') {
            throw RestoreAttachmentMarkerException::malformed();
        }

        return new self($restoreUuid, $phase, $updatedAt);
    }

    /**
     * @return array{restore_uuid: string, phase: string, updated_at: string}
     */
    public function toCanonicalArray(): array
    {
        return [
            'restore_uuid' => $this->restoreUuid,
            'phase' => $this->phase->value,
            'updated_at' => $this->updatedAt,
        ];
    }
}
