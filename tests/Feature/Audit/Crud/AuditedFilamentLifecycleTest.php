<?php

namespace Tests\Feature\Audit\Crud;

use App\Filament\Resources\PartnerTypes\Pages\CreatePartnerType;
use App\Filament\Resources\PartnerTypes\Pages\EditPartnerType;
use App\Filament\Resources\PartnerTypes\Pages\ListPartnerTypes;
use App\Models\AuditEvent;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * OMS Task 9B.2 — proof that swapping only Filament's process closure left
 * the surrounding page/action lifecycle intact.
 *
 * The redirect standard, success notifications and authorization are already
 * covered (Tests\Feature\Crud\CrudRedirectStandardTest and
 * AuditedFilamentCrudTest); this file covers the four behaviors those do not
 * reach: before/after hooks still firing around the replaced handler,
 * validation still short-circuiting before any audited write, bulk failure
 * reporting, and post-bulk deselection.
 */
class AuditedFilamentLifecycleTest extends AuditedCrudTestCase
{
    // -----------------------------------------------------------------
    // Lifecycle hooks still surround the replaced handler
    // -----------------------------------------------------------------

    public function test_create_hooks_still_fire_around_the_audited_handler(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(HookSpyCreatePartnerType::class)
            ->fillForm(['name' => 'مع الخطافات'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['beforeValidate', 'afterValidate', 'beforeCreate', 'record-created', 'afterCreate'],
            HookSpyCreatePartnerType::$calls,
        );

        $this->assertSame(1, AuditEvent::where('event_action', 'created')->count());
    }

    public function test_save_hooks_still_fire_around_the_audited_handler(): void
    {
        $this->actingAsSuperAdmin();
        $record = PartnerType::create(['name' => 'قبل']);

        Livewire::test(HookSpyEditPartnerType::class, ['record' => $record->getKey()])
            ->fillForm(['name' => 'بعد'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['beforeValidate', 'afterValidate', 'beforeSave', 'record-updated', 'afterSave'],
            HookSpyEditPartnerType::$calls,
        );

        $this->assertSame(1, AuditEvent::where('event_action', 'updated')->count());
    }

    // -----------------------------------------------------------------
    // Validation still runs before anything is written or audited
    // -----------------------------------------------------------------

    public function test_failed_validation_writes_neither_a_record_nor_an_event(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreatePartnerType::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name'])
            ->assertNoRedirect();

        $this->assertSame(0, PartnerType::withTrashed()->count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_failed_validation_on_edit_leaves_the_record_and_the_trail_untouched(): void
    {
        $this->actingAsSuperAdmin();
        $record = PartnerType::create(['name' => 'سليم']);

        Livewire::test(EditPartnerType::class, ['record' => $record->getKey()])
            ->fillForm(['name' => ''])
            ->call('save')
            ->assertHasFormErrors(['name']);

        $this->assertSame('سليم', $record->fresh()->name);
        $this->assertSame(0, AuditEvent::count());
    }

    // -----------------------------------------------------------------
    // Bulk failure reporting + deselection
    // -----------------------------------------------------------------

    /**
     * With audit storage unavailable every record's REQUIRED audit insert
     * fails, so every deletion rolls back. Filament must still report the
     * failure through its own per-record reporting path rather than
     * silently claiming success.
     */
    public function test_a_bulk_delete_whose_audit_fails_deletes_nothing_and_reports_failure(): void
    {
        $this->actingAsSuperAdmin();

        $records = collect(['أ', 'ب'])->map(fn (string $name) => PartnerType::create(['name' => $name]));

        Schema::drop('audit_events');

        Livewire::test(ListPartnerTypes::class)
            ->callTableBulkAction('delete', $records)
            ->assertNotified();

        $this->assertSame(2, PartnerType::count(), 'Every deletion must have rolled back.');
    }

    /**
     * DeleteBulkAction::setUp() calls deselectRecordsAfterCompletion(), and
     * AuditedActions::deleteBulk() must not lose it. Deselection is a
     * client-side dispatch (HasBulkActions::deselectAllTableRecords()), so
     * the emitted browser event — not a server-side property — is the
     * behavior to assert.
     */
    public function test_a_successful_bulk_delete_still_deselects_the_records(): void
    {
        $this->actingAsSuperAdmin();

        $records = collect(['أ', 'ب'])->map(fn (string $name) => PartnerType::create(['name' => $name]));

        Livewire::test(ListPartnerTypes::class)
            ->callTableBulkAction('delete', $records)
            ->assertDispatched('deselectAllTableRecords');

        $this->assertSame(0, PartnerType::count());
        $this->assertSame(2, AuditEvent::count());
    }

    // -----------------------------------------------------------------
    // Relationship saving
    // -----------------------------------------------------------------

    /**
     * Filament runs saveRelationships() after the record handler. Every
     * relationship on this phase's forms is a BelongsTo `Select` — written
     * as a foreign-key column on the record itself, not a deferred
     * relationship write — so the audited snapshot taken inside the
     * transaction is already complete, and nothing is left for
     * saveRelationships() to persist afterwards.
     */
    public function test_belongs_to_selects_are_captured_by_the_audited_write_itself(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project('مشروع الربط');
        $accountType = $this->accountType();
        $currency = $this->currency();

        Livewire::test(\App\Filament\Resources\ProjectCosts\Pages\CreateProjectCost::class)
            ->fillForm([
                'project_id' => $project->id,
                'account_type_id' => $accountType->id,
                'currency_id' => $currency->id,
                'amount' => 100,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cost = ProjectCost::firstOrFail();
        $event = AuditEvent::sole();

        $this->assertSame($project->id, $event->new_values['project_id']);
        $this->assertSame($accountType->id, $event->new_values['account_type_id']);
        $this->assertSame($currency->id, $event->new_values['currency_id']);

        $this->assertSame($project->id, $cost->project_id);
        $this->assertSame($accountType->id, $cost->account_type_id);
        $this->assertInstanceOf(Project::class, $cost->project);
    }
}

/**
 * Records the order of Filament's own create hooks around the audited
 * handler. Deliberately a real subclass of the production page so the
 * inherited AuditsRecordCreation trait is the one under test.
 */
class HookSpyCreatePartnerType extends CreatePartnerType
{
    /** @var array<int, string> */
    public static array $calls = [];

    public function mount(): void
    {
        static::$calls = [];

        parent::mount();
    }

    protected function beforeValidate(): void
    {
        static::$calls[] = 'beforeValidate';
    }

    protected function afterValidate(): void
    {
        static::$calls[] = 'afterValidate';
    }

    protected function beforeCreate(): void
    {
        static::$calls[] = 'beforeCreate';
    }

    protected function afterCreate(): void
    {
        static::$calls[] = 'afterCreate';
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $record = parent::handleRecordCreation($data);

        static::$calls[] = 'record-created';

        return $record;
    }
}

class HookSpyEditPartnerType extends EditPartnerType
{
    /** @var array<int, string> */
    public static array $calls = [];

    public function mount(int | string $record): void
    {
        static::$calls = [];

        parent::mount($record);
    }

    protected function beforeValidate(): void
    {
        static::$calls[] = 'beforeValidate';
    }

    protected function afterValidate(): void
    {
        static::$calls[] = 'afterValidate';
    }

    protected function beforeSave(): void
    {
        static::$calls[] = 'beforeSave';
    }

    protected function afterSave(): void
    {
        static::$calls[] = 'afterSave';
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $updated = parent::handleRecordUpdate($record, $data);

        static::$calls[] = 'record-updated';

        return $updated;
    }
}
