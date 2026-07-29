<?php

namespace App\Services\Audit\Financial;

/**
 * The changed-fields delta between the pre-change and post-change snapshot
 * of one financial record (OMS Task 9B.3).
 *
 * Three rules, in this order:
 *
 *  1. CONTEXT fields (`operation_type`, `transaction_id`,
 *     `transaction_number`) are never business changes. They are carried on
 *     both sides so the event can always be tied back to its workflow and
 *     its ledger transaction, but they never appear in `changed_fields`.
 *
 *  2. SATELLITE fields (a `_label` / `_code` belonging to a foreign key —
 *     see FinancialAuditFieldNames) are never business changes either. A
 *     satellite is carried on both sides exactly when ITS OWN foreign key
 *     changed, which is what preserves "was account A, is now account B" as
 *     readable text rather than two bare ids. A satellite whose foreign key
 *     is not part of the payload at all is treated as an ordinary field, so
 *     a stray key can never be silently dropped.
 *
 *  3. Everything else is a business field: it is reported in
 *     `changed_fields` and carried on both sides, and ONLY when it actually
 *     changed. An update that changed nothing produces an empty diff, and
 *     the recorder writes no event at all.
 *
 * Comparison is strict (`!==`) over already-normalized snapshot values —
 * money and rates are fixed-scale decimal strings by the time they get here
 * (FinancialAuditValue), so "1000.00" vs "1000.0" cannot be mistaken for a
 * change, and 1000.00 vs 1000.005 cannot be mistaken for equality.
 */
final class FinancialAuditDiff
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<int, string>  $changed
     */
    private function __construct(
        public readonly array $old,
        public readonly array $new,
        public readonly array $changed,
    ) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function between(array $before, array $after): self
    {
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));

        $changed = [];

        foreach ($keys as $key) {
            if (FinancialAuditFieldNames::isContextField($key)) {
                continue;
            }

            if (self::isSatelliteOfAPresentKey($key, $keys)) {
                continue;
            }

            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            return new self([], [], []);
        }

        $carry = array_merge(
            FinancialAuditFieldNames::CONTEXT_FIELDS,
            $changed,
            self::satellitesOf($changed, $keys),
        );

        return new self(
            old: self::only($before, $carry),
            new: self::only($after, $carry),
            changed: $changed,
        );
    }

    public function isEmpty(): bool
    {
        return $this->changed === [];
    }

    /**
     * @param  array<int, string>  $presentKeys
     */
    private static function isSatelliteOfAPresentKey(string $key, array $presentKeys): bool
    {
        $base = FinancialAuditFieldNames::baseKey($key);

        return $base !== null && in_array($base, $presentKeys, true);
    }

    /**
     * @param  array<int, string>  $changed
     * @param  array<int, string>  $presentKeys
     * @return array<int, string>
     */
    private static function satellitesOf(array $changed, array $presentKeys): array
    {
        $satellites = [];

        foreach ($presentKeys as $key) {
            $base = FinancialAuditFieldNames::baseKey($key);

            if ($base !== null && in_array($base, $changed, true)) {
                $satellites[] = $key;
            }
        }

        return $satellites;
    }

    /**
     * Preserves a key that exists on only one side as an explicit null on
     * the other, so "was set, is now cleared" is unambiguous in the stored
     * payload rather than looking like a field that was never captured.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private static function only(array $row, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $row[$key] ?? null;
        }

        return $result;
    }
}
