<?php

namespace Tests\Unit\Support\Audit;

use App\Services\Audit\AuditRedactor;
use App\Support\Audit\AuditPayloadPresenter;
use PHPUnit\Framework\TestCase;

/**
 * OMS Task 9B.7 §7 — the shared payload presentation helper is pure, lossless
 * for the value shapes that matter, and never emits markup.
 */
class AuditPayloadPresenterTest extends TestCase
{
    public function test_null_empty_string_and_empty_array_are_three_distinct_renderings(): void
    {
        $presented = AuditPayloadPresenter::keyValue([
            'a' => null,
            'b' => '',
            'c' => [],
        ]);

        $this->assertSame(AuditPayloadPresenter::NULL_LABEL, $presented['a']);
        $this->assertSame(AuditPayloadPresenter::EMPTY_STRING_LABEL, $presented['b']);
        $this->assertSame(AuditPayloadPresenter::EMPTY_ARRAY_LABEL, $presented['c']);

        $this->assertCount(3, array_unique([$presented['a'], $presented['b'], $presented['c']]));
    }

    public function test_booleans_render_in_arabic(): void
    {
        $presented = AuditPayloadPresenter::keyValue(['t' => true, 'f' => false]);

        $this->assertSame('نعم', $presented['t']);
        $this->assertSame('لا', $presented['f']);
    }

    public function test_decimal_strings_pass_through_byte_for_byte(): void
    {
        $presented = AuditPayloadPresenter::keyValue([
            'amount' => '1500.00',
            'rate' => '0.123456',
            'negative' => '-0.010000',
            'big' => '999999999999.99',
        ]);

        $this->assertSame('1500.00', $presented['المبلغ (amount)']);
        $this->assertSame('0.123456', $presented['سعر الصرف (rate)']);
        $this->assertSame('-0.010000', $presented['negative']);
        $this->assertSame('999999999999.99', $presented['big']);
    }

    public function test_the_redaction_marker_is_preserved_exactly(): void
    {
        $presented = AuditPayloadPresenter::keyValue(['password' => AuditRedactor::MARKER]);

        $this->assertSame(AuditRedactor::MARKER, $presented['password']);
    }

    public function test_known_top_level_keys_get_an_arabic_label_and_unknown_ones_do_not(): void
    {
        $presented = AuditPayloadPresenter::keyValue([
            'amount' => '1.00',
            'totally_unknown_key' => 'قيمة',
        ]);

        $this->assertArrayHasKey('المبلغ (amount)', $presented);
        $this->assertArrayHasKey('totally_unknown_key', $presented);
    }

    public function test_nested_arrays_are_flattened_into_readable_paths(): void
    {
        $presented = AuditPayloadPresenter::keyValue([
            'components' => ['database' => true, 'private_attachments' => false],
        ]);

        $this->assertSame('نعم', $presented['المكوّنات (components) · database']);
        $this->assertSame('لا', $presented['المكوّنات (components) · private_attachments']);
    }

    public function test_lists_are_flattened_with_one_based_positions(): void
    {
        $presented = AuditPayloadPresenter::keyValue(['roles' => ['Admin', 'Viewer']]);

        $this->assertSame('Admin', $presented['الأدوار (roles) · #1']);
        $this->assertSame('Viewer', $presented['الأدوار (roles) · #2']);
    }

    public function test_a_non_array_payload_yields_nothing(): void
    {
        $this->assertSame([], AuditPayloadPresenter::keyValue(null));
        $this->assertSame([], AuditPayloadPresenter::keyValue('a string'));
        $this->assertSame([], AuditPayloadPresenter::keyValue(42));
    }

    public function test_no_value_is_ever_markup_or_a_link(): void
    {
        $presented = AuditPayloadPresenter::keyValue([
            'notes' => '<b>bold</b><script>alert(1)</script>',
            'link' => 'https://example.test/path',
            'markdown' => '[click](https://example.test)',
        ]);

        // Passed through verbatim as PLAIN TEXT — the renderer escapes it. The
        // helper must never wrap, linkify or interpret any of these.
        $this->assertSame('<b>bold</b><script>alert(1)</script>', $presented['ملاحظات (notes)']);
        $this->assertSame('https://example.test/path', $presented['link']);
        $this->assertSame('[click](https://example.test)', $presented['markdown']);

        foreach ($presented as $value) {
            $this->assertStringNotContainsString('<a ', $value);
            $this->assertStringNotContainsString('href=', $value);
        }
    }

    public function test_deep_nesting_is_bounded_rather_than_recursing_without_limit(): void
    {
        $deep = 'leaf';

        for ($i = 0; $i < 40; $i++) {
            $deep = ['level' => $deep];
        }

        $presented = AuditPayloadPresenter::keyValue($deep);

        $this->assertNotEmpty($presented);
        $this->assertLessThan(20, count($presented));
        $this->assertStringContainsString('leaf', implode('', $presented));
    }

    public function test_a_very_wide_payload_is_truncated_with_a_visible_notice(): void
    {
        $wide = [];

        for ($i = 0; $i < 1000; $i++) {
            $wide["field_{$i}"] = (string) $i;
        }

        $presented = AuditPayloadPresenter::keyValue($wide);

        $this->assertLessThanOrEqual(501, count($presented));
        $this->assertArrayHasKey('…', $presented);
    }

    public function test_changed_fields_are_labelled_and_unknown_ones_survive(): void
    {
        $labels = AuditPayloadPresenter::changedFields(['amount', 'future_field', '', 42]);

        $this->assertSame(['المبلغ (amount)', 'future_field'], $labels);
    }

    public function test_changed_fields_of_a_non_array_is_empty(): void
    {
        $this->assertSame([], AuditPayloadPresenter::changedFields(null));
        $this->assertSame([], AuditPayloadPresenter::changedFieldKeys(null));
    }
}
