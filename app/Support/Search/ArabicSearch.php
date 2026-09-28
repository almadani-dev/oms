<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Search-term normalization primitives for Arabic OMS search.
 *
 * Every text column in OMS is utf8mb4_unicode_ci, and a read-only audit against
 * that collation established what the database does NOT fold on its own under
 * LIKE: alef variants (أ إ آ ٱ vs ا), harakat, tatweel and zero-width marks.
 * (Arabic-Indic digits and letter case are already folded by MySQL, but not by
 * PHP's is_numeric() nor by SQLite, so digits are normalized here as well.)
 *
 * Deliberately NOT folded, because each pair distinguishes real words:
 * ى/ي (على ≠ علي), ة/ه (حسابة ≠ حسابه), ؤ/و, ئ/ي, and the Persian ی/ک.
 *
 * Two term shapes are offered:
 *  - clean():     safe cleanup only — for structured identifiers (transaction
 *                 numbers, codes, references), whose stored value is compared
 *                 as-is;
 *  - normalize(): clean() plus alef folding — for human Arabic text, always
 *                 paired with the column-side foldAlefSql() so both sides of the
 *                 comparison share one canonical form (plain ا).
 *
 * LIKE predicates are built only through whereContainsText()/whereContainsIdentifier(),
 * which escape the term and emit an explicit ESCAPE clause. SQLite has no
 * default LIKE escape character and MySQL's default (backslash) depends on
 * NO_BACKSLASH_ESCAPES, so relying on either default would be undefined.
 */
final class ArabicSearch
{
    /**
     * LIKE escape character, emitted explicitly as `ESCAPE '!'`. Not a backslash,
     * which is also a string-literal escape in MySQL and would need doubling.
     */
    public const LIKE_ESCAPE = '!';

    /** Alef variants folded to plain alef (ا), on the term and on the column. */
    private const ALEF_VARIANTS = ['أ', 'إ', 'آ', 'ٱ'];

    private const PLAIN_ALEF = 'ا';

    /** Harakat U+064B..U+0652 and superscript alef U+0670, plus tatweel U+0640. */
    private const MARKS_PATTERN = '/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u';

    /**
     * Invisible formatting characters that copy/paste drags along: zero-width
     * space/non-joiner/joiner, LRM/RLM, Arabic letter mark, BOM, and the
     * bidirectional embedding/override/isolate controls.
     */
    private const INVISIBLES_PATTERN = '/[\x{200B}-\x{200F}\x{061C}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** Any run of whitespace, including NBSP and the other Unicode space separators. */
    private const WHITESPACE_PATTERN = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    /** Formatting characters ignored inside phone numbers, on the term and on the column. */
    private const PHONE_FORMATTING = [' ', '-', '+', '(', ')'];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Safe cleanup: compose to Unicode NFC, strip harakat, tatweel and invisible
     * marks, convert Arabic-Indic and Persian digits to ASCII, collapse whitespace
     * (NBSP included) to a single space, and trim. Letters are otherwise left
     * exactly as typed.
     *
     * NFC is canonical equivalence only: a decomposed alef + U+0654 becomes the
     * single letter أ (then folded like any typed أ by normalize()), and a
     * decomposed و/ي + U+0654 becomes ؤ/ئ — never the bare و/ي. It runs first
     * so the combining marks it absorbs are not mistaken for strippable harakat.
     * ext-intl is a hard requirement of filament/support; the guard only keeps
     * search working unchanged if it were ever absent.
     */
    public static function clean(string $term): string
    {
        if (class_exists(\Normalizer::class)) {
            $term = \Normalizer::normalize($term, \Normalizer::FORM_C) ?: $term;
        }

        $term = preg_replace(self::INVISIBLES_PATTERN, '', $term) ?? $term;
        $term = preg_replace(self::MARKS_PATTERN, '', $term) ?? $term;
        $term = strtr($term, self::DIGITS);
        $term = preg_replace(self::WHITESPACE_PATTERN, ' ', $term) ?? $term;

        return trim($term);
    }

    /**
     * clean() plus alef folding: U+0623 (أ), U+0625 (إ), U+0622 (آ) and U+0671 (ٱ)
     * each become plain alef U+0627 (ا); U+0627 itself is unchanged. For human
     * Arabic text only.
     */
    public static function normalize(string $term): string
    {
        return str_replace(self::ALEF_VARIANTS, self::PLAIN_ALEF, self::clean($term));
    }

    /**
     * Escape a term for use inside a LIKE pattern with ESCAPE '!': the escape
     * character itself first, then the two wildcards, so `%` and `_` match
     * literally.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term,
        );
    }

    /**
     * The alef-folding expression over an already-wrapped, code-supplied column:
     * REPLACE(REPLACE(REPLACE(REPLACE(col, 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا'), 'ٱ', 'ا').
     * The replacement pairs are constants of this class, never user input.
     */
    public static function foldAlefSql(string $wrappedColumn): string
    {
        $sql = $wrappedColumn;

        foreach (self::ALEF_VARIANTS as $variant) {
            $sql = "REPLACE({$sql}, '{$variant}', '".self::PLAIN_ALEF."')";
        }

        return $sql;
    }

    /**
     * `<alef-folded column> LIKE %term% ESCAPE '!'` for human Arabic text. The
     * term is normalized, escaped and bound; an empty normalized term adds no
     * predicate at all.
     */
    public static function whereContainsText(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $term = self::normalize($term);

        if ($term === '') {
            return $query;
        }

        return $query->whereRaw(
            self::foldAlefSql(self::wrap($query, $column)).' LIKE ? ESCAPE \''.self::LIKE_ESCAPE.'\'',
            ['%'.self::escapeLike($term).'%'],
            $boolean,
        );
    }

    /**
     * `<column> LIKE %term% ESCAPE '!'` for structured identifiers (codes,
     * numbers, references): cleaned but not alef-folded on either side.
     */
    public static function whereContainsIdentifier(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $term = self::clean($term);

        if ($term === '') {
            return $query;
        }

        return $query->whereRaw(
            self::wrap($query, $column).' LIKE ? ESCAPE \''.self::LIKE_ESCAPE.'\'',
            ['%'.self::escapeLike($term).'%'],
            $boolean,
        );
    }

    /**
     * `REPLACE(<column>, ' ', '') LIKE %term% ESCAPE '!'` for identifiers that are
     * commonly written in space-separated groups (IBAN "PS92 PALS 0000 ..."):
     * spaces are ignored on both sides, so grouping never decides the match.
     * Stored values are never changed.
     */
    public static function whereContainsCompactIdentifier(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $term = str_replace(' ', '', self::clean($term));

        if ($term === '') {
            return $query;
        }

        return $query->whereRaw(
            'REPLACE('.self::wrap($query, $column).', \' \', \'\') LIKE ? ESCAPE \''.self::LIKE_ESCAPE.'\'',
            ['%'.self::escapeLike($term).'%'],
            $boolean,
        );
    }

    /**
     * The digits of a phone-number term, or null when the term is not one: after
     * clean() (so Arabic/Persian digits are ASCII) it must hold at least one digit
     * and nothing but digits and the formatting characters PHONE_FORMATTING.
     * Country codes are not interpreted — a leading + is formatting like any
     * other. Not numeric(): a phone number is an identifier, not an amount.
     */
    public static function phoneDigits(string $term): ?string
    {
        $term = self::clean($term);

        if (preg_match('/^[\d \-+()]*\d[\d \-+()]*$/', $term) !== 1) {
            return null;
        }

        return str_replace(self::PHONE_FORMATTING, '', $term);
    }

    /**
     * Phone contains-search: the term's digits against the column with the same
     * formatting characters stripped, so "0599-123 456", "+970599123456" and
     * "٠٥٩٩١٢٣٤٥٦" all compare as plain digits. A non-phone term adds nothing.
     */
    public static function whereContainsPhone(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $digits = self::phoneDigits($term);

        if ($digits === null) {
            return $query;
        }

        $sql = self::wrap($query, $column);

        foreach (self::PHONE_FORMATTING as $character) {
            $sql = "REPLACE({$sql}, '{$character}', '')";
        }

        return $query->whereRaw($sql.' LIKE ? ESCAPE \''.self::LIKE_ESCAPE.'\'', ['%'.$digits.'%'], $boolean);
    }

    /**
     * The words of a search input, cleaned — for search surfaces that do their
     * own word splitting (topbar global search), matching the table behaviour
     * where Filament splits the input and every word must match.
     *
     * @return list<string>
     */
    public static function words(string $search): array
    {
        return array_values(array_filter(explode(' ', self::clean($search)), fn (string $word): bool => $word !== ''));
    }

    /**
     * Whether the normalized $needle occurs in the normalized $haystack — for
     * matching a term against a fixed Arabic vocabulary (enum labels) in PHP.
     * An empty needle never matches.
     */
    public static function containsNormalized(string $haystack, string $needle): bool
    {
        $needle = self::normalize($needle);

        return $needle !== '' && str_contains(self::normalize($haystack), $needle);
    }

    /**
     * The term as an exact decimal string, or null when it is not a plain
     * number. Digits are converted first; the Arabic decimal separator ٫ reads
     * as `.`; grouping separators (`,`, Arabic ٬, space) are accepted only in
     * well-formed groups of three ("1,500.00", "٢٬٧٠٠"). Signs, exponents,
     * hex and malformed grouping ("1,50,0") are rejected, so a term never turns
     * into an amount predicate by accident.
     */
    public static function numeric(string $term): ?string
    {
        $term = str_replace('٫', '.', self::clean($term));

        if (preg_match('/^\d+(?:\.\d+)?$/', $term) === 1) {
            return $term;
        }

        if (preg_match('/^\d{1,3}(?:([,\x{066C} ])\d{3})(?:\1\d{3})*(?:\.\d+)?$/u', $term) === 1) {
            return str_replace([',', '٬', ' '], '', $term);
        }

        return null;
    }

    private static function wrap(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
    }
}
