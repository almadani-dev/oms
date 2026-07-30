<?php

namespace Tests\Feature\Audit\Ui;

use App\Enums\AuditActorType;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * OMS Task 9B.7 §5/§9 — filters, ordering and the query shape behind them.
 */
class AuditEventFiltersTest extends AuditUiTestCase
{
    public function test_default_ordering_is_newest_first(): void
    {
        $oldest = $this->makeEvent(['subject_label' => 'الأقدم']);
        $middle = $this->makeEvent(['subject_label' => 'الأوسط']);
        $newest = $this->makeEvent(['subject_label' => 'الأحدث']);

        // All three share a created_at second in a fast test run, which is
        // exactly the tie the id tiebreaker exists for.
        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
    }

    public function test_default_sort_query_orders_by_created_at_then_id_descending(): void
    {
        $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $orders = [];
        DB::listen(function ($query) use (&$orders): void {
            if (str_contains($query->sql, 'from "audit_events"') && str_contains($query->sql, 'order by')) {
                $orders[] = $query->sql;
            }
        });

        Livewire::test(ListAuditEvents::class)->assertOk();

        $this->assertNotEmpty($orders);
        $this->assertStringContainsString(
            'order by "created_at" desc, "audit_events"."id" desc',
            $orders[0],
        );
    }

    public function test_date_range_filter(): void
    {
        $old = $this->makeEvent(['subject_label' => 'حدث قديم']);
        AuditEvent::withoutEvents(fn () => DB::table('audit_events')
            ->where('id', $old->getKey())
            ->update(['created_at' => Carbon::parse('2026-01-10 08:00:00')]));

        $recent = $this->makeEvent(['subject_label' => 'حدث حديث']);
        AuditEvent::withoutEvents(fn () => DB::table('audit_events')
            ->where('id', $recent->getKey())
            ->update(['created_at' => Carbon::parse('2026-06-20 08:00:00')]));

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.created_at.from', '2026-06-01')
            ->set('tableFilters.created_at.until', '2026-06-30')
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);

        // The boundary day itself is inclusive on both ends.
        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.created_at.from', '2026-01-10')
            ->set('tableFilters.created_at.until', '2026-01-10')
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$recent]);
    }

    public function test_date_filter_uses_a_sargable_range_not_a_date_function(): void
    {
        $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            if (str_contains($query->sql, 'from "audit_events"')) {
                $sql[] = $query->sql;
            }
        });

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.created_at.from', '2026-01-01')
            ->assertOk();

        $this->assertNotEmpty($sql);

        foreach ($sql as $statement) {
            // strftime(...) is what whereDate() compiles to on SQLite — its
            // presence would mean the created_at index can never be used.
            $this->assertStringNotContainsString('strftime', $statement);
        }
    }

    public function test_event_category_filter(): void
    {
        $crud = $this->makeEvent(['event_category' => 'crud', 'subject_label' => 'سجل عام']);
        $financial = $this->makeEvent(['event_category' => 'financial', 'subject_label' => 'سجل مالي']);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.event_category.value', 'financial')
            ->assertCanSeeTableRecords([$financial])
            ->assertCanNotSeeTableRecords([$crud]);
    }

    public function test_event_action_filter(): void
    {
        $created = $this->makeEvent(['event_action' => 'created', 'subject_label' => 'إنشاء']);
        $deleted = $this->makeEvent(['event_action' => 'deleted', 'subject_label' => 'حذف']);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.event_action.value', 'deleted')
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$created]);
    }

    public function test_actor_type_filter(): void
    {
        $byUser = $this->makeEvent(['actor_type' => AuditActorType::User, 'subject_label' => 'بواسطة مستخدم']);
        $bySystem = $this->makeEvent(['actor_type' => AuditActorType::System, 'subject_label' => 'بواسطة النظام']);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.actor_type.value', 'system')
            ->assertCanSeeTableRecords([$bySystem])
            ->assertCanNotSeeTableRecords([$byUser]);
    }

    public function test_actor_user_filter_matches_the_stored_actor_user_id(): void
    {
        $alice = User::factory()->create(['name' => 'أليس']);
        $bob = User::factory()->create(['name' => 'بوب']);

        $aliceEvent = $this->makeEvent(['actor_user_id' => $alice->getKey(), 'actor_name' => 'أليس']);
        $bobEvent = $this->makeEvent(['actor_user_id' => $bob->getKey(), 'actor_name' => 'بوب']);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.actor_user_id.value', $alice->getKey())
            ->assertCanSeeTableRecords([$aliceEvent])
            ->assertCanNotSeeTableRecords([$bobEvent]);
    }

    public function test_actor_user_filter_never_joins_or_exists_against_users(): void
    {
        $alice = User::factory()->create(['name' => 'أليس']);
        $this->makeEvent(['actor_user_id' => $alice->getKey()]);

        $this->actingAs($this->superAdmin());

        // Mount first, so only the statements the FILTER itself produces are
        // captured (the initial unfiltered mount runs its own queries).
        $component = Livewire::test(ListAuditEvents::class);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            if (str_contains($query->sql, 'from "audit_events"')) {
                $sql[] = $query->sql;
            }
        });

        $component->set('tableFilters.actor_user_id.value', $alice->getKey())->assertOk();

        $this->assertNotEmpty($sql);

        foreach ($sql as $statement) {
            $this->assertStringNotContainsString('exists (select', $statement);
            $this->assertStringContainsString('"actor_user_id" = ?', $statement);
        }
    }

    public function test_subject_type_filter(): void
    {
        $project = $this->makeEvent(['subject_type' => 'project', 'subject_label' => 'مشروع']);
        $account = $this->makeEvent(['subject_type' => 'account', 'subject_label' => 'حساب']);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.subject_type.value', 'account')
            ->assertCanSeeTableRecords([$account])
            ->assertCanNotSeeTableRecords([$project]);
    }

    public function test_correlation_id_filter_is_an_exact_match(): void
    {
        $matching = (string) Str::uuid();
        $other = (string) Str::uuid();

        $target = $this->makeEvent(['correlation_id' => $matching, 'subject_label' => 'مرتبط']);
        $unrelated = $this->makeEvent(['correlation_id' => $other, 'subject_label' => 'غير مرتبط']);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.correlation_id.value', $matching)
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$unrelated]);

        // A partial prefix must NOT match — this is an exact filter, not a LIKE.
        Livewire::test(ListAuditEvents::class)
            ->set('tableFilters.correlation_id.value', substr($matching, 0, 8))
            ->assertCanNotSeeTableRecords([$target, $unrelated]);
    }

    public function test_filter_options_never_run_a_distinct_over_the_audit_table(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeEvent(['subject_key' => (string) $i]);
        }

        $this->actingAs($this->superAdmin());

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        Livewire::test(ListAuditEvents::class)->assertOk();

        foreach ($sql as $statement) {
            if (str_contains($statement, 'from "audit_events"')) {
                $this->assertStringNotContainsString('distinct', $statement);
            }
        }
    }

    public function test_search_matches_subject_and_actor_snapshot_columns(): void
    {
        $target = $this->makeEvent([
            'subject_label' => 'مشروع البحث الفريد',
            'actor_name' => 'باحث',
        ]);
        $other = $this->makeEvent(['subject_label' => 'شيء آخر', 'actor_name' => 'شخص آخر']);

        $this->actingAs($this->superAdmin());

        Livewire::test(ListAuditEvents::class)
            ->set('tableSearch', 'البحث الفريد')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListAuditEvents::class)
            ->set('tableSearch', 'باحث')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_list_is_database_paginated(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makeEvent(['subject_label' => "سجل رقم {$i}"]);
        }

        $this->actingAs($this->superAdmin());

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            if (str_contains($query->sql, 'from "audit_events"') && str_contains($query->sql, 'limit')) {
                $sql[] = $query->sql;
            }
        });

        Livewire::test(ListAuditEvents::class)->assertOk();

        $this->assertNotEmpty($sql, 'the list query must apply a SQL limit, not paginate in PHP');
    }
}
