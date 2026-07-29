<?php

namespace App\Services\Audit\Financial;

use DateTimeInterface;

/**
 * The normalizers every financial audit payload value passes through
 * (OMS Task 9B.3).
 *
 * The single hard rule this class exists to enforce: money, percentages and
 * FX rates are stored as fixed-scale DECIMAL STRINGS, never as PHP floats.
 * A float in an audit payload is JSON-encoded with binary rounding
 * artefacts ("1234.5600000000001") and silently loses trailing scale
 * ("1000.00" -> 1000), both of which destroy a financial record's meaning.
 *
 * Most values arrive here already correct — the workflow models declare
 * `decimal:2` / `decimal:6` casts, so reading the attribute already yields
 * a string — but every value is re-normalized anyway so a raw float that
 * reaches a call site (a freshly computed amount, a value read off a
 * TransactionLine) can never slip through untouched.
 */
final class FinancialAuditValue
{
    public const MONEY_SCALE = 2;

    public const RATE_SCALE = 6;

    public const PERCENTAGE_SCALE = 2;

    /**
     * Bound applied to the free-text fields that ARE carried (a general
     * expense's short `description`, a record's `notes`). Deliberately far
     * below AuditPayloadBounder's own 1000-character per-string cap: these
     * are carried so a text-only edit is still auditable, not so the audit
     * trail becomes a copy of the notes column.
     */
    public const MAX_TEXT_LENGTH = 255;

    /**
     * A fixed-scale decimal string, or null. Never a float.
     */
    public static function decimal(mixed $value, int $scale = self::MONEY_SCALE): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    public static function money(mixed $value): ?string
    {
        return self::decimal($value, self::MONEY_SCALE);
    }

    public static function rate(mixed $value): ?string
    {
        return self::decimal($value, self::RATE_SCALE);
    }

    public static function percentage(mixed $value): ?string
    {
        return self::decimal($value, self::PERCENTAGE_SCALE);
    }

    /**
     * A business date as plain `Y-m-d` — these are calendar dates, not
     * instants, so the full ATOM timestamp AuditPayloadBounder would
     * otherwise produce for a DateTimeInterface is not wanted.
     */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 10);
    }

    public static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A bounded, trimmed free-text value; null when empty. Never carries an
     * unbounded notes/description column into the payload.
     */
    public static function text(mixed $value, int $maxLength = self::MAX_TEXT_LENGTH): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
