<?php

namespace Tests\Feature\Audit\Crud;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Models\AuditEvent;
use App\Models\BankType;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;

/**
 * OMS Task 9B.2 — created/updated/deleted/restored event content for
 * representative target models.
 */
class AuditedCrudServiceTest extends AuditedCrudTestCase
{
    public function test_create_produces_one_correct_event(): void
    {
        $this->actingAsSuperAdmin();
        $type = $this->partnerType();

        $partner = $this->service()->create(new Partner, [
            'name' => 'مؤسسة الخير',
            'partner_type_id' => $type->id,
            'is_donor' => true,
            'city' => 'غزة',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame('crud', $event->event_category);
        $this->assertSame('created', $event->event_action);
        $this->assertSame('partner', $event->subject_type);
        $this->assertSame((string) $partner->id, $event->subject_key);
        $this->assertSame('مؤسسة الخير', $event->subject_label);
        $this->assertSame(AuditStatus::Success, $event->status);
        $this->assertNull($event->old_values);
        $this->assertNull($event->changed_fields);

        $this->assertSame('مؤسسة الخير', $event->new_values['name']);
        $this->assertSame($type->id, $event->new_values['partner_type_id']);
        $this->assertSame('غزة', $event->new_values['city']);
        $this->assertArrayHasKey('notes', $event->new_values);

        foreach (['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'] as $technical) {
            $this->assertArrayNotHasKey($technical, $event->new_values);
        }
    }

    public function test_update_records_only_the_changed_audited_fields(): void
    {
        $this->actingAsSuperAdmin();

        $partner = Partner::create([
            'name' => 'الاسم القديم',
            'partner_type_id' => $this->partnerType()->id,
            'city' => 'رفح',
            'notes' => 'بدون تغيير',
        ]);

        $this->service()->update($partner, [
            'name' => 'الاسم الجديد',
            'city' => 'رفح',
            'notes' => 'بدون تغيير',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame('updated', $event->event_action);
        $this->assertSame(['name'], $event->changed_fields);
        $this->assertSame(['name' => 'الاسم القديم'], $event->old_values);
        $this->assertSame(['name' => 'الاسم الجديد'], $event->new_values);
        $this->assertSame('الاسم الجديد', $event->subject_label);
    }

    public function test_delete_stores_the_pre_delete_snapshot_and_keeps_the_subject_key(): void
    {
        $this->actingAsSuperAdmin();

        $bankType = BankType::create(['name' => 'بنك تجاري', 'notes' => 'ملاحظة']);
        $id = $bankType->id;

        $this->service()->delete($bankType);

        $event = AuditEvent::sole();

        $this->assertSame('deleted', $event->event_action);
        $this->assertSame('bank_type', $event->subject_type);
        $this->assertSame((string) $id, $event->subject_key);
        $this->assertSame('بنك تجاري', $event->subject_label);
        $this->assertNull($event->new_values);
        $this->assertSame(['name' => 'بنك تجاري', 'notes' => 'ملاحظة'], $event->old_values);

        $this->assertNull(BankType::find($id));
        $this->assertNotNull(BankType::withTrashed()->find($id), 'SoftDeletes must be respected.');
    }

    public function test_restore_produces_a_distinct_restored_event(): void
    {
        $this->actingAsSuperAdmin();

        $superType = TransactionSuperType::create(['name' => 'نوع رئيسي']);
        $superType->delete();

        $this->service()->restore($superType);

        $event = AuditEvent::sole();

        $this->assertSame('restored', $event->event_action);
        $this->assertSame('transaction_super_type', $event->subject_type);
        $this->assertNull($event->old_values);
        $this->assertSame('نوع رئيسي', $event->new_values['name']);
        $this->assertNotNull(TransactionSuperType::find($superType->id));
    }

    public function test_actor_snapshot_matches_the_authenticated_user(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $this->service()->create(new BankType, ['name' => 'بنك']);

        $event = AuditEvent::sole();

        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($actor->name, $event->actor_name);
        $this->assertSame($actor->email, $event->actor_email);
        $this->assertSame(AuditActorType::User, $event->actor_type);
        $this->assertContains('Super Admin', $event->actor_roles);
    }

    /**
     * ProjectObserver flips ProjectFinancialSnapshot.is_dirty on every
     * Project create/update/delete/restore. That is a write to a different,
     * unaudited table and must add no event and no payload noise.
     */
    public function test_the_project_dirty_flag_observer_adds_no_audit_noise(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->service()->create(new Project, [
            'name' => 'مشروع المياه',
            'project_super_id' => $this->projectSuper()->id,
            'project_status_id' => $this->projectStatus()->id,
            'approval_date' => '2026-03-01',
        ]);

        ProjectFinancialSnapshot::create(['project_id' => $project->id, 'is_dirty' => false]);

        $this->service()->update($project, ['name' => 'مشروع المياه المحدث']);

        $this->assertSame(2, AuditEvent::count(), 'The observer must not add its own event.');

        $updated = AuditEvent::where('event_action', 'updated')->sole();
        $this->assertSame(['name'], $updated->changed_fields);
        $this->assertArrayNotHasKey('is_dirty', $updated->new_values);

        $this->assertTrue(
            (bool) ProjectFinancialSnapshot::where('project_id', $project->id)->value('is_dirty'),
            'The observer must still have run.',
        );
    }

    public function test_project_label_uses_the_generated_code_and_name(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->service()->create(new Project, [
            'name' => 'مشروع الصحة',
            'project_super_id' => $this->projectSuper('HLT')->id,
            'project_status_id' => $this->projectStatus()->id,
            'approval_date' => '2026-03-01',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame($project->code.' — مشروع الصحة', $event->subject_label);
        $this->assertSame('2026-03-01', $event->new_values['approval_date'], 'Date casts must be stored as plain dates.');
    }

    public function test_project_cost_stores_the_project_as_a_foreign_key_plus_a_bounded_label(): void
    {
        $this->actingAsSuperAdmin();
        $project = $this->project('مشروع الإغاثة');

        $cost = $this->service()->create(new ProjectCost, [
            'project_id' => $project->id,
            'account_type_id' => $this->accountType()->id,
            'currency_id' => $this->currency()->id,
            'amount' => 2500,
        ]);

        $event = AuditEvent::sole();

        $this->assertSame('project_cost', $event->subject_type);
        $this->assertSame($project->id, $event->new_values['project_id'], 'The FK scalar itself must be stored.');
        $this->assertSame($project->code.' — مشروع الإغاثة', $event->new_values['project_label']);

        // A related model/collection is never serialized.
        foreach ($event->new_values as $value) {
            $this->assertNotIsArray($value);
        }

        $this->assertStringContainsString($project->code, (string) $event->subject_label);
        $this->assertStringContainsString('2500', (string) $event->subject_label);
        $this->assertSame($cost->id, $cost->fresh()->id);
    }

    public function test_changing_a_project_costs_project_labels_both_sides_from_their_own_foreign_key(): void
    {
        $this->actingAsSuperAdmin();

        $first = $this->project('المشروع الأول');
        $second = Project::create([
            'name' => 'المشروع الثاني',
            'project_super_id' => $first->project_super_id,
            'project_status_id' => $first->project_status_id,
            'approval_date' => '2026-04-01',
        ]);

        $cost = $this->projectCost($first);

        $this->service()->update($cost, ['project_id' => $second->id]);

        $event = AuditEvent::sole();

        // changed_fields stays a list of semantic business fields: the label
        // is a readability snapshot attached to the FK, not a field a user
        // changed.
        $this->assertSame(['project_id'], $event->changed_fields);

        $this->assertSame($first->id, $event->old_values['project_id']);
        $this->assertSame($first->code.' — المشروع الأول', $event->old_values['project_label']);

        $this->assertSame($second->id, $event->new_values['project_id']);
        $this->assertSame($second->code.' — المشروع الثاني', $event->new_values['project_label']);

        foreach ([...$event->old_values, ...$event->new_values] as $value) {
            $this->assertNotIsArray($value);
        }
    }

    /**
     * The old side must survive the project itself being gone, which is the
     * normal case when a whole project is retired — hence the withTrashed()
     * lookup in the registry's label resolver.
     */
    public function test_a_soft_deleted_previous_project_is_still_labelled_on_the_old_side(): void
    {
        $this->actingAsSuperAdmin();

        $first = $this->project('مشروع منتهٍ');
        $second = Project::create([
            'name' => 'مشروع بديل',
            'project_super_id' => $first->project_super_id,
            'project_status_id' => $first->project_status_id,
            'approval_date' => '2026-04-01',
        ]);

        $cost = $this->projectCost($first);
        $firstCode = $first->code;
        $first->delete();

        $this->service()->update($cost, ['project_id' => $second->id]);

        $event = AuditEvent::sole();

        $this->assertSame($firstCode.' — مشروع منتهٍ', $event->old_values['project_label']);
        $this->assertSame($second->code.' — مشروع بديل', $event->new_values['project_label']);
    }

    public function test_changing_an_unrelated_field_performs_no_relationship_labelling(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project('مشروع ثابت');
        $cost = $this->projectCost($project);

        $this->service()->update($cost, ['amount' => 4200]);

        $event = AuditEvent::sole();

        $this->assertSame(['amount'], $event->changed_fields);
        $this->assertArrayNotHasKey('project_id', $event->old_values);
        $this->assertArrayNotHasKey('project_label', $event->old_values);
        $this->assertArrayNotHasKey('project_label', $event->new_values);
        $this->assertSame(['amount' => '4200.00'], $event->new_values);
    }

    public function test_fiscal_year_dates_and_booleans_round_trip_readably(): void
    {
        $this->actingAsSuperAdmin();

        $year = $this->service()->create(new FiscalYear, [
            'name' => 'السنة المالية 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);

        $event = AuditEvent::sole();

        $this->assertSame('2026-01-01', $event->new_values['start_date']);
        $this->assertSame('2026-12-31', $event->new_values['end_date']);
        $this->assertTrue($event->new_values['is_active']);
        $this->assertSame('السنة المالية 2026', $event->subject_label);
        $this->assertNotNull($year->id);
    }

    public function test_transaction_type_records_its_super_type_foreign_key(): void
    {
        $this->actingAsSuperAdmin();
        $super = TransactionSuperType::create(['name' => 'نوع رئيسي']);

        $this->service()->create(new TransactionType, [
            'name' => 'نوع فرعي',
            'transaction_super_type_id' => $super->id,
        ]);

        $event = AuditEvent::sole();

        $this->assertSame('transaction_type', $event->subject_type);
        $this->assertSame($super->id, $event->new_values['transaction_super_type_id']);
        $this->assertSame('نوع فرعي', $event->subject_label);
    }

    public function test_no_request_metadata_is_fabricated_outside_an_http_request(): void
    {
        $this->actingAsSuperAdmin();

        $this->service()->create(new BankType, ['name' => 'بنك']);

        $event = AuditEvent::sole();

        $this->assertNull($event->ip_address);
        $this->assertNull($event->user_agent);
        $this->assertNull($event->route_name);
        $this->assertNull($event->http_method);
    }

    private function assertNotIsArray(mixed $value): void
    {
        $this->assertFalse(is_array($value), 'Audit payload values must stay scalar.');
    }
}
