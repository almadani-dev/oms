<?php

namespace App\Services\Audit\Crud;

use App\Services\Audit\AuditRedactor;

/**
 * The strict, explicit safe-value policy for `settings.value` (OMS Task
 * 9B.2 §8).
 *
 * `settings` is a free-form key/value table: nothing in the application
 * reads a fixed set of keys (verified — there is no `Setting::` read
 * anywhere in app/), users create arbitrary keys through
 * SettingResource, and the `value` column is a plain textarea. So a
 * per-key allowlist cannot be enumerated honestly, and storing every value
 * verbatim would eventually put a credential in the audit trail the first
 * time somebody adds a `smtp_password` row.
 *
 * The policy is therefore fail-closed on two independent signals, either of
 * which redacts:
 *
 *  1. the setting's KEY is credential-shaped — decided by
 *     AuditRedactor's own already-reviewed rules rather than a second,
 *     drifting copy of them, after normalizing the key's separators
 *     (`mail.password`/`mail-password` -> `mail_password`) so a dotted
 *     settings key is segmented the same way a column name would be;
 *  2. the VALUE is credential-shaped — a PEM block, or a long opaque
 *     token/base64/hex string with no whitespace at all.
 *
 * Nothing here ever reads the environment: `settings` rows are database
 * rows, and no environment-derived value passes through this class.
 */
final class SettingValuePolicy
{
    /**
     * Below this length an opaque string is far more likely to be a real
     * setting ("Asia/Jerusalem", "Y-m-d", "USD") than key material.
     */
    private const OPAQUE_VALUE_MIN_LENGTH = 40;

    public function __construct(private readonly AuditRedactor $redactor) {}

    public function sanitize(mixed $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($key) && $this->isSensitiveKey($key)) {
            return AuditRedactor::MARKER;
        }

        if (is_string($value) && $this->looksLikeCredentialMaterial($value)) {
            return AuditRedactor::MARKER;
        }

        return $value;
    }

    /**
     * Reuses AuditRedactor rather than duplicating its denylist: a field
     * name is sensitive exactly when redacting a one-key array under that
     * name comes back marked.
     */
    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key) ?? $key);

        if ($normalized === '') {
            return false;
        }

        $probe = $this->redactor->redact([$normalized => '']);

        return ($probe[$normalized] ?? null) === AuditRedactor::MARKER;
    }

    private function looksLikeCredentialMaterial(string $value): bool
    {
        $trimmed = trim($value);

        if (str_contains($trimmed, '-----BEGIN')) {
            return true;
        }

        if (mb_strlen($trimmed) < self::OPAQUE_VALUE_MIN_LENGTH) {
            return false;
        }

        // A single long run of token/base64/hex-safe characters with no
        // whitespace or sentence punctuation — i.e. not prose, not a path,
        // not a JSON blob a human would recognise.
        return preg_match('/^[A-Za-z0-9+\/=_\-\.]+$/', $trimmed) === 1;
    }
}
