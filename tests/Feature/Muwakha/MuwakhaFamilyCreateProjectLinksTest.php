<?php

namespace Tests\Feature\Muwakha;

use App\Filament\Resources\MuwakhaFamilies\Pages\CreateMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\RelationManagers\ProjectsRelationManager;
use App\Models\Account;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Models\MuwakhaFamilyProject;
use App\Models\User;
use App\Services\Muwakha\MuwakhaFamilyProjectService;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * `ربط بمشاريع المؤاخاة` on the Family CREATE form.
 *
 * The section only collects rows; every link rule still belongs to
 * MuwakhaFamilyProjectService, which MuwakhaFamilyService replays inside its
 * own transaction. Two consequences are what these tests pin:
 *
 *  1. A rejected row unwinds the ENTIRE creation — Account, family, ownership
 *     mapping and every earlier link — so there is never a half-linked family.
 *  2. A duplicate project or card code WITHIN one submission is caught by the
 *     same server-side rules as a duplicate added later, because each row is
 *     checked against the rows already inserted.
 */
class MuwakhaFamilyCreateProjectLinksTest extends MuwakhaTestCase
{
    private MuwakhaFamilyService $families;

    private MuwakhaFamilyProjectService $links;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSyncService::class)->sync();

        $this->actingAs(User::factory()->create());

        $this->families = app(MuwakhaFamilyService::class);
        $this->links = app(MuwakhaFamilyProjectService::class);
    }

    /** #6 — zero links stays valid. */
    public function test_a_family_can_still_be_created_with_no_projects(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());

        $this->assertSame(1, MuwakhaFamily::count());
        $this->assertSame(0, MuwakhaFamilyProject::count());
        $this->assertSame(1, Account::count());
        $this->assertSame(1, MuwakhaFamilyAccount::count());

        // An explicitly empty section is the same thing.
        $this->families->create($this->familyData([
            'martyr_national_id' => '9999999999',
            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [],
        ]));

        $this->assertSame(0, MuwakhaFamilyProject::count());
    }

    /** #7 + #8 + #11 */
    public function test_a_family_can_be_created_with_one_project_and_a_card_code(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $family = $this->families->create($this->familyData([
            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                ['project_id' => $project->id, 'card_code' => 'G 10'],
            ],
        ]));

        $link = MuwakhaFamilyProject::sole();

        $this->assertSame($family->id, $link->muwakha_family_id);
        $this->assertSame($project->id, $link->project_id);
        $this->assertSame('G 10', $link->card_code);
    }

    /** #9 — a blank card code normalizes to NULL through the existing rule. */
    public function test_a_blank_card_code_is_normalized_to_null(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $this->families->create($this->familyData([
            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                ['project_id' => $project->id, 'card_code' => '   '],
            ],
        ]));

        $this->assertNull(MuwakhaFamilyProject::sole()->card_code);
    }

    /** #10 + #11 — several projects in ONE submission, each with its own code. */
    public function test_several_projects_can_be_linked_in_one_submission(): void
    {
        $this->seedMuwakhaReference();
        $first = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $second = $this->makeMuwakhaProject('مؤاخاة البركة 2026');

        $family = $this->families->create($this->familyData([
            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                ['project_id' => $first->id, 'card_code' => 'G 10'],
                ['project_id' => $second->id, 'card_code' => 'B 24'],
            ],
        ]));

        $links = MuwakhaFamilyProject::where('muwakha_family_id', $family->id)
            ->orderBy('id')->get();

        $this->assertCount(2, $links);
        $this->assertSame([$first->id, $second->id], $links->pluck('project_id')->all());
        $this->assertSame(['G 10', 'B 24'], $links->pluck('card_code')->all());
    }

    /** #12 — the same project twice in one submission is rejected. */
    public function test_the_same_project_cannot_be_selected_twice(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        try {
            $this->families->create($this->familyData([
                MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                    ['project_id' => $project->id, 'card_code' => 'G 10'],
                    ['project_id' => $project->id, 'card_code' => 'G 11'],
                ],
            ]));
            $this->fail('One project may not be linked twice in a single submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project_id', $exception->errors());
        }

        $this->assertNothingWasCreated();
    }

    /** #13 — a project outside the Muwakha root is rejected server-side. */
    public function test_an_unrelated_project_is_rejected(): void
    {
        $this->seedMuwakhaReference();
        $unrelated = $this->makeUnrelatedProject();

        try {
            $this->families->create($this->familyData([
                MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                    ['project_id' => $unrelated->id],
                ],
            ]));
            $this->fail('An unrelated project must not be linkable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project_id', $exception->errors());
        }

        $this->assertNothingWasCreated();
    }

    /** #14 — the root is a ProjectSuper, so it can never be a link target. */
    public function test_the_muwakha_root_cannot_be_linked(): void
    {
        $this->seedMuwakhaReference();

        try {
            $this->families->create($this->familyData([
                MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                    ['project_id' => $this->muwakhaRoot()->id],
                ],
            ]));
            $this->fail('The Muwakha root must not be linkable as a project.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project_id', $exception->errors());
        }

        $this->assertNothingWasCreated();
    }

    /** A forged row carrying no project at all is rejected, not skipped. */
    public function test_a_row_without_a_project_is_rejected(): void
    {
        $this->seedMuwakhaReference();

        try {
            $this->families->create($this->familyData([
                MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                    ['project_id' => null, 'card_code' => 'G 10'],
                ],
            ]));
            $this->fail('A row with no project must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project_id', $exception->errors());
        }

        $this->assertNothingWasCreated();
    }

    /** #15 — the existing card-code uniqueness rule still applies. */
    public function test_a_card_code_already_used_in_that_project_is_rejected(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $existing = $this->families->create($this->familyData(['martyr_national_id' => '1111111111']));
        $this->links->link($existing, ['project_id' => $project->id, 'card_code' => 'G 10']);

        try {
            $this->families->create($this->familyData([
                'martyr_national_id' => '2222222222',
                'martyr_name' => 'محمد',
                MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                    ['project_id' => $project->id, 'card_code' => 'G 10'],
                ],
            ]));
            $this->fail('A card code already used in that project must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('card_code', $exception->errors());
        }

        // Only the FIRST family and its records survive.
        $this->assertSame(1, MuwakhaFamily::count());
        $this->assertSame(1, Account::count());
        $this->assertSame(1, MuwakhaFamilyAccount::count());
        $this->assertSame(1, MuwakhaFamilyProject::count());
    }

    /** #16 + #17 + #18 + #19 — one bad row unwinds everything. */
    public function test_a_failing_project_link_rolls_back_the_entire_creation(): void
    {
        $this->seedMuwakhaReference();
        $good = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $unrelated = $this->makeUnrelatedProject();

        try {
            $this->families->create($this->familyData([
                MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                    // Accepted first, so it is already inserted when the next
                    // row fails — it must not survive either.
                    ['project_id' => $good->id, 'card_code' => 'G 10'],
                    ['project_id' => $unrelated->id, 'card_code' => 'X 1'],
                ],
            ]));
            $this->fail('An unrelated project must abort the whole creation.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, MuwakhaFamily::count());             // #16
        $this->assertSame(0, Account::count());                   // #17
        $this->assertSame(0, MuwakhaFamilyAccount::count());      // #18
        $this->assertSame(0, MuwakhaFamilyProject::count());      // #19 — not even the accepted first row
        $this->assertSame(0, MuwakhaFamilyProject::where('project_id', $good->id)->count());
    }

    /** #20 — post-create relation management is untouched. */
    public function test_project_links_can_still_be_managed_after_creation(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        $first = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $second = $this->makeMuwakhaProject('مؤاخاة البركة 2026');

        $family = $this->families->create($this->familyData([
            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                ['project_id' => $first->id, 'card_code' => 'G 10'],
            ],
        ]));

        // The relation manager still renders for the family and lists the link
        // that the create form made.
        $manager = Livewire::test(ProjectsRelationManager::class, [
            'ownerRecord' => $family->fresh(),
            'pageClass' => \App\Filament\Resources\MuwakhaFamilies\Pages\ViewMuwakhaFamily::class,
        ]);

        $manager->assertSuccessful()->assertSee('مؤاخاة كاف 2026');

        // Its add / edit / remove actions are still wired up...
        $table = $manager->instance()->getTable();

        $this->assertSame(
            ['create', 'edit', 'delete'],
            array_keys($table->getFlatActions()),
        );

        // ...and each one delegates to this service, which still works after a
        // family was created together with its initial links.
        $added = $this->links->link($family->fresh(), ['project_id' => $second->id, 'card_code' => 'B 24']);
        $this->assertSame(2, MuwakhaFamilyProject::where('muwakha_family_id', $family->id)->count());

        $this->links->update($added, ['project_id' => $second->id, 'card_code' => 'B 25']);
        $this->assertSame('B 25', $added->fresh()->card_code);

        $this->links->unlink($added);
        $this->assertSame(1, MuwakhaFamilyProject::where('muwakha_family_id', $family->id)->count());
    }

    /** The whole flow through the real Create page, in one save. */
    public function test_the_create_page_saves_the_family_and_its_links_in_one_submission(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        $first = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $second = $this->makeMuwakhaProject('مؤاخاة البركة 2026');

        Livewire::test(CreateMuwakhaFamily::class)
            ->fillForm($this->familyData([
                MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                    ['project_id' => $first->id, 'card_code' => 'G 10'],
                    ['project_id' => $second->id, 'card_code' => 'B 24'],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $family = MuwakhaFamily::sole();

        $this->assertSame(1, Account::count());
        $this->assertSame(1, MuwakhaFamilyAccount::count());
        $this->assertSame(2, MuwakhaFamilyProject::where('muwakha_family_id', $family->id)->count());
        $this->assertSame(
            ['G 10', 'B 24'],
            MuwakhaFamilyProject::orderBy('id')->pluck('card_code')->all(),
        );
    }

    /** The section is create-only; an edit submission carrying rows adds none. */
    public function test_an_edit_never_adds_project_links(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $family = $this->families->create($this->familyData());

        $this->families->update($family->fresh(), $this->familyData([
            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                ['project_id' => $project->id, 'card_code' => 'G 10'],
            ],
        ]));

        $this->assertSame(0, MuwakhaFamilyProject::count());
    }

    /** #21 — the approved permission matrix is unchanged. */
    public function test_the_permission_matrix_is_unchanged(): void
    {
        $full = ['view_any', 'view', 'create', 'update', 'delete', 'export'];

        $admin = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::ADMIN);
        $projectManager = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::PROJECT_MANAGER);
        $viewer = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::VIEWER);
        $accountant = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::ACCOUNTANT);

        foreach ($full as $operation) {
            $this->assertContains("muwakha_families.{$operation}", $admin);
            $this->assertContains("muwakha_families.{$operation}", $projectManager);
            $this->assertNotContains("muwakha_families.{$operation}", $accountant);
        }

        $this->assertContains('muwakha_families.view_any', $viewer);
        $this->assertContains('muwakha_families.view', $viewer);
        $this->assertNotContains('muwakha_families.export', $viewer);
        $this->assertNotContains('muwakha_families.create', $viewer);
    }

    // ------------------------------------------------------------------ helpers

    private function assertNothingWasCreated(): void
    {
        $this->assertSame(0, MuwakhaFamily::count());
        $this->assertSame(0, Account::count());
        $this->assertSame(0, MuwakhaFamilyAccount::count());
        $this->assertSame(0, MuwakhaFamilyProject::count());
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
