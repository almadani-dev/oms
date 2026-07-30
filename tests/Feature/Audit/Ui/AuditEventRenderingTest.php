<?php

namespace Tests\Feature\Audit\Ui;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Models\User;
use App\Services\Audit\AuditRedactor;
use App\Support\Audit\AuditPayloadPresenter;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * OMS Task 9B.7 §6/§7 — the detail view renders stored snapshots safely.
 */
class AuditEventRenderingTest extends AuditUiTestCase
{
    protected function viewPage(int $key): Testable
    {
        return Livewire::test(ViewAuditEvent::class, ['record' => $key]);
    }

    public function test_actor_snapshot_renders_after_the_linked_user_is_deleted(): void
    {
        $actor = User::factory()->create(['name' => 'موظف سابق', 'email' => 'gone@example.test']);

        $event = $this->makeEvent([
            'actor_user_id' => $actor->getKey(),
            'actor_name' => 'موظف سابق',
            'actor_email' => 'gone@example.test',
            'actor_roles' => ['Accountant'],
        ]);

        // Hard-delete the user: the FK is ON DELETE SET NULL, so actor_user_id
        // becomes null while the snapshot columns stay exactly as recorded.
        DB::table('users')->where('id', $actor->getKey())->delete();

        $event->refresh();
        $this->assertNull($event->actor_user_id);

        $this->actingAs($this->superAdmin());

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee('موظف سابق')
            ->assertSee('gone@example.test')
            ->assertSee('Accountant')
            ->assertSee('غير مرتبط بمستخدم (أو تم حذف المستخدم)');
    }

    public function test_actor_snapshot_renders_when_actor_user_id_was_never_set(): void
    {
        $event = $this->makeEvent([
            'actor_user_id' => null,
            'actor_name' => null,
            'actor_email' => null,
            'actor_roles' => null,
            'actor_type' => AuditActorType::Scheduler,
            'ip_address' => null,
            'user_agent' => null,
            'route_name' => null,
            'http_method' => null,
        ]);

        $this->actingAs($this->superAdmin());

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee('المجدول')
            ->assertSee('غير متاح')
            // The whole request section is hidden for a non-interactive actor
            // rather than shown with fabricated/blank metadata.
            ->assertDontSee('بيانات الطلب');
    }

    public function test_subject_snapshot_renders_without_loading_the_subject_model(): void
    {
        $event = $this->makeEvent([
            'subject_type' => 'project',
            'subject_key' => '99999',
            'subject_label' => 'PRJ-099 — مشروع محذوف',
        ]);

        $this->actingAs($this->superAdmin());

        // No `projects` row with id 99999 exists at all — the label must still
        // render, proving the view reads the snapshot and never the model.
        $this->assertDatabaseMissing('projects', ['id' => 99999]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee('PRJ-099 — مشروع محذوف')
            ->assertSee('مشروع');

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('from "projects"', $sql);
        }
    }

    public function test_old_and_new_values_render_as_readable_key_value_pairs(): void
    {
        $event = $this->makeEvent([
            'old_values' => ['name' => 'الاسم القديم', 'amount' => '1500.00'],
            'new_values' => ['name' => 'الاسم الجديد', 'amount' => '2500.50'],
            'changed_fields' => ['name', 'amount'],
        ]);

        $this->actingAs($this->superAdmin());

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee('القيم القديمة')
            ->assertSee('القيم الجديدة')
            ->assertSee('الاسم القديم')
            ->assertSee('الاسم الجديد')
            ->assertSee('1500.00')
            ->assertSee('2500.50');
    }

    public function test_changed_fields_render_with_arabic_labels(): void
    {
        $event = $this->makeEvent([
            'old_values' => ['amount' => '10.00'],
            'new_values' => ['amount' => '20.00'],
            'changed_fields' => ['amount', 'some_future_field'],
        ]);

        $this->actingAs($this->superAdmin());

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee('الحقول التي تغيرت')
            ->assertSee('المبلغ (amount)')
            // Unknown field: still listed, by its stored technical name.
            ->assertSee('some_future_field');
    }

    public function test_redacted_values_stay_visibly_redacted(): void
    {
        $event = $this->makeEvent([
            'new_values' => ['password' => AuditRedactor::MARKER, 'name' => 'مستخدم'],
        ]);

        $this->actingAs($this->superAdmin());

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee(AuditRedactor::MARKER);
    }

    public function test_decimal_strings_are_never_converted_to_floats(): void
    {
        $event = $this->makeEvent([
            'new_values' => [
                'amount' => '1500.00',
                'rate' => '0.123456',
                'zero' => '0.00',
            ],
        ]);

        $this->actingAs($this->superAdmin());

        $presented = AuditPayloadPresenter::keyValue($event->new_values);

        $this->assertSame('1500.00', $presented['المبلغ (amount)']);
        $this->assertSame('0.123456', $presented['سعر الصرف (rate)']);
        $this->assertSame('0.00', $presented['zero']);

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee('1500.00')
            ->assertSee('0.123456')
            ->assertSee('0.00');
    }

    public function test_unknown_category_action_and_subject_alias_render_safely(): void
    {
        $event = $this->makeEvent([
            'event_category' => 'future_category',
            'event_action' => 'future_action',
            'subject_type' => 'future_alias',
        ]);

        $this->actingAs($this->superAdmin());

        // Falls back to the stored value verbatim, on both surfaces.
        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee('future_category')
            ->assertSee('future_action')
            ->assertSee('future_alias');

        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            ->assertSee('future_category')
            ->assertSee('future_action');
    }

    public function test_malicious_html_and_script_payloads_are_escaped(): void
    {
        $payload = '<script>alert(1)</script>';

        $event = $this->makeEvent([
            'subject_label' => $payload,
            'new_values' => ['notes' => $payload, '<img src=x onerror=alert(1)>' => 'قيمة'],
        ]);

        $this->actingAs($this->superAdmin());

        $html = $this->viewPage($event->getKey())->assertOk()->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        $listHtml = Livewire::test(ListAuditEvents::class)->assertOk()->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $listHtml);
    }

    public function test_null_boolean_and_array_values_render_distinctly(): void
    {
        $event = $this->makeEvent([
            'new_values' => [
                'nullable' => null,
                'yes' => true,
                'no' => false,
                'blank' => '',
                'empty_list' => [],
                'components' => ['database' => true, 'private_attachments' => false],
                'list' => ['a', 'b'],
            ],
        ]);

        $presented = AuditPayloadPresenter::keyValue($event->new_values);

        $this->assertSame(AuditPayloadPresenter::NULL_LABEL, $presented['nullable']);
        $this->assertSame(AuditPayloadPresenter::TRUE_LABEL, $presented['yes']);
        $this->assertSame(AuditPayloadPresenter::FALSE_LABEL, $presented['no']);
        $this->assertSame(AuditPayloadPresenter::EMPTY_STRING_LABEL, $presented['blank']);
        $this->assertSame(AuditPayloadPresenter::EMPTY_ARRAY_LABEL, $presented['empty_list']);
        $this->assertSame(AuditPayloadPresenter::TRUE_LABEL, $presented['المكوّنات (components) · database']);
        $this->assertSame(AuditPayloadPresenter::FALSE_LABEL, $presented['المكوّنات (components) · private_attachments']);
        $this->assertSame('a', $presented['list · #1']);
        $this->assertSame('b', $presented['list · #2']);

        $this->actingAs($this->superAdmin());

        $this->viewPage($event->getKey())
            ->assertOk()
            ->assertSee(AuditPayloadPresenter::NULL_LABEL)
            ->assertSee(AuditPayloadPresenter::EMPTY_ARRAY_LABEL)
            ->assertSee(AuditPayloadPresenter::TRUE_LABEL);
    }

    public function test_failure_status_and_arabic_labels_render_on_the_list(): void
    {
        $this->makeEvent([
            'event_category' => 'security',
            'event_action' => 'login_failed',
            'subject_type' => 'authentication',
            'status' => AuditStatus::Failure,
        ]);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            ->assertSee('أمان وصلاحيات')
            ->assertSee('محاولة دخول فاشلة')
            ->assertSee('المصادقة')
            ->assertSee('فشل');
    }

    public function test_the_list_query_never_selects_the_json_payload_columns(): void
    {
        $this->makeEvent([
            'old_values' => ['name' => 'قديم'],
            'new_values' => ['name' => 'جديد'],
            'changed_fields' => ['name'],
        ]);

        $this->actingAs($this->superAdmin());

        $selects = [];
        DB::listen(function ($query) use (&$selects): void {
            if (str_contains($query->sql, 'from "audit_events"')) {
                $selects[] = $query->sql;
            }
        });

        Livewire::test(ListAuditEvents::class)->assertOk();

        $this->assertNotEmpty($selects);

        foreach ($selects as $sql) {
            foreach (['"old_values"', '"new_values"', '"changed_fields"'] as $jsonColumn) {
                $this->assertStringNotContainsString($jsonColumn, $sql);
            }
        }
    }
}
