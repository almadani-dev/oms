<?php

namespace Tests\Feature\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\User;
use App\Support\Audit\AuditLabels;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * «رقم المشروع لدى الجهة المانحة» (projects.donor_project_number): the donor's
 * own reference for a project — optional free text, kept exactly as typed —
 * beside, never instead of, the donor-side project NAME (donor_project_name)
 * and the internal generated `code`.
 */
class DonorProjectNumberTest extends TestCase
{
    use IntegrityTestFixtures;

    private const MIGRATION = 'database/migrations/2026_10_18_100000_add_donor_project_number_to_projects_table.php';

    private ProjectStatus $status;

    private ProjectSuper $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($user);

        $this->status = ProjectStatus::create(['name' => 'نشط']);
        $this->super = ProjectSuper::create(['name' => 'مشاريع الإغاثة', 'code_prefix' => 'RLF']);
    }

    private function project(string $name, ?string $number = null, ?string $donorName = null): Project
    {
        return Project::create([
            'name' => $name,
            'project_super_id' => $this->super->id,
            'project_status_id' => $this->status->id,
            'donor_project_name' => $donorName,
            'donor_project_number' => $number,
        ])->refresh();
    }

    // ---- schema -----------------------------------------------------------

    public function test_the_migration_adds_a_nullable_text_column_beside_the_donor_project_name(): void
    {
        $columns = collect(Schema::getColumns('projects'))->keyBy('name');

        $this->assertTrue($columns->has('donor_project_number'));
        $this->assertTrue($columns['donor_project_number']['nullable']);
        $this->assertStringContainsString('varchar', strtolower($columns['donor_project_number']['type_name'].$columns['donor_project_number']['type']));
        $this->assertTrue($columns->has('donor_project_name'), 'the donor-side name is kept');
        $this->assertTrue($columns->has('code'), 'the internal code is kept');

        // An existing project stays valid with no number.
        $this->assertNull($this->project('مشروع قديم', donorName: 'اسم لدى المانح')->donor_project_number);
    }

    public function test_the_migration_is_reversible_and_never_touches_the_donor_project_name(): void
    {
        $project = $this->project('مشروع', '00123/AB-7', 'اسم لدى المانح');
        $code = $project->code;
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('projects', 'donor_project_number'));
        $this->assertSame('اسم لدى المانح', DB::table('projects')->where('id', $project->id)->value('donor_project_name'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('projects', 'donor_project_number'));
        $row = DB::table('projects')->where('id', $project->id)->first();
        $this->assertNull($row->donor_project_number, 'no value is inferred or backfilled');
        $this->assertSame('اسم لدى المانح', $row->donor_project_name);
        $this->assertSame($code, $row->code);
    }

    // ---- Create / Edit ----------------------------------------------------

    public function test_create_saves_the_donor_name_and_number_independently_of_the_internal_code(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'مشروع المياه',
                'project_super_id' => $this->super->id,
                'project_status_id' => $this->status->id,
                'donor_project_name' => 'Water for Gaza',
                'donor_project_number' => '00123/AB-7',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::sole();

        // Kept exactly as typed: leading zeros, letters, "/" and "-".
        $this->assertSame('00123/AB-7', $project->donor_project_number);
        $this->assertSame('Water for Gaza', $project->donor_project_name);
        $this->assertMatchesRegularExpression('/^RLF_\d{8}_\d{3}$/', (string) $project->code, 'the internal code is still generated');
    }

    public function test_the_number_is_optional_and_bounded_to_255_characters(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm(['name' => 'بلا رقم', 'project_super_id' => $this->super->id, 'project_status_id' => $this->status->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Project::sole()->donor_project_number);

        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'رقم طويل', 'project_super_id' => $this->super->id, 'project_status_id' => $this->status->id,
                'donor_project_number' => str_repeat('7', 256),
            ])
            ->call('create')
            ->assertHasFormErrors(['donor_project_number' => 'max']);
    }

    public function test_edit_updates_the_name_and_number_independently_and_audits_the_number(): void
    {
        $project = $this->project('مشروع', '0007', 'اسم قديم');
        $code = $project->code;
        AuditEvent::query()->delete();

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->assertSchemaStateSet(['donor_project_number' => '0007', 'donor_project_name' => 'اسم قديم'])
            ->fillForm(['donor_project_number' => 'AB/0099'])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();
        $this->assertSame('AB/0099', $project->donor_project_number);
        $this->assertSame('اسم قديم', $project->donor_project_name, 'the number never overwrites the name');
        $this->assertSame($code, $project->code);

        $event = AuditEvent::sole();
        $this->assertSame(['donor_project_number'], $event->changed_fields);
        $this->assertSame(['donor_project_number' => '0007'], $event->old_values);
        $this->assertSame(['donor_project_number' => 'AB/0099'], $event->new_values);

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['donor_project_name' => 'اسم جديد'])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();
        $this->assertSame('اسم جديد', $project->donor_project_name);
        $this->assertSame('AB/0099', $project->donor_project_number, 'the name never overwrites the number');

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm(['donor_project_number' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($project->refresh()->donor_project_number, 'the number can be cleared');
        $this->assertSame('اسم جديد', $project->donor_project_name);
    }

    public function test_audit_labels_name_both_donor_fields(): void
    {
        $this->assertSame('اسم المشروع لدى الجهة المانحة', AuditLabels::field('donor_project_name'));
        $this->assertSame('رقم المشروع لدى الجهة المانحة', AuditLabels::field('donor_project_number'));
    }

    // ---- View ---------------------------------------------------------------

    public function test_view_shows_the_donor_name_and_number_as_two_entries_beside_the_code(): void
    {
        $project = $this->project('مشروع', '00123/AB-7', 'اسم لدى المانح');

        $component = Livewire::test(ViewProject::class, ['record' => $project->getKey()])
            ->assertSee('اسم المشروع لدى الجهة المانحة')
            ->assertSee('رقم المشروع لدى الجهة المانحة')
            ->assertSee('اسم لدى المانح')
            ->assertSee('00123/AB-7')
            ->assertSee($project->code);

        $entries = collect($component->instance()->getSchema('infolist')->getFlatComponents())
            ->filter(fn ($c): bool => $c instanceof TextEntry)
            ->keyBy(fn (TextEntry $entry): string => $entry->getName());

        $this->assertSame('اسم لدى المانح', $entries['donor_project_name']->getState());
        $this->assertSame('00123/AB-7', $entries['donor_project_number']->getState());
        $this->assertSame('—', $entries['donor_project_number']->getPlaceholder());
        $this->assertSame($project->code, $entries['code']->getState());
    }

    // ---- Projects table -------------------------------------------------------

    public function test_the_table_shows_the_number_by_default_toggleable_and_sortable_with_a_placeholder(): void
    {
        $numbered = $this->project('مشروع مرقم', '00123/AB-7');
        $empty = $this->project('مشروع بلا رقم');

        $component = Livewire::test(ListProjects::class)
            ->assertTableColumnExists('donor_project_number')
            ->assertTableColumnVisible('donor_project_number')
            ->assertCanRenderTableColumn('donor_project_number')
            ->assertTableColumnStateSet('donor_project_number', '00123/AB-7', $numbered)
            ->assertTableColumnStateNotSet('donor_project_number', '00123/AB-7', $empty);

        $column = $component->instance()->getTable()->getColumn('donor_project_number');
        $this->assertSame('رقم المشروع لدى الجهة المانحة', $column->getLabel());
        $this->assertTrue($column->isToggleable());
        $this->assertFalse($column->isToggledHiddenByDefault(), 'visible by default');
        $this->assertTrue($column->isSortable());
        $this->assertSame('—', $column->getPlaceholder());

        // Placed right after the donor column; the internal code column stays first.
        $names = array_keys($component->instance()->getTable()->getColumns());
        $this->assertSame('code', $names[0]);
        $this->assertSame(array_search('donor.name', $names, true) + 1, array_search('donor_project_number', $names, true));
    }

    public function test_the_table_sorts_by_the_number_as_text(): void
    {
        $b = $this->project('مشروع ب', '0100');
        $a = $this->project('مشروع أ', '0020');
        $c = $this->project('مشروع ج', 'A-5');

        // Text order keeps leading zeros meaningful: "0020" < "0100" < "A-5".
        Livewire::test(ListProjects::class)
            ->sortTable('donor_project_number')
            ->assertCanSeeTableRecords([$a, $b, $c], inOrder: true)
            ->sortTable('donor_project_number', 'desc')
            ->assertCanSeeTableRecords([$c, $b, $a], inOrder: true);
    }

    public function test_the_table_search_finds_the_donor_number_literally_and_keeps_code_name_search(): void
    {
        $numbered = $this->project('مشروع المياه', '00123/AB-7');
        $percent = $this->project('مشروع النسبة', '10%-A');
        $other = $this->project('مشروع التعليم');

        $search = fn (string $term) => Livewire::test(ListProjects::class)->searchTable($term);

        $search('00123/ab')->assertCanSeeTableRecords([$numbered])->assertCanNotSeeTableRecords([$percent, $other]);
        $search('AB-7')->assertCanSeeTableRecords([$numbered])->assertCanNotSeeTableRecords([$percent, $other]);

        // "%" is a literal character, never a wildcard.
        $search('%')->assertCanSeeTableRecords([$percent])->assertCanNotSeeTableRecords([$numbered, $other]);

        // Searching the number never stands in for the internal code, and the
        // code / name searches still work.
        $search($other->code)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$numbered, $percent]);
        $search('التعليم')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$numbered, $percent]);
    }
}
