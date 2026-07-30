<?php

namespace Tests\Feature\Audit\Ui;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * OMS Task 9B.7 forward-compatibility regression suite: a historical row whose
 * categorical values this build does not know must still be READABLE.
 *
 * WHY THESE ROWS ARE INSERTED THROUGH THE QUERY BUILDER. `actor_type` and
 * `status` are cast to the domain enums AuditActorType / AuditStatus on the
 * model, so Eloquent could never be used to WRITE an out-of-vocabulary value —
 * which is the point: the value gets into the table the same way real ones
 * would, i.e. written by a LATER build of this application (or restored from a
 * backup taken by one) into a column the schema declares as a plain varchar.
 * Every insert below therefore goes straight to the table, bypassing Eloquent
 * entirely; nothing here weakens a cast, a recorder, AuditRedactor, or
 * AuditEvent's immutability hooks.
 *
 * Every assertion runs against the SQLite :memory: schema inherited from
 * AuditTestCase — the real local `audit_events` table is never touched.
 */
class AuditUnknownValueCompatibilityTest extends AuditUiTestCase
{
    /**
     * One raw row, written with the query builder so no cast, mutator or model
     * event ever sees it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertRawEvent(array $attributes = []): int
    {
        return DB::table('audit_events')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'event_category' => 'crud',
            'event_action' => 'updated',
            'subject_type' => 'project',
            'subject_key' => '1',
            'subject_label' => 'PRJ-001 — مشروع تجريبي',
            'actor_user_id' => null,
            'actor_name' => 'مدير النظام',
            'actor_email' => 'admin@example.test',
            'actor_roles' => json_encode(['Super Admin'], JSON_UNESCAPED_UNICODE),
            'actor_type' => 'user',
            'old_values' => null,
            'new_values' => null,
            'changed_fields' => null,
            'reason' => null,
            'correlation_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'route_name' => null,
            'http_method' => 'POST',
            'status' => 'success',
            'created_at' => now()->toDateTimeString(),
        ], $attributes));
    }

    /**
     * The unknown-value row used by most cases below. Every string stays within
     * its column's real length (`actor_type` 20, `status` 10, `event_category`
     * 40, `event_action` 60, `subject_type` 100), so these rows are storable on
     * the production MySQL schema too, not only on SQLite.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertFutureEvent(array $attributes = []): int
    {
        return $this->insertRawEvent(array_merge([
            'event_category' => 'future_category',
            'event_action' => 'future_action',
            'subject_type' => 'future_alias',
            'actor_type' => 'future_actor',
            'status' => 'pending',
        ], $attributes));
    }

    public function test_the_query_builder_really_stored_a_value_the_enum_cast_rejects(): void
    {
        $id = $this->insertFutureEvent();

        // Proves the fixture is the real hazard and not a no-op: the stored
        // value is intact in the table, and the model's domain cast still
        // refuses it — the casts were NOT weakened to make this pass.
        $this->assertSame('future_actor', DB::table('audit_events')->where('id', $id)->value('actor_type'));
        $this->assertSame('pending', DB::table('audit_events')->where('id', $id)->value('status'));

        $this->expectException(\ValueError::class);

        AuditEvent::query()->findOrFail($id)->actor_type;
    }

    public function test_unknown_actor_type_and_status_render_on_the_list(): void
    {
        $this->insertFutureEvent();

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            ->assertSee('future_actor')
            ->assertSee('pending')
            ->assertSee('future_category')
            ->assertSee('future_action')
            ->assertSee('future_alias');
    }

    public function test_unknown_actor_type_and_status_render_on_the_view_page(): void
    {
        $id = $this->insertFutureEvent();

        $this->actingAs($this->superAdmin());

        // Both the plain HTTP route and the Livewire component, because the
        // page is re-rendered on every subsequent Livewire hydration too.
        $this->get(self::LIST_URL.'/'.$id)->assertOk()->assertSee('future_actor');

        Livewire::test(ViewAuditEvent::class, ['record' => $id])
            ->assertOk()
            ->assertSee('future_actor')
            ->assertSee('pending')
            ->assertSee('future_category')
            ->assertSee('future_action')
            ->assertSee('future_alias');
    }

    public function test_unknown_values_survive_search_sorting_and_pagination(): void
    {
        foreach (range(1, 15) as $i) {
            $this->insertFutureEvent([
                'subject_key' => (string) $i,
                'subject_label' => "سجل مستقبلي {$i}",
                'created_at' => now()->subMinutes($i)->toDateTimeString(),
            ]);
        }

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            // pagination
            ->call('gotoPage', 2, 'page')
            ->assertOk()
            ->assertSee('future_actor')
            ->call('gotoPage', 1, 'page')
            // sorting, on both a sortable categorical column and the default one
            ->call('sortTable', 'event_category', 'asc')
            ->assertOk()
            ->assertSee('future_category')
            ->call('sortTable', 'event_action', 'desc')
            ->assertOk()
            ->call('sortTable', 'created_at', 'asc')
            ->assertOk()
            // search
            ->set('tableSearch', 'سجل مستقبلي 7')
            ->assertOk()
            ->assertSee('سجل مستقبلي 7')
            ->assertSee('future_actor')
            ->assertSee('pending');
    }

    public function test_an_unknown_subject_alias_renders_safely_on_both_surfaces(): void
    {
        $id = $this->insertRawEvent([
            'subject_type' => 'future_subject_alias',
            'subject_label' => 'سجل من نوع غير معروف',
        ]);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            ->assertSee('future_subject_alias');

        Livewire::test(ViewAuditEvent::class, ['record' => $id])
            ->assertOk()
            ->assertSee('future_subject_alias')
            ->assertSee('سجل من نوع غير معروف');
    }

    public function test_malicious_html_inside_unknown_values_is_escaped(): void
    {
        $id = $this->insertRawEvent([
            'actor_type' => '<b>x</b>',
            'status' => '<i>y</i>',
            'event_category' => '<script>alert(1)</script>',
            'event_action' => '<img src=x onerror=alert(1)>',
            'subject_type' => '<svg onload=alert(1)>',
        ]);

        $this->actingAs($this->superAdmin());

        foreach ([
            Livewire::test(ListAuditEvents::class)->assertOk()->html(),
            Livewire::test(ViewAuditEvent::class, ['record' => $id])->assertOk()->html(),
        ] as $html) {
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
            $this->assertStringNotContainsString('<svg onload=alert(1)>', $html);
            $this->assertStringNotContainsString('<b>x</b>', $html);
            $this->assertStringNotContainsString('<i>y</i>', $html);

            // Present, but as visible text.
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        }
    }

    public function test_opening_records_with_unknown_values_creates_no_audit_event(): void
    {
        $id = $this->insertFutureEvent();

        $this->actingAs($this->superAdmin());

        $before = DB::table('audit_events')->count();

        $this->get(self::LIST_URL)->assertOk();
        $this->get(self::LIST_URL.'/'.$id)->assertOk();

        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            ->set('tableSearch', 'مشروع')
            ->call('sortTable', 'event_category', 'asc')
            ->call('gotoPage', 1, 'page')
            ->assertOk();

        Livewire::test(ViewAuditEvent::class, ['record' => $id])->assertOk();

        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertSame(1, $before);
    }

    public function test_known_values_still_get_their_arabic_labels_next_to_an_unknown_row(): void
    {
        $known = $this->insertRawEvent([
            'event_category' => 'security',
            'event_action' => 'login_failed',
            'subject_type' => 'authentication',
            'actor_type' => 'system',
            'status' => 'failure',
            'created_at' => now()->toDateTimeString(),
        ]);

        $this->insertFutureEvent(['created_at' => now()->subMinute()->toDateTimeString()]);

        $this->actingAs($this->superAdmin());

        // One unknown row on the page must not degrade the known row beside it.
        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            ->assertSee('أمان وصلاحيات')
            ->assertSee('محاولة دخول فاشلة')
            ->assertSee('المصادقة')
            ->assertSee('النظام')
            ->assertSee('فشل')
            ->assertSee('future_actor')
            ->assertSee('pending');

        Livewire::test(ViewAuditEvent::class, ['record' => $known])
            ->assertOk()
            ->assertSee('أمان وصلاحيات')
            ->assertSee('محاولة دخول فاشلة')
            ->assertSee('النظام')
            ->assertSee('فشل');
    }

    public function test_no_stored_audit_row_is_modified_by_rendering_it(): void
    {
        $id = $this->insertFutureEvent();

        $before = (array) DB::table('audit_events')->where('id', $id)->first();

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)->assertOk();
        Livewire::test(ViewAuditEvent::class, ['record' => $id])->assertOk();

        $this->assertSame($before, (array) DB::table('audit_events')->where('id', $id)->first());
    }
}
