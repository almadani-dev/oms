<?php

namespace App\Services\Restore\Attachments;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — the single place the
 * attachment-swap marker's HMAC-SHA256 signature is computed or checked,
 * shared by AttachmentSwapMarkerWriter and AttachmentSwapMarkerReader so the
 * two can never drift onto different keys or algorithms. Mirrors
 * RestoreProgressSigner exactly (same key-derivation shape from APP_KEY),
 * but with a distinct, stable context string so this signature can never be
 * substituted for — or confused with — a restore progress-file signature
 * even though both derive from the same underlying application key.
 */
final class AttachmentSwapMarkerSigner
{
    private const CONTEXT = 'oms-restore-attachment-swap-v1';

    public static function sign(string $canonicalJson): string
    {
        return hash_hmac('sha256', $canonicalJson, self::key());
    }

    public static function verify(string $canonicalJson, string $signature): bool
    {
        return hash_equals(self::sign($canonicalJson), $signature);
    }

    private static function key(): string
    {
        return hash_hmac('sha256', self::CONTEXT, (string) config('app.key'));
    }
}
