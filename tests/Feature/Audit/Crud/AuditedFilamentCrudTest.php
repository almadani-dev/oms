<?php

namespace Tests\Feature\Audit\Crud;

use App\Filament\Resources\PartnerTypes\Pages\CreatePartnerType;
use App\Filament\Resources\PartnerTypes\Pages\EditPartnerType;
use App\Filament\Resources\PartnerTypes\Pages\ListPartnerTypes;
use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\CostsRelationManager;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\AuditEvent;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use Livewire\Livewire;

/**
 * OMS Task 9B.2 — the real Filament write paths, end to end: exactly one
 * AuditEvent per logical user action, with every pre-existing CRUD behavior
 * (success notification, create/edit -> View, delete -> List, policies)
 * unchanged.
 */
class AuditedFilamentCrudTest extends AuditedCrudTestCase
{
    // -----------------------------------------------------------------
    // Full-page Create / Edit / Delete
    // -----------------------------------------------------------------

    public function test_the_create_page_writes_exactly_one_created_event_and_still_redirects_to_view(): void
    {
        $actor = $this->actingAsSuperAdmin();

        Livewire::test(CreatePartnerType::class)
            ->fillForm(['name' => 'نوع جديد'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect(PartnerTypeResource::getUrl('view', [
                'record' => PartnerType::where('name', 'نوع جديد')->firstOrFail(),
            ]));

        $event = AuditEvent::sole();

        $this->assertSame('crud', $event->event_category);
        $this->assertSame('created', $event->event_action);
        $this->assertSame('partner_type', $event->subject_type);
        $this->assertSame('نوع جديد', $event->subject_label);
        $this->assertSame($actor->id, $event->actor_user_id);
    }

    public function test_the_edit_page_writes_exactly_one_updated_event_and_still_redirects_to_view(): void
    {
        $this->actingAsSuperAdmin();
        $record = PartnerType::create(['name' => 'قبل التعديل']);

        Livewire::test(EditPartnerType::class, ['record' => $record->getKey()])
            ->fillForm(['name' => 'بعد التعديل'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect(PartnerTypeResource::getUrl('view', ['record' => $record]));

        $event = AuditEvent::sole();

        $this->assertSame('updated', $event->event_action);
        $this->assertSame(['name'], $event->changed_fields);
        $this->assertSame(['name' => 'قبل التعديل'], $event->old_values);
        $this->assertSame(['name' => 'بعد التعديل'], $event->new_values);
        $this->assertSame('بعد التعديل', $record->fresh()->name);
    }

    public function test_saving_the_edit_page_without_changes_writes_no_event(): void
    {
        $this->actingAsSuperAdmin();
        $record = PartnerType::create(['name' => 'بدون تغيير']);

        Livewire::test(EditPartnerType::class, ['record' => $record->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_deleting_from_the_edit_page_writes_one_deleted_event_and_still_redirects_to_the_list(): void
    {
        $this->actingAsSuperAdmin();
        $record = PartnerType::create(['name' => 'للحذف']);

        Livewire::test(EditPartnerType::class, ['record' => $record->getKey()])
            ->callAction('delete')
            ->assertRedirect(PartnerTypeResource::getUrl('index'));

        $event = AuditEvent::sole();

        $this->assertSame('deleted', $event->event_action);
        $this->assertSame((string) $record->getKey(), $event->subject_key);
        $this->assertSame(['name' => 'للحذف', 'notes' => null], $event->old_values);

        $this->assertNull(PartnerType::find($record->getKey()));
        $this->assertNotNull(PartnerType::withTrashed()->find($record->getKey()), 'SoftDeletes must be respected.');
    }

    public function test_a_bulk_delete_writes_one_event_per_record(): void
    {
        $this->actingAsSuperAdmin();

        $records = collect(['أ', 'ب', 'ج'])->map(fn (string $name) => PartnerType::create(['name' => $name]));

        Livewire::test(ListPartnerTypes::class)
            ->callTableBulkAction('delete', $records);

        $this->assertSame(3, AuditEvent::where('event_action', 'deleted')->count());
        $this->assertSame(3, AuditEvent::count());
        $this->assertSame(
            $records->pluck('id')->map(fn (int $id) => (string) $id)->sort()->values()->all(),
            AuditEvent::pluck('subject_key')->sort()->values()->all(),
        );
        $this->assertSame(0, PartnerType::count());
        $this->assertSame(3, PartnerType::withTrashed()->count());
    }

    // -----------------------------------------------------------------
    // Project — generated code + observer coexistence
    // -----------------------------------------------------------------

    public function test_creating_a_project_through_its_page_writes_one_event_including_the_generated_code(): void
    {
        $this->actingAsSuperAdmin();

        $super = $this->projectSuper('WSH');
        $status = $this->projectStatus();
        $donor = $this->partner('جهة مانحة');

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'مشروع المياه',
                'project_super_id' => $super->id,
                'project_status_id' => $status->id,
                'donor_id' => $donor->id,
                'approval_date' => '2026-05-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::firstOrFail();
        $event = AuditEvent::sole();

        $this->assertSame('project', $event->subject_type);
        $this->assertSame($project->code, $event->new_values['code']);
        $this->assertSame($donor->id, $event->new_values['donor_id']);
        $this->assertSame('2026-05-01', $event->new_values['approval_date']);
        $this->assertSame($project->code.' — مشروع المياه', $event->subject_label);
    }

    public function test_editing_a_project_writes_one_event_despite_the_snapshot_observer(): void
    {
        $this->actingAsSuperAdmin();
        $project = $this->project('مشروع قديم');

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['name' => 'مشروع محدث'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProjectResource::getUrl('view', ['record' => $project]));

        $event = AuditEvent::sole();

        $this->assertSame('updated', $event->event_action);
        $this->assertSame(['name'], $event->changed_fields);
    }

    // -----------------------------------------------------------------
    // Relation manager (the only in-place modal write path in this phase)
    // -----------------------------------------------------------------

    public function test_the_project_costs_relation_manager_audits_create_edit_and_delete(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project('مشروع التكاليف');
        $currency = $this->currency();

        Livewire::test(CostsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => ViewProject::class,
        ])
            ->callTableAction('create', data: ['amount' => 750, 'currency_id' => $currency->id, 'notes' => 'دفعة أولى']);

        $cost = ProjectCost::firstOrFail();

        $created = AuditEvent::sole();
        $this->assertSame('created', $created->event_action);
        $this->assertSame('project_cost', $created->subject_type);
        $this->assertSame($project->id, $created->new_values['project_id'], 'The relationship must have applied the owner FK.');
        $this->assertSame($project->code.' — مشروع التكاليف', $created->new_values['project_label']);

        Livewire::test(CostsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => ViewProject::class,
        ])
            ->callTableAction('edit', $cost, data: ['amount' => 900, 'currency_id' => $currency->id, 'notes' => 'دفعة أولى']);

        $this->assertSame(2, AuditEvent::count());
        $updated = AuditEvent::where('event_action', 'updated')->sole();
        $this->assertSame(['amount'], $updated->changed_fields);
        $this->assertSame('900.00', $updated->new_values['amount']);

        Livewire::test(CostsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => ViewProject::class,
        ])
            ->callTableAction('delete', $cost);

        $this->assertSame(3, AuditEvent::count());
        $deleted = AuditEvent::where('event_action', 'deleted')->sole();
        $this->assertSame((string) $cost->id, $deleted->subject_key);
        $this->assertSame('900.00', $deleted->old_values['amount']);
        $this->assertNull(ProjectCost::find($cost->id));
    }

    // -----------------------------------------------------------------
    // Authorization is untouched
    // -----------------------------------------------------------------

    public function test_an_unauthorized_user_still_cannot_reach_the_create_page_and_writes_no_event(): void
    {
        $this->actingAs($this->userWithPermissions(['partner_types.view_any']));

        $this->get(PartnerTypeResource::getUrl('create'))->assertForbidden();

        $this->assertSame(0, AuditEvent::count());
        $this->assertSame(0, PartnerType::count());
    }

    public function test_an_unauthorized_user_cannot_delete_and_writes_no_event(): void
    {
        $record = PartnerType::create(['name' => 'محمي']);

        $this->actingAs($this->userWithPermissions([
            'partner_types.view_any', 'partner_types.view', 'partner_types.update',
        ]));

        Livewire::test(EditPartnerType::class, ['record' => $record->getKey()])
            ->assertActionHidden('delete');

        $this->assertSame(0, AuditEvent::count());
        $this->assertNotNull(PartnerType::find($record->getKey()));
    }
}
