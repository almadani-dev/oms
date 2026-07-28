<?php

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditPayloadBounder;
use Tests\TestCase;

class AuditPayloadBounderTest extends TestCase
{
    private AuditPayloadBounder $bounder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bounder = new AuditPayloadBounder;
    }

    public function test_null_input_returns_null(): void
    {
        $this->assertNull($this->bounder->boundValues(null));
        $this->assertNull($this->bounder->boundChangedFields(null));
    }

    public function test_utf8_arabic_long_text_is_truncated_to_1000_unicode_characters(): void
    {
        $arabic = str_repeat('أ', 1500);

        $result = $this->bounder->boundValues(['notes' => $arabic]);

        $this->assertSame(1000, mb_strlen($result['notes'], 'UTF-8'));
        $this->assertSame(mb_substr($arabic, 0, 1000, 'UTF-8'), $result['notes']);
    }

    public function test_short_string_is_preserved_unchanged(): void
    {
        $result = $this->bounder->boundValues(['label' => 'REC-2026-014']);

        $this->assertSame('REC-2026-014', $result['label']);
    }

    public function test_old_and_new_values_are_capped_independently_at_8192_bytes(): void
    {
        $big = [];

        for ($i = 0; $i < 200; $i++) {
            $big["field_{$i}"] = str_repeat('x', 100);
        }

        $result = $this->bounder->boundValues($big);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertNotFalse($encoded);
        $this->assertLessThanOrEqual(8192, strlen($encoded));
        $this->assertTrue($result[AuditPayloadBounder::TRUNCATED_MARKER_KEY]);
    }

    public function test_result_is_always_valid_json(): void
    {
        $big = [];

        for ($i = 0; $i < 500; $i++) {
            $big["field_{$i}"] = str_repeat('y', 500);
        }

        $result = $this->bounder->boundValues($big);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertNotFalse($encoded);
        $this->assertNotNull(json_decode($encoded));
    }

    public function test_extremely_large_payload_always_fits_the_byte_budget(): void
    {
        // Every individual field name/value is already bounded (191/1000
        // chars respectively), so no single remaining key-value pair can
        // ever itself exceed the 8192-byte budget — proving the
        // drop-keys loop always converges to a fitting result, never an
        // infinite loop or an oversized final payload, regardless of how
        // many oversized fields the caller throws at it.
        $huge = [];

        // The differentiating suffix comes FIRST so each key is still
        // unique after normalizeArray's 191-char mb_substr() truncation —
        // padding it at the end would collapse every key to the same
        // truncated prefix and silently collide in the result array.
        for ($i = 0; $i < 2000; $i++) {
            $huge["field_{$i}_".str_repeat('k', 180)] = str_repeat('v', 1000);
        }

        $result = $this->bounder->boundValues($huge);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertNotFalse($encoded);
        $this->assertLessThanOrEqual(8192, strlen($encoded));
        $this->assertTrue($result[AuditPayloadBounder::TRUNCATED_MARKER_KEY]);
    }

    public function test_changed_fields_are_bounded_to_a_reasonable_count(): void
    {
        $fields = array_map(fn (int $i) => "field_{$i}", range(1, 500));

        $result = $this->bounder->boundChangedFields($fields);

        $this->assertLessThanOrEqual(100, count($result));
    }

    public function test_changed_fields_rejects_non_string_and_empty_entries(): void
    {
        $result = $this->bounder->boundChangedFields(['amount', '', 123, null, 'currency_id']);

        $this->assertSame(['amount', 'currency_id'], $result);
    }

    public function test_deep_nesting_is_bounded(): void
    {
        $value = 'leaf';

        for ($i = 0; $i < 20; $i++) {
            $value = ['nested' => $value];
        }

        $result = $this->bounder->boundValues(['root' => $value]);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertNotFalse($encoded);
        $this->assertLessThanOrEqual(8192, strlen($encoded));
    }

    public function test_decimal_money_strings_are_preserved_exactly(): void
    {
        $result = $this->bounder->boundValues([
            'amount' => '1500.00',
            'fx_rate' => '1.000000',
        ]);

        $this->assertSame('1500.00', $result['amount']);
        $this->assertIsString($result['amount']);
        $this->assertSame('1.000000', $result['fx_rate']);
        $this->assertIsString($result['fx_rate']);
    }

    public function test_datetime_and_backed_enum_are_converted_safely(): void
    {
        $date = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $result = $this->bounder->boundValues([
            'occurred_at' => $date,
            'status' => \App\Enums\AuditStatus::Success,
        ]);

        $this->assertSame($date->format(DATE_ATOM), $result['occurred_at']);
        $this->assertSame('success', $result['status']);
    }

    public function test_resources_and_closures_are_rejected_safely(): void
    {
        $resource = fopen('php://memory', 'r');

        $result = $this->bounder->boundValues([
            'handle' => $resource,
            'callback' => static fn () => 'x',
        ]);

        $this->assertSame('[UNSUPPORTED_VALUE]', $result['handle']);
        $this->assertSame('[UNSUPPORTED_VALUE]', $result['callback']);

        fclose($resource);
    }

    public function test_output_is_deterministic_for_the_same_input(): void
    {
        $input = ['a' => 'x', 'b' => ['c' => 'y'], 'd' => 3];

        $first = $this->bounder->boundValues($input);
        $second = $this->bounder->boundValues($input);

        $this->assertSame($first, $second);
    }
}
