<?php

namespace App\Support\Audit;

/**
 * Turns a stored `old_values` / `new_values` / `changed_fields` payload into a
 * FLAT `array<string, string>` of Arabic-labelled key => plain-text value, for
 * the read-only Audit Log detail view (OMS Task 9B.7).
 *
 * READ-ONLY AND PURE. It receives the array Eloquent already decoded from the
 * row and returns strings. It never touches the database, never hydrates or
 * re-loads a subject model (so a hidden model attribute can never leak in
 * through a "fresh" lookup), never re-resolves a historical relation to a
 * current value, and never modifies the stored payload.
 *
 * PLAIN TEXT, NEVER MARKUP. Every value comes back as a plain PHP string with
 * no HTML in it, and the only consumer is Filament's KeyValueEntry, which
 * writes both key and value through `e()`. That is a deliberate division of
 * labour: escaping happens exactly once, at the boundary that actually emits
 * HTML, so a `<script>` inside a payload is rendered as visible text rather
 * than either executed OR shown double-escaped. Nothing here ever produces an
 * `->html()` / `HtmlString` value, ever interprets Markdown, and ever converts
 * a stored string that looks like a URL into a link — a URL captured in an
 * audit trail is evidence, not navigation.
 *
 * TYPE FIDELITY. A stored decimal is a STRING in this system on purpose (see
 * FinancialAuditValue) and is passed through byte-for-byte — never cast to
 * float, never reformatted, never rounded. AuditRedactor::MARKER is likewise
 * passed through verbatim, so a redacted value stays visibly redacted.
 * `null`, an empty string and an empty array are each given their own distinct
 * Arabic rendering rather than collapsing into one blank cell, because in an
 * audit trail "was null", "was blank" and "was an empty list" are three
 * different facts.
 */
final class AuditPayloadPresenter
{
    public const NULL_LABEL = 'بدون قيمة (null)';

    public const EMPTY_STRING_LABEL = 'نص فارغ ("")';

    public const EMPTY_ARRAY_LABEL = 'قائمة فارغة ([])';

    public const TRUE_LABEL = 'نعم';

    public const FALSE_LABEL = 'لا';

    /** Path separator for a nested key. Direction-neutral on purpose (RTL). */
    private const PATH_SEPARATOR = ' · ';

    /**
     * Defensive caps only. AuditPayloadBounder already bounds what can be
     * WRITTEN; these bound what a single view will RENDER, so a legacy or
     * hand-inserted row can never turn one page load into an unbounded loop.
     */
    private const MAX_DEPTH = 6;

    private const MAX_ENTRIES = 500;

    /**
     * @param  mixed  $payload  the already-decoded `old_values`/`new_values`
     * @return array<string, string>
     */
    public static function keyValue(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $flat = [];

        self::flatten($payload, [], $flat, 0);

        return $flat;
    }

    /**
     * `changed_fields` is a bare list of field NAMES (never values) — rendered
     * as an Arabic-labelled list with the stored technical name kept alongside
     * it, so an unmapped future field is still identifiable.
     *
     * @return array<int, string>
     */
    public static function changedFields(mixed $changedFields): array
    {
        if (! is_array($changedFields)) {
            return [];
        }

        $labels = [];

        foreach ($changedFields as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $label = AuditLabels::field($field);

            $labels[] = $label === $field ? $field : "{$label} ({$field})";
        }

        return $labels;
    }

    /**
     * The set of payload keys reported as changed, for highlighting in the
     * old/new tables. Compared against the FIRST path segment only: a changed
     * field is always a top-level payload key, while a nested path such as
     * `المكوّنات · قاعدة البيانات` belongs to the same top-level field.
     *
     * @return array<int, string>
     */
    public static function changedFieldKeys(mixed $changedFields): array
    {
        if (! is_array($changedFields)) {
            return [];
        }

        return array_values(array_filter(
            $changedFields,
            static fn (mixed $field): bool => is_string($field) && $field !== '',
        ));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<int, string>  $labelPath
     * @param  array<string, string>  $flat
     */
    private static function flatten(array $value, array $labelPath, array &$flat, int $depth): void
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (count($flat) >= self::MAX_ENTRIES) {
                $flat['…'] = 'تم اختصار باقي الحقول لتجاوزها الحد الأقصى للعرض.';

                return;
            }

            $segment = $isList
                ? '#'.((int) $key + 1)
                : self::labelFor((string) $key, $depth);

            $path = [...$labelPath, $segment];

            if (is_array($item)) {
                if ($item === []) {
                    $flat[self::joinPath($path)] = self::EMPTY_ARRAY_LABEL;

                    continue;
                }

                if ($depth + 1 >= self::MAX_DEPTH) {
                    $flat[self::joinPath($path)] = self::deepValue($item);

                    continue;
                }

                self::flatten($item, $path, $flat, $depth + 1);

                continue;
            }

            $flat[self::joinPath($path)] = self::scalar($item);
        }
    }

    /**
     * Only TOP-LEVEL keys get an Arabic field label. A nested key is part of
     * a writer-specific structure whose inner names are not a stable, shared
     * vocabulary, so translating those would risk relabelling something into
     * a meaning it does not have.
     */
    private static function labelFor(string $key, int $depth): string
    {
        if ($depth > 0) {
            return $key;
        }

        $label = AuditLabels::field($key);

        return $label === $key ? $key : "{$label} ({$key})";
    }

    /**
     * @param  array<int, string>  $path
     */
    private static function joinPath(array $path): string
    {
        return implode(self::PATH_SEPARATOR, $path);
    }

    /**
     * Everything past MAX_DEPTH, as compact JSON. Unicode is left unescaped so
     * Arabic content stays readable, and the result is still a plain string
     * that the renderer escapes like any other value.
     */
    private static function deepValue(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[…]' : $json;
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => self::NULL_LABEL,
            $value === true => self::TRUE_LABEL,
            $value === false => self::FALSE_LABEL,
            // Byte-for-byte passthrough. This is the branch that keeps a
            // decimal string ("1500.00") and AuditRedactor::MARKER exact.
            is_string($value) => $value === '' ? self::EMPTY_STRING_LABEL : $value,
            is_int($value) => (string) $value,
            // json_encode uses PHP's shortest round-trip float repr, so a
            // float is never widened or truncated by a sprintf format.
            is_float($value) => self::deepValue($value),
            default => self::deepValue($value),
        };
    }
}
