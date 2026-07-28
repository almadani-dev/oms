<?php

namespace App\Services\Audit;

use Closure;

/**
 * The single place every audit payload's keys are checked against the
 * secret/credential denylist before anything is bounded (AuditPayloadBounder)
 * or persisted (AuditLogger). Redaction is purely key-name-based — value
 * TYPE normalization/rejection (DateTime, BackedEnum, remaining unsafe
 * objects) is AuditPayloadBounder's job, not this class's; the one
 * exception is resources/closures, redacted here defensively since a
 * literal open file handle or callable should never reach the bounder at
 * all.
 *
 * Matching is whole-underscore-segment based (never a bare substring check)
 * specifically so a real, safe identifier like `encryption_key_id` is not
 * redacted merely because it contains the letters "key" — see
 * SAFE_EXCEPTIONS. A field must be explicitly allowlisted there to survive
 * a segment match; nothing is allowlisted unless it is a confirmed,
 * non-secret identifier that actually exists in this codebase (see
 * App\Services\Backup\BackupOperation's `encryption_key_id` column) — never
 * added speculatively.
 */
final class AuditRedactor
{
    public const MARKER = '[REDACTED]';

    private const UNSUPPORTED_VALUE_MARKER = '[UNSUPPORTED_VALUE]';

    /**
     * Explicit, literal field names denied regardless of pattern matching —
     * kept even where a pattern rule below would already catch them, as a
     * direct, documented mapping back to OMS Task 9A §F's required denylist.
     */
    private const EXACT_DENYLIST = [
        'password',
        'password_confirmation',
        'remember_token',
        'api_token',
        'access_token',
        'refresh_token',
        'bearer_token',
        'session_id',
        'csrf_token',
        'app_key',
        'db_password',
        'database_password',
        'db_credentials',
        'database_credentials',
        'encryption_key',
        'backup_encryption_key',
        'previous_keys',
    ];

    /**
     * A field ending in one of these is always redacted — there is no
     * legitimate field in this codebase shaped this way, so no exception
     * list is needed for suffixes.
     */
    private const SUFFIX_DENYLIST = [
        '_token',
        '_secret',
    ];

    /**
     * A field is redacted if any underscore-delimited segment of its name
     * exactly equals one of these — e.g. `encryption_key_id` has the
     * segment "key" and is caught here (then allowed back through by
     * SAFE_EXCEPTIONS), but `bank_type_id` has no matching segment at all.
     */
    private const SEGMENT_DENYLIST = [
        'key', 'keys',
        'secret', 'secrets',
        'token', 'tokens',
        'password', 'passwords', 'pass',
        'credential', 'credentials',
        'authorization',
        'cookie', 'cookies',
    ];

    /**
     * Confirmed non-secret identifiers that would otherwise be caught by
     * SEGMENT_DENYLIST. See App\Models\BackupOperation::$fillable —
     * `encryption_key_id` identifies which key encrypted an archive, it is
     * never the key material itself (see BackupKeyRing). Nothing else is
     * added here — a hypothetical field like `account_key` does not exist
     * anywhere in this codebase, so it stays fail-closed per OMS Task 9A §F.
     */
    private const SAFE_EXCEPTIONS = [
        'encryption_key_id',
    ];

    /**
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        return $this->redactArray($data);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::MARKER;

                continue;
            }

            $result[$key] = $this->redactValue($value);
        }

        return $result;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactArray($value);
        }

        if (is_resource($value) || $value instanceof Closure) {
            return self::UNSUPPORTED_VALUE_MARKER;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::SAFE_EXCEPTIONS, true)) {
            return false;
        }

        if (in_array($normalized, self::EXACT_DENYLIST, true)) {
            return true;
        }

        foreach (self::SUFFIX_DENYLIST as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        $segments = explode('_', $normalized);

        foreach (self::SEGMENT_DENYLIST as $sensitiveSegment) {
            if (in_array($sensitiveSegment, $segments, true)) {
                return true;
            }
        }

        return false;
    }
}
