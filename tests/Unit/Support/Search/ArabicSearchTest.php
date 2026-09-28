<?php

namespace Tests\Unit\Support\Search;

use App\Support\Search\ArabicSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure string behaviour of the Arabic search primitives. Every expected value
 * is written with explicit code points where the difference is invisible, so a
 * test can never pass because two visually identical strings were typed.
 */
class ArabicSearchTest extends TestCase
{
    // ---- alef ----------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function alefCases(): array
    {
        return [
            'hamza above' => ["\u{0623}حمد", 'احمد'],
            'hamza below' => ["\u{0625}حمد", 'احمد'],
            'madda' => ["\u{0622}دم", 'ادم'],
            'wasla' => ["\u{0671}لله", 'الله'],
            'plain alef unchanged' => ['احمد', 'احمد'],
            'mid-word hamza alef' => ["الم\u{0623}مون", 'المامون'],
            'every variant at once' => ["\u{0623}\u{0625}\u{0622}\u{0671}ا", 'ااااا'],
        ];
    }

    #[DataProvider('alefCases')]
    public function test_normalize_folds_every_alef_variant_to_plain_alef(string $input, string $expected): void
    {
        $this->assertSame($expected, ArabicSearch::normalize($input));
    }

    /**
     * The approved direction, asserted with literal code points on BOTH sides so
     * the expected value is never produced by the transform under test: every
     * variant (U+0623, U+0625, U+0622, U+0671) becomes plain alef U+0627, and
     * U+0627 itself stays U+0627.
     */
    public function test_alef_direction_is_variant_to_plain_alef(): void
    {
        $this->assertSame("\u{0627}\u{062D}\u{0645}\u{062F}", ArabicSearch::normalize("\u{0623}\u{062D}\u{0645}\u{062F}")); // أحمد → احمد
        $this->assertSame("\u{0627}\u{062D}\u{0645}\u{062F}", ArabicSearch::normalize("\u{0625}\u{062D}\u{0645}\u{062F}")); // إحمد → احمد
        $this->assertSame("\u{0627}\u{062F}\u{0645}", ArabicSearch::normalize("\u{0622}\u{062F}\u{0645}"));                 // آدم → ادم
        $this->assertSame("\u{0627}\u{0633}\u{0644}\u{0627}\u{0645}", ArabicSearch::normalize("\u{0671}\u{0633}\u{0644}\u{0627}\u{0645}")); // ٱسلام → اسلام
        $this->assertSame("\u{0627}\u{062D}\u{0645}\u{062F}", ArabicSearch::normalize("\u{0627}\u{062D}\u{0645}\u{062F}")); // احمد unchanged
    }

    public function test_normalized_output_never_contains_an_alef_variant(): void
    {
        $out = ArabicSearch::normalize("\u{0623}\u{0625}\u{0622}\u{0671}\u{0627}");

        $this->assertSame(str_repeat("\u{0627}", 5), $out);
        $this->assertSame(0, preg_match('/[\x{0622}\x{0623}\x{0625}\x{0671}]/u', $out));
    }

    public function test_clean_does_not_fold_alef(): void
    {
        $this->assertSame("\u{0623}حمد", ArabicSearch::clean("\u{0623}حمد"));
    }

    // ---- digits --------------------------------------------------------------

    public function test_arabic_indic_digits_become_ascii(): void
    {
        $this->assertSame('123456', ArabicSearch::clean("\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}"));
        $this->assertSame('0123456789', ArabicSearch::clean('٠١٢٣٤٥٦٧٨٩'));
    }

    public function test_persian_digits_become_ascii(): void
    {
        $this->assertSame('123456', ArabicSearch::clean("\u{06F1}\u{06F2}\u{06F3}\u{06F4}\u{06F5}\u{06F6}"));
        $this->assertSame('0123456789', ArabicSearch::clean('۰۱۲۳۴۵۶۷۸۹'));
    }

    // ---- whitespace ----------------------------------------------------------

    public function test_repeated_whitespace_collapses_and_edges_are_trimmed(): void
    {
        $this->assertSame('محمد احمد', ArabicSearch::normalize("  محمد   \t احمد  "));
    }

    public function test_nbsp_and_unicode_spaces_count_as_whitespace(): void
    {
        $this->assertSame('محمد احمد', ArabicSearch::clean("\u{00A0}محمد\u{00A0}\u{00A0}احمد\u{202F}"));
    }

    // ---- marks ---------------------------------------------------------------

    public function test_tatweel_is_removed(): void
    {
        $this->assertSame('محمد', ArabicSearch::clean("مح\u{0640}\u{0640}مد"));
    }

    public function test_harakat_are_removed(): void
    {
        // مُحَمَّد: damma, fatha, shadda, fatha.
        $this->assertSame('محمد', ArabicSearch::clean("م\u{064F}ح\u{064E}م\u{0651}\u{064E}د"));
    }

    public function test_full_harakat_range_and_superscript_alef_are_removed(): void
    {
        $marks = implode('', array_map(fn (int $cp): string => mb_chr($cp), [...range(0x064B, 0x0652), 0x0670]));

        $this->assertSame('ب', ArabicSearch::clean('ب'.$marks));
    }

    public function test_combining_hamza_is_not_treated_as_a_haraka(): void
    {
        // U+0654 is outside the approved range: stripping it would turn a
        // decomposed ؤ into و, which is a forbidden fold. NFC composes it into
        // ؤ (U+0624) instead — never the bare و.
        $this->assertSame("\u{0624}", ArabicSearch::clean("\u{0648}\u{0654}"));
        $this->assertNotSame("\u{0648}", ArabicSearch::clean("\u{0648}\u{0654}"));

        // Where no precomposed letter exists, the mark is kept, not stripped.
        $this->assertSame("\u{0628}\u{0654}", ArabicSearch::clean("\u{0628}\u{0654}"));
    }

    // ---- NFC (canonical equivalence, not spelling folding) --------------------

    /**
     * A decomposed alef + combining hamza/madda is canonically the same letter as
     * the precomposed form, so it must land on the same plain alef.
     *
     * @return array<string, array{string, string}>
     */
    public static function decomposedAlefCases(): array
    {
        return [
            'alef + hamza above (= U+0623)' => ["\u{0627}\u{0654}\u{062D}\u{0645}\u{062F}", "\u{0627}\u{062D}\u{0645}\u{062F}"],
            'alef + hamza below (= U+0625)' => ["\u{0627}\u{0655}\u{062D}\u{0645}\u{062F}", "\u{0627}\u{062D}\u{0645}\u{062F}"],
            'alef + madda above (= U+0622)' => ["\u{0627}\u{0653}\u{062F}\u{0645}", "\u{0627}\u{062F}\u{0645}"],
        ];
    }

    #[DataProvider('decomposedAlefCases')]
    public function test_decomposed_alef_forms_normalize_like_their_precomposed_forms(string $decomposed, string $expected): void
    {
        $this->assertSame($expected, ArabicSearch::normalize($decomposed));
    }

    public function test_clean_composes_to_nfc(): void
    {
        $this->assertSame("\u{0623}\u{062D}\u{0645}\u{062F}", ArabicSearch::clean("\u{0627}\u{0654}\u{062D}\u{0645}\u{062F}"));
    }

    /**
     * NFC composes waw/yeh + hamza into ؤ/ئ — the hamza letters themselves, never
     * the bare و/ي — and leaves ة and ى alone.
     *
     * @return array<string, array{string, string}>
     */
    public static function nfcNonFoldingCases(): array
    {
        return [
            'waw + hamza stays ؤ' => ["\u{0645}\u{0633}\u{0648}\u{0654}\u{0648}\u{0644}", "\u{0645}\u{0633}\u{0624}\u{0648}\u{0644}"],
            'yeh + hamza stays ئ' => ["\u{0631}\u{064A}\u{0654}\u{064A}\u{0633}", "\u{0631}\u{0626}\u{064A}\u{0633}"],
            'precomposed ؤ unchanged' => ["\u{0645}\u{0633}\u{0624}\u{0648}\u{0644}", "\u{0645}\u{0633}\u{0624}\u{0648}\u{0644}"],
            'precomposed ئ unchanged' => ["\u{0631}\u{0626}\u{064A}\u{0633}", "\u{0631}\u{0626}\u{064A}\u{0633}"],
            'teh marbuta unchanged' => ["\u{062D}\u{0633}\u{0627}\u{0628}\u{0629}", "\u{062D}\u{0633}\u{0627}\u{0628}\u{0629}"],
            'alef maksura unchanged' => ["\u{0639}\u{0644}\u{0649}", "\u{0639}\u{0644}\u{0649}"],
        ];
    }

    #[DataProvider('nfcNonFoldingCases')]
    public function test_nfc_never_becomes_a_spelling_fold(string $input, string $expected): void
    {
        $this->assertSame($expected, ArabicSearch::normalize($input));
    }

    public function test_zero_width_and_bidi_marks_are_removed(): void
    {
        $this->assertSame('محمد', ArabicSearch::clean("\u{200F}مح\u{200C}م\u{200D}د\u{200E}\u{061C}\u{FEFF}\u{2067}"));
    }

    public function test_a_term_of_only_marks_cleans_to_empty(): void
    {
        $this->assertSame('', ArabicSearch::normalize("\u{0640}\u{064E} \u{200F}"));
    }

    // ---- deliberately NOT normalized -----------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function distinctPairs(): array
    {
        return [
            'yeh vs alef maksura' => ['علي', 'على'],
            'heh vs teh marbuta' => ['حسابه', 'حسابة'],
            'waw hamza vs waw' => ['مسؤول', 'مسوول'],
            'yeh hamza vs yeh' => ['رئيس', 'رييس'],
            'keheh vs kaf' => ['کريم', 'كريم'],
            'farsi yeh vs yeh' => ['علی', 'علي'],
        ];
    }

    #[DataProvider('distinctPairs')]
    public function test_distinct_letters_are_never_folded(string $a, string $b): void
    {
        $this->assertSame($a, ArabicSearch::normalize($a));
        $this->assertSame($b, ArabicSearch::normalize($b));
        $this->assertNotSame(ArabicSearch::normalize($a), ArabicSearch::normalize($b));
        $this->assertFalse(ArabicSearch::containsNormalized($a, $b));
    }

    // ---- person names (explicit ى → ي fold) -----------------------------------

    public function test_person_name_folds_alef_maksura_to_yeh(): void
    {
        $this->assertSame("\u{0639}\u{0644}\u{064A}", ArabicSearch::normalizePersonName("\u{0639}\u{0644}\u{0649}"));             // على → علي
        $this->assertSame("\u{0645}\u{0635}\u{0637}\u{0641}\u{064A}", ArabicSearch::normalizePersonName("\u{0645}\u{0635}\u{0637}\u{0641}\u{0649}")); // مصطفى → مصطفي
        $this->assertSame("\u{0639}\u{0644}\u{064A}", ArabicSearch::normalizePersonName("\u{0639}\u{0644}\u{064A}"));             // علي unchanged
    }

    /**
     * The approved canonical direction, both sides as literal code points so a
     * reversed mapping cannot hide: U+0649 (ى) becomes U+064A (ي), and U+064A
     * stays U+064A.
     */
    public function test_person_name_direction_is_alef_maksura_to_yeh(): void
    {
        $withMaksura = "\u{0639}\u{0644}\u{0649}"; // على
        $withYeh = "\u{0639}\u{0644}\u{064A}";     // علي

        $this->assertSame($withYeh, ArabicSearch::normalizePersonName($withMaksura));
        $this->assertSame($withYeh, ArabicSearch::normalizePersonName($withYeh));
        $this->assertStringNotContainsString("\u{0649}", ArabicSearch::normalizePersonName($withMaksura));
    }

    public function test_person_name_keeps_every_normal_fold(): void
    {
        // Alef, harakat, tatweel, digits and whitespace, plus ى → ي.
        $this->assertSame(
            "\u{0627}\u{062D}\u{0645}\u{062F} \u{0639}\u{064A}\u{0633}\u{064A} 12",
            ArabicSearch::normalizePersonName("  \u{0623}\u{064E}\u{062D}\u{0640}\u{0645}\u{062F}   \u{0639}\u{064A}\u{0633}\u{0649} \u{0661}\u{0662} "),
        );
    }

    public function test_person_name_never_folds_teh_marbuta(): void
    {
        $this->assertSame("\u{0641}\u{0627}\u{0637}\u{0645}\u{0629}", ArabicSearch::normalizePersonName("\u{0641}\u{0627}\u{0637}\u{0645}\u{0629}"));
        $this->assertNotSame(ArabicSearch::normalizePersonName('فاطمة'), ArabicSearch::normalizePersonName('فاطمه'));
    }

    public function test_default_normalize_still_keeps_alef_maksura(): void
    {
        $this->assertSame("\u{0639}\u{0644}\u{0649}", ArabicSearch::normalize("\u{0639}\u{0644}\u{0649}"));
        $this->assertNotSame(ArabicSearch::normalize('على'), ArabicSearch::normalize('علي'));
    }

    public function test_person_name_sql_wraps_the_alef_fold_with_the_maksura_fold(): void
    {
        $this->assertSame(
            'REPLACE('.ArabicSearch::foldAlefSql('"t"."c"').", '\u{0649}', '\u{064A}')",
            ArabicSearch::foldPersonNameSql('"t"."c"'),
        );
    }

    // ---- LIKE escaping -------------------------------------------------------

    public function test_percent_is_escaped(): void
    {
        $this->assertSame('10!%', ArabicSearch::escapeLike('10%'));
    }

    public function test_underscore_is_escaped(): void
    {
        $this->assertSame('ACC!_001', ArabicSearch::escapeLike('ACC_001'));
    }

    public function test_the_escape_character_itself_is_escaped_first(): void
    {
        $this->assertSame('!!', ArabicSearch::escapeLike('!'));
        // A literal "!%" must become "!!" + "!%", not "!!%" (which would re-open %).
        $this->assertSame('!!!%', ArabicSearch::escapeLike('!%'));
    }

    public function test_a_backslash_is_an_ordinary_character(): void
    {
        $this->assertSame('a\\b', ArabicSearch::escapeLike('a\\b'));
    }

    public function test_plain_text_is_not_touched_by_escaping(): void
    {
        $this->assertSame('بنك فلسطين', ArabicSearch::escapeLike('بنك فلسطين'));
    }

    // ---- column-side alef folding --------------------------------------------

    public function test_fold_alef_sql_nests_replace_toward_plain_alef(): void
    {
        $this->assertSame(
            "REPLACE(REPLACE(REPLACE(REPLACE(\"t\".\"c\", 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا'), 'ٱ', 'ا')",
            ArabicSearch::foldAlefSql('"t"."c"'),
        );
    }

    // ---- label matching ------------------------------------------------------

    public function test_contains_normalized_matches_across_alef_variants(): void
    {
        $this->assertTrue(ArabicSearch::containsNormalized('خصم إداري', 'اداري'));
        $this->assertTrue(ArabicSearch::containsNormalized('خصم اداري', 'إداري'));
    }

    public function test_contains_normalized_never_matches_an_empty_needle(): void
    {
        $this->assertFalse(ArabicSearch::containsNormalized('مستفيد', ''));
        $this->assertFalse(ArabicSearch::containsNormalized('مستفيد', "\u{0640}"));
    }

    // ---- phone numbers (identifiers, never amounts) ---------------------------

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function phoneCases(): array
    {
        return [
            'western digits' => ['0599123456', '0599123456'],
            'arabic-indic digits' => ['٠٥٩٩١٢٣٤٥٦', '0599123456'],
            'persian digits' => ['۰۵۹۹۱۲۳۴۵۶', '0599123456'],
            'hyphens and spaces' => ['0599-123 456', '0599123456'],
            'leading plus kept as formatting only' => ['+970 599-123-456', '970599123456'],
            'parentheses' => ['(059) 912', '059912'],
            'arabic digits with formatting' => ['+٩٧٠-٥٩٩', '970599'],
            'letters make it not a phone' => ['X-562', null],
            'arabic text' => ['محمد', null],
            'comma is not phone formatting' => ['1,500', null],
            'formatting only' => ['+-()', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('phoneCases')]
    public function test_phone_digits(string $input, ?string $expected): void
    {
        $this->assertSame($expected, ArabicSearch::phoneDigits($input));
    }

    public function test_phone_digits_do_not_go_through_amount_parsing(): void
    {
        // A leading zero is significant in a phone number and survives, and a
        // leading + is phone formatting, which the amount parser rejects.
        $this->assertSame('0599', ArabicSearch::phoneDigits('0599'));
        $this->assertSame('0599', ArabicSearch::phoneDigits('+0599'));
        $this->assertNull(ArabicSearch::numeric('+0599'));
    }

    // ---- word splitting -------------------------------------------------------

    public function test_words_splits_cleaned_input_on_whitespace(): void
    {
        $this->assertSame(['بنك', 'فلسطين'], ArabicSearch::words("  بنك\u{00A0}\u{00A0} فلسطين "));
        $this->assertSame(['BNK-7788'], ArabicSearch::words('BNK-7788'));
    }

    public function test_words_drops_words_that_clean_to_nothing(): void
    {
        $this->assertSame([], ArabicSearch::words("\u{0640} \u{064E}"));
        $this->assertSame(['حساب'], ArabicSearch::words("حساب \u{0640}"));
    }

    // ---- numbers -------------------------------------------------------------

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function numericCases(): array
    {
        return [
            'ascii integer' => ['1500', '1500'],
            'arabic-indic integer' => ['١٥٠٠', '1500'],
            'persian integer' => ['۱۵۰۰', '1500'],
            'decimal' => ['2700.00', '2700.00'],
            'grouped comma' => ['2,700.00', '2700.00'],
            'arabic digits grouped comma' => ['٢,٧٠٠.٠٠', '2700.00'],
            'arabic thousands and decimal separators' => ['٢٬٧٠٠٫٥', '2700.5'],
            'nbsp grouping' => ["1\u{00A0}500", '1500'],
            'multi-group' => ['1,234,567', '1234567'],
            'malformed grouping' => ['1,50,0', null],
            'mixed separators' => ['1,234 567', null],
            'trailing separator' => ['1,500,', null],
            'exponent' => ['1e3', null],
            'hex' => ['0x1A', null],
            'signed' => ['-1500', null],
            'leading dot' => ['.5', null],
            'text' => ['مبلغ', null],
            'digits with text' => ['1500ش', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('numericCases')]
    public function test_numeric_parsing_is_strict(string $input, ?string $expected): void
    {
        $this->assertSame($expected, ArabicSearch::numeric($input));
    }
}
