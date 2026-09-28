<?php

namespace Tests\Feature\Projects;

use App\Filament\Resources\ProjectCosts\Pages\CreateProjectCost;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Both project pickers render `code ?: name`, so the code a user can see must
 * find the option — through Filament's own server-side relationship search
 * (bounded by optionsLimit), never by shipping every row to the browser.
 *
 *  - ProjectForm      → project_super_id (المشروع الرئيسي)
 *  - ProjectCostForm  → project_id       (المشروع)
 */
class ProjectSelectCodeSearchTest extends TestCase
{
    use IntegrityTestFixtures;

    private ProjectStatus $status;

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
    }

    // ---- ProjectForm: project_super_id ----------------------------------

    public function test_project_super_select_finds_an_option_by_its_displayed_code(): void
    {
        $target = $this->makeSuper('مشاريع الإغاثة', 'GAZ');
        $this->makeSuper('مشاريع التعليم', 'EDU');

        $results = $this->superSelect()->getSearchResults($target->code);

        $this->assertSame([$target->id => $target->code], $results);
    }

    public function test_project_super_select_still_finds_an_option_by_name(): void
    {
        $target = $this->makeSuper('مشاريع الإغاثة', 'GAZ');
        $this->makeSuper('مشاريع التعليم', 'EDU');

        $this->assertSame([$target->id => $target->code], $this->superSelect()->getSearchResults('الإغاثة'));
    }

    public function test_project_super_select_label_is_unchanged(): void
    {
        $withCode = $this->makeSuper('مشاريع الإغاثة', 'GAZ');
        $withoutCode = $this->makeSuper('بدون رمز', 'NOC');
        $withoutCode->forceFill(['code' => null])->saveQuietly();

        $select = $this->superSelect();

        $this->assertSame($withCode->code, $select->getSearchResults($withCode->code)[$withCode->id]);
        $this->assertSame('بدون رمز', $select->getSearchResults('بدون رمز')[$withoutCode->id]);
    }

    public function test_project_super_search_is_one_bounded_server_side_query_over_code_and_name(): void
    {
        $this->makeSuper('مشاريع الإغاثة', 'GAZ');
        $select = $this->superSelect();

        $sql = $this->capturedSql(fn () => $select->getSearchResults('GAZ'));

        $this->assertCount(1, $sql);
        $this->assertMatchesRegularExpression('/"code"\s+like\s+\?\s+or\s+"name"\s+like\s+\?/i', $sql[0]);
        $this->assertStringContainsString('"deleted_at" is null', $sql[0]);
        $this->assertStringContainsString('limit 50', $sql[0]);
    }

    public function test_project_super_preload_stays_bounded_by_the_options_limit(): void
    {
        foreach (range(1, 55) as $i) {
            $this->makeSuper("رئيسي {$i}", 'R'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $select = $this->superSelect();

        $this->assertTrue($select->isPreloaded());
        $this->assertCount(50, $select->getOptions());
    }

    public function test_soft_deleted_project_super_is_not_offered(): void
    {
        $trashed = $this->makeSuper('مشاريع محذوفة', 'DEL');
        $trashed->delete();

        $this->assertSame([], $this->superSelect()->getSearchResults($trashed->code));
    }

    // ---- ProjectCostForm: project_id --------------------------------------

    public function test_project_cost_select_finds_a_project_by_its_displayed_code(): void
    {
        $super = $this->makeSuper('مشاريع الإغاثة', 'GAZ');
        $target = $this->makeProject('مشروع المياه', $super);
        $this->makeProject('مشروع الغذاء', $super);

        $this->assertNotEmpty($target->code);
        $this->assertSame([$target->id => $target->code], $this->projectCostSelect()->getSearchResults($target->code));
    }

    public function test_project_cost_select_still_finds_a_project_by_name(): void
    {
        $super = $this->makeSuper('مشاريع الإغاثة', 'GAZ');
        $target = $this->makeProject('مشروع المياه', $super);
        $this->makeProject('مشروع الغذاء', $super);

        $this->assertSame([$target->id => $target->code], $this->projectCostSelect()->getSearchResults('المياه'));
    }

    public function test_project_cost_search_is_server_side_and_never_preloaded(): void
    {
        $this->makeProject('مشروع المياه', $this->makeSuper('مشاريع الإغاثة', 'GAZ'));
        $select = $this->projectCostSelect();

        $this->assertFalse($select->isPreloaded());

        $sql = $this->capturedSql(fn () => $select->getSearchResults('المياه'));

        $this->assertCount(1, $sql);
        $this->assertMatchesRegularExpression('/"code"\s+like\s+\?\s+or\s+"name"\s+like\s+\?/i', $sql[0]);
        $this->assertStringContainsString('"deleted_at" is null', $sql[0]);
        $this->assertStringContainsString('limit 50', $sql[0]);
    }

    public function test_soft_deleted_project_is_not_offered_for_a_cost(): void
    {
        $trashed = $this->makeProject('مشروع محذوف', $this->makeSuper('مشاريع الإغاثة', 'GAZ'));
        $trashed->delete();

        $this->assertSame([], $this->projectCostSelect()->getSearchResults($trashed->code));
    }

    // ---------------------------------------------------------------------

    private function superSelect(): Select
    {
        return $this->selectOn(CreateProject::class, 'project_super_id');
    }

    private function projectCostSelect(): Select
    {
        return $this->selectOn(CreateProjectCost::class, 'project_id');
    }

    private function selectOn(string $page, string $name): Select
    {
        $select = collect(Livewire::test($page)->instance()->getSchema('form')->getFlatComponents())
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === $name);

        $this->assertInstanceOf(Select::class, $select, "{$name} Select not found on {$page}");

        return $select;
    }

    /**
     * @return list<string>
     */
    private function capturedSql(callable $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $callback();

        return $sql;
    }

    private function makeSuper(string $name, string $prefix): ProjectSuper
    {
        return ProjectSuper::create(['name' => $name, 'code_prefix' => $prefix])->refresh();
    }

    private function makeProject(string $name, ProjectSuper $super): Project
    {
        return Project::create([
            'name' => $name,
            'project_super_id' => $super->id,
            'project_status_id' => $this->status->id,
        ])->refresh();
    }
}
