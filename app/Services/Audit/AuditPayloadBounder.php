<?php

namespace App\Services\Audit;

use BackedEnum;
use Closure;
use DateTimeInterface;
use UnitEnum;

/**
 * The single deterministic bounding pass every already-redacted audit
 * payload goes through before it is handed to AuditEvent::create(). Two
 * independent bounds are enforced, in order:
 *
 *  1. every individual string value is truncated to MAX_STRING_LENGTH
 *     Unicode characters (mb_*, never substr()/strlen() byte-based cuts —
 *     this table stores real Arabic content);
 *  2. the whole array, once fully normalized, is capped at
 *     MAX_ENCODED_BYTES of encoded JSON — by dropping whole keys (never by
 *     substr()-ing the encoded JSON string itself, which would produce
 *     invalid JSON), marking `_truncated: true` when it does.
 *
 * old_values and new_values are bounded independently by the caller
 * (AuditLogger) — this class has no opinion on which column a given array
 * belongs to, it just bounds whatever array it is handed.
 */
final class AuditPayloadBounder
{
    public const TRUNCATED_MARKER_KEY = '_truncated';

    private const MAX_STRING_LENGTH = 1000;

    private const MAX_ENCODED_BYTES = 8192;

    private const MAX_DEPTH = 10;

    private const MAX_CHANGED_FIELDS = 100;

    private const MAX_FIELD_NAME_LENGTH = 191;

    private const UNSUPPORTED_VALUE_MARKER = '[UNSUPPORTED_VALUE]';

    private const MAX_DEPTH_MARKER = '[MAX_DEPTH_EXCEEDED]';

    public function boundValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $normalized = $this->normalizeArray($values, 0);

        return $this->capEncodedSize($normalized);
    }

    /**
     * @param  array<int, mixed>|null  $fields
     * @return array<int, string>|null
     */
    public function boundChangedFields(?array $fields): ?array
    {
        if ($fields === null) {
            return null;
        }

        $clean = [];

        foreach ($fields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $clean[] = mb_substr($field, 0, self::MAX_FIELD_NAME_LENGTH);

            if (count($clean) >= self::MAX_CHANGED_FIELDS) {
                break;
            }
        }

        /** @var array<int, string> $unique */
        $unique = array_values(array_unique($clean));

        return $unique;
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::MAX_DEPTH_MARKER;
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $this->boundString($value),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => $this->normalizeArray($value, $depth + 1),
            is_resource($value), $value instanceof Closure => self::UNSUPPORTED_VALUE_MARKER,
            is_object($value) => self::UNSUPPORTED_VALUE_MARKER,
            default => self::UNSUPPORTED_VALUE_MARKER,
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $value, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [self::TRUNCATED_MARKER_KEY => true];
        }

        $result = [];

        foreach ($value as $key => $item) {
            $safeKey = is_string($key) ? mb_substr($key, 0, self::MAX_FIELD_NAME_LENGTH) : $key;
            $result[$safeKey] = $this->normalize($item, $depth);
        }

        return $result;
    }

    private function boundString(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH, 'UTF-8');
    }

    /**
     * @param  array<array-key, mixed>  $normalized
     * @return array<array-key, mixed>
     */
    private function capEncodedSize(array $normalized): array
    {
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE);

        if ($encoded !== false && strlen($encoded) <= self::MAX_ENCODED_BYTES) {
            return $normalized;
        }

        return $this->fitByDroppingKeys($normalized);
    }

    /**
     * Never truncates the encoded JSON string itself (that would produce
     * invalid JSON) — instead drops whole top-level keys, in a fixed,
     * deterministic order (last-inserted first), re-encoding after each
     * drop until the result fits. If even the bare marker cannot fit
     * (pathological key names), falls back to the smallest possible valid
     * payload rather than storing something oversized or invalid.
     *
     * @param  array<array-key, mixed>  $normalized
     * @return array<array-key, mixed>
     */
    private function fitByDroppingKeys(array $normalized): array
    {
        $result = $normalized;
        $result[self::TRUNCATED_MARKER_KEY] = true;

        $keys = array_keys($normalized);

        while (
            ($encoded = json_encode($result, JSON_UNESCAPED_UNICODE)) !== false
            && strlen($encoded) > self::MAX_ENCODED_BYTES
            && count($keys) > 0
        ) {
            $dropKey = array_pop($keys);
            unset($result[$dropKey]);
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return [self::TRUNCATED_MARKER_KEY => true];
        }

        return $result;
    }
}
