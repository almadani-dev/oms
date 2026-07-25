<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.2 — the single place a restore progress file's HMAC-SHA256
 * signature is computed or checked, shared by RestoreProgressWriter and
 * RestoreProgressReader so the two can never drift onto different keys or
 * algorithms.
 *
 * The signing key is purpose-derived from APP_KEY (never BackupKeyRing's
 * archive-encryption keys — a deliberate, distinct concern: those rotate
 * independently of any single restore's lifetime, and binding progress-file
 * integrity to them would mean a key rotation mid-restore could invalidate
 * a progress file actively being written). A fixed context string
 * ("oms-restore-progress-v1") separates this derived key from any other
 * purpose APP_KEY might ever be used for elsewhere in the app.
 */
final class RestoreProgressSigner
{
    private const CONTEXT = 'oms-restore-progress-v1';

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
