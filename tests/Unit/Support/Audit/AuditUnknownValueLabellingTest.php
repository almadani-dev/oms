<?php

namespace Tests\Unit\Support\Audit;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Models\AuditEvent;
use App\Support\Audit\AuditLabels;
use App\Support\Audit\AuditRawValue;
use PHPUnit\Framework\TestCase;

/**
 * OMS Task 9B.7 forward-compatibility fix — the two presentation primitives the
 * Audit Log UI relies on to render an out-of-vocabulary stored value:
 * AuditRawValue reads a categorical column WITHOUT resolving the model's enum
 * casts, and AuditLabels maps that raw string to Arabic with a verbatim
 * fallback.
 *
 * Pure: no database, no Filament. The rendered-surface proof lives in
 * Tests\Feature\Audit\Ui\AuditUnknownValueCompatibilityTest.
 */
class AuditUnknownValueLabellingTest extends TestCase
{
    /**
     * Builds an unsaved AuditEvent whose raw attributes are exactly what a
     * database row would hydrate into — set through setRawAttributes(), so the
     * enum casts are never invoked on the way in either.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function rowWith(array $attributes): AuditEvent
    {
        $record = new AuditEvent;
        $record->setRawAttributes($attributes, sync: true);

        return $record;
    }

    public function test_reading_an_enum_cast_column_directly_is_what_breaks(): void
    {
        $record = $this->rowWith(['actor_type' => 'future_actor', 'status' => 'pending']);

        // This is the exact failure the UI must not be able to trigger: the
        // model's domain casts are intact, so touching the attribute throws.
        $this->expectException(\ValueError::class);

        $record->actor_type;
    }

    public function test_the_raw_reader_returns_an_unknown_stored_value_untouched(): void
    {
        $record = $this->rowWith([
            'actor_type' => 'future_actor',
            'status' => 'pending',
            'event_category' => 'future_category',
            'event_action' => 'future_action',
            'subject_type' => 'future_alias',
        ]);

        $this->assertSame('future_actor', AuditRawValue::string($record, 'actor_type'));
        $this->assertSame('pending', AuditRawValue::string($record, 'status'));
        $this->assertSame('future_category', AuditRawValue::string($record, 'event_category'));
        $this->assertSame('future_action', AuditRawValue::string($record, 'event_action'));
        $this->assertSame('future_alias', AuditRawValue::string($record, 'subject_type'));
    }

    public function test_the_raw_reader_returns_null_for_a_null_or_unselected_column(): void
    {
        $record = $this->rowWith(['subject_type' => null]);

        $this->assertNull(AuditRawValue::string($record, 'subject_type'));
        $this->assertNull(AuditRawValue::string($record, 'actor_type'));
    }

    public function test_the_raw_reader_normalizes_an_enum_instance_to_its_backing_value(): void
    {
        $record = new AuditEvent;
        $record->actor_type = AuditActorType::Scheduler;
        $record->status = AuditStatus::Failure;

        $this->assertSame('scheduler', AuditRawValue::string($record, 'actor_type'));
        $this->assertSame('failure', AuditRawValue::string($record, 'status'));
    }

    public function test_known_actor_type_and_status_strings_get_arabic_labels(): void
    {
        $this->assertSame('مستخدم', AuditLabels::actorType('user'));
        $this->assertSame('النظام', AuditLabels::actorType('system'));
        $this->assertSame('المجدول', AuditLabels::actorType('scheduler'));
        $this->assertSame('قائمة الانتظار', AuditLabels::actorType('queue'));
        $this->assertSame('أمر طرفية', AuditLabels::actorType('command'));

        $this->assertSame('نجاح', AuditLabels::status('success'));
        $this->assertSame('فشل', AuditLabels::status('failure'));
    }

    public function test_the_enum_overloads_still_produce_the_same_labels(): void
    {
        foreach (AuditActorType::cases() as $case) {
            $this->assertSame(AuditLabels::actorType($case->value), AuditLabels::actorType($case));
        }

        foreach (AuditStatus::cases() as $case) {
            $this->assertSame(AuditLabels::status($case->value), AuditLabels::status($case));
        }
    }

    public function test_unknown_actor_type_and_status_fall_back_to_the_stored_value_verbatim(): void
    {
        $this->assertSame('future_actor', AuditLabels::actorType('future_actor'));
        $this->assertSame('pending', AuditLabels::status('pending'));

        // Never mangled, never truncated, never turned into a placeholder.
        $this->assertSame('<b>x</b>', AuditLabels::actorType('<b>x</b>'));
    }

    public function test_null_and_empty_still_render_as_a_dash(): void
    {
        $this->assertSame('—', AuditLabels::actorType(null));
        $this->assertSame('—', AuditLabels::actorType(''));
        $this->assertSame('—', AuditLabels::status(null));
        $this->assertSame('—', AuditLabels::status(''));
    }

    public function test_an_unknown_status_is_neutral_rather_than_reported_as_success(): void
    {
        $this->assertSame('danger', AuditLabels::statusColor('failure'));
        $this->assertSame('success', AuditLabels::statusColor('success'));
        $this->assertSame('danger', AuditLabels::statusColor(AuditStatus::Failure));

        $this->assertSame('gray', AuditLabels::statusColor('pending'));
        $this->assertSame('gray', AuditLabels::statusColor(null));
    }

    public function test_the_filter_option_maps_still_cover_every_declared_enum_case(): void
    {
        foreach (AuditActorType::cases() as $case) {
            $this->assertArrayHasKey($case->value, AuditLabels::actorTypeOptions());
        }

        foreach (AuditStatus::cases() as $case) {
            $this->assertArrayHasKey($case->value, AuditLabels::statusOptions());
        }
    }
}
