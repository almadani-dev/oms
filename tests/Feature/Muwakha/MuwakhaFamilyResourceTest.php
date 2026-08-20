<?php

namespace Tests\Feature\Muwakha;

use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Filament\Resources\MuwakhaFamilies\Pages\ListMuwakhaFamilies;
use App\Models\AuditEvent;
use App\Models\MuwakhaFamily;
use App\Models\User;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Muwakha\MuwakhaFamilyProjectService;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §19 "UI / permissions" (63-70) and "Export" (71-78).
 */
class MuwakhaFamilyResourceTest extends MuwakhaTestCase
{
    private MuwakhaFamilyService $families;

    private MuwakhaFamilyProjectService $links;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSyncService::class)->sync();

        $this->families = app(MuwakhaFamilyService::class);
        $this->links = app(MuwakhaFamilyProjectService::class);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        $role = Role::create(['name' => 'muwakha-test-'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    // ------------------------------------------------------------ permissions

    public function test_the_five_crud_permissions_and_export_are_registered(): void
    {
        $names = PermissionRegistry::names();

        foreach (['view_any', 'view', 'create', 'update', 'delete', 'export'] as $operation) {
            $this->assertContains("muwakha_families.{$operation}", $names);
        }
    }

    /** No import, restore or force-delete permission may exist. */
    public function test_no_restore_import_or_force_delete_permission_is_registered(): void
    {
        $names = PermissionRegistry::names();

        $this->assertNotContains('muwakha_families.restore', $names);
        $this->assertNotContains('muwakha_families.import', $names);
        $this->assertNotContains('muwakha_families.force_delete', $names);
    }

    public function test_role_defaults_grant_muwakha_families_as_approved(): void
    {
        $full = ['view_any', 'view', 'create', 'update', 'delete', 'export'];

        $admin = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::ADMIN);
        $projectManager = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::PROJECT_MANAGER);
        $viewer = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::VIEWER);
        $accountant = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::ACCOUNTANT);

        foreach ($full as $operation) {
            $this->assertContains("muwakha_families.{$operation}", $admin);
            $this->assertContains("muwakha_families.{$operation}", $projectManager);
        }

        // Viewer: read-only, and explicitly WITHOUT export — a Viewer may read
        // the register on screen but not extract it to a file.
        $this->assertContains('muwakha_families.view_any', $viewer);
        $this->assertContains('muwakha_families.view', $viewer);
        $this->assertNotContains('muwakha_families.export', $viewer);
        $this->assertNotContains('muwakha_families.create', $viewer);

        // Accountant: nothing, for now.
        foreach ($full as $operation) {
            $this->assertNotContains("muwakha_families.{$operation}", $accountant);
        }
    }

    /** #63 */
    public function test_an_authorized_user_sees_the_resource(): void
    {
        $this->actingAs($this->userWith('muwakha_families.view_any', 'muwakha_families.view'));

        $this->assertTrue(MuwakhaFamilyResource::canViewAny());
    }

    /** #64 */
    public function test_an_unauthorized_user_cannot_reach_list_or_create(): void
    {
        $this->actingAs($this->userWith('projects.view_any'));

        $this->assertFalse(MuwakhaFamilyResource::canViewAny());
        $this->assertFalse(MuwakhaFamilyResource::canCreate());

        $this->get(MuwakhaFamilyResource::getUrl('index'))->assertForbidden();
        $this->get(MuwakhaFamilyResource::getUrl('create'))->assertForbidden();
    }

    public function test_an_unauthorized_user_cannot_reach_view_or_edit(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $family = $this->families->create($this->familyData());

        $this->actingAs($this->userWith('projects.view_any'));

        $this->get(MuwakhaFamilyResource::getUrl('view', ['record' => $family]))->assertForbidden();
        $this->get(MuwakhaFamilyResource::getUrl('edit', ['record' => $family]))->assertForbidden();
    }

    public function test_an_authorized_user_can_load_the_list_page(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $this->families->create($this->familyData());

        $this->actingAs($this->userWith('muwakha_families.view_any', 'muwakha_families.view'));

        $this->get(MuwakhaFamilyResource::getUrl('index'))->assertSuccessful();
    }

    /** #61 + #62 — no Restore / Force Delete anywhere in the resource. */
    public function test_the_resource_exposes_no_restore_or_force_delete(): void
    {
        $pages = array_keys(MuwakhaFamilyResource::getPages());

        $this->assertSame(['index', 'create', 'view', 'account-statement', 'edit'], $pages);

        $source = file_get_contents(app_path('Filament/Resources/MuwakhaFamilies/Tables/MuwakhaFamiliesTable.php'))
            .file_get_contents(app_path('Filament/Resources/MuwakhaFamilies/Pages/EditMuwakhaFamily.php'))
            .file_get_contents(app_path('Filament/Resources/MuwakhaFamilies/Pages/ListMuwakhaFamilies.php'));

        $this->assertStringNotContainsString(RestoreAction::class, $source);
        $this->assertStringNotContainsString(ForceDeleteAction::class, $source);
        $this->assertStringNotContainsString('RestoreAction', $source);
        $this->assertStringNotContainsString('ForceDeleteAction', $source);
        $this->assertStringNotContainsString('TrashedFilter', $source);
    }

    // ------------------------------------------------------------------ table

    /** #65 + #66 */
    public function test_default_and_toggleable_columns_are_correct(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $this->families->create($this->familyData());

        $columns = Livewire::test(ListMuwakhaFamilies::class)
            ->instance()
            ->getTable()
            ->getColumns();

        // Default columns: present and NOT hidden behind the toggle.
        foreach ([
            'martyr_name', 'martyr_national_id', 'guardian_name', 'guardian_phone',
            'children_count', 'account_holder_name', 'account.account_code',
            'account.bankType.name', 'familyProjects',
        ] as $name) {
            $this->assertArrayHasKey($name, $columns, "Default column [{$name}] is missing.");
            $this->assertFalse(
                $columns[$name]->isToggledHiddenByDefault(),
                "Column [{$name}] must be visible by default.",
            );
        }

        // Optional columns: present, toggleable, and hidden by default — the
        // same optional-column pattern AccountsTable/ExecutionPaymentsTable use.
        foreach ([
            'martyr_date_of_birth', 'martyr_age_at_martyrdom', 'martyrdom_date',
            'guardian_national_id', 'guardian_date_of_birth',
            'account.currency.name', 'account.iban',
            'notes', 'created_at', 'updated_at',
        ] as $name) {
            $this->assertArrayHasKey($name, $columns, "Optional column [{$name}] is missing.");
            $this->assertTrue(
                $columns[$name]->isToggleable(),
                "Column [{$name}] must be toggleable.",
            );
            $this->assertTrue(
                $columns[$name]->isToggledHiddenByDefault(),
                "Column [{$name}] must be hidden by default.",
            );
        }
    }

    /** #69 + #70 — searching by a SHARED account number matches both families. */
    public function test_search_matches_family_fields_and_a_duplicated_account_number(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        // Phone numbers are deliberately chosen NOT to contain the searched
        // account number: guardian_phone is itself a searchable column, so a
        // shared substring would make this assert nothing about account_code.
        $first = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد', 'martyr_national_id' => '1111111111',
            'account_code' => '123456', 'guardian_phone' => '0570000001',
        ]));
        $second = $this->families->create($this->familyData([
            'martyr_name' => 'محمد', 'martyr_national_id' => '2222222222',
            'account_code' => '123456', 'guardian_phone' => '0570000002',
        ]));
        $third = $this->families->create($this->familyData([
            'martyr_name' => 'خالد', 'martyr_national_id' => '3333333333',
            'account_code' => '999999', 'guardian_phone' => '0570000003',
        ]));

        Livewire::test(ListMuwakhaFamilies::class)
            ->searchTable('123456')
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$third]);

        Livewire::test(ListMuwakhaFamilies::class)
            ->searchTable('2222222222')
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first, $third]);
    }

    /** #67 */
    public function test_the_project_filter_works(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        $linked = $this->families->create($this->familyData(['martyr_national_id' => '1111111111']));
        $unlinked = $this->families->create($this->familyData([
            'martyr_national_id' => '2222222222', 'martyr_name' => 'محمد',
        ]));

        $project = $this->makeMuwakhaProject();
        $this->links->link($linked, ['project_id' => $project->id, 'card_code' => 'G 10']);

        Livewire::test(ListMuwakhaFamilies::class)
            ->filterTable('muwakha_project', $project->id)
            ->assertCanSeeTableRecords([$linked])
            ->assertCanNotSeeTableRecords([$unlinked]);
    }

    /** #68 */
    public function test_the_bank_type_filter_works(): void
    {
        $reference = $this->seedMuwakhaReference();
        $otherBank = $this->seedBankType('بنك القدس');
        $this->actingAs($this->superAdmin());

        $onDefault = $this->families->create($this->familyData(['martyr_national_id' => '1111111111']));
        $onOther = $this->families->create($this->familyData([
            'martyr_national_id' => '2222222222',
            'martyr_name' => 'محمد',
            'bank_type_id' => $otherBank->id,
        ]));

        Livewire::test(ListMuwakhaFamilies::class)
            ->filterTable('bank_type', $otherBank->id)
            ->assertCanSeeTableRecords([$onOther])
            ->assertCanNotSeeTableRecords([$onDefault]);
    }

    // ----------------------------------------------------------------- export

    /** #71 + #72 */
    public function test_excel_export_streams_a_right_to_left_workbook(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $this->families->create($this->familyData());

        // Same pattern as ReportExportAuditTest: call the export method on the
        // component instance, which returns the StreamedResponse directly.
        $response = Livewire::test(ListMuwakhaFamilies::class)->instance()->exportExcel();

        $this->assertInstanceOf(StreamedResponse::class, $response);

        $contents = $this->capture($response);

        $this->assertNotSame('', $contents);
        $this->assertStringStartsWith('PK', $contents, 'An .xlsx file is a ZIP container.');

        // The workbook itself is asserted RTL at the object level, which is
        // what setRightToLeft() actually controls.
        $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($this->tempFile($contents, 'xlsx'));
        $this->assertTrue($spreadsheet->getActiveSheet()->getRightToLeft());
    }

    /** #73 + #74 */
    public function test_word_export_streams_a_right_to_left_document(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $this->families->create($this->familyData());

        $response = Livewire::test(ListMuwakhaFamilies::class)->instance()->exportWord();

        $this->assertInstanceOf(StreamedResponse::class, $response);

        $contents = $this->capture($response);

        $this->assertStringStartsWith('PK', $contents, 'A .docx file is a ZIP container.');

        $zip = new \ZipArchive;
        $zip->open($this->tempFile($contents, 'docx'));
        $document = $zip->getFromName('word/document.xml');
        $zip->close();

        // PhpWord emits <w:bidi/> on RTL paragraphs; Settings::setDefaultRtl
        // is what puts it there.
        $this->assertStringContainsString('<w:bidi', $document);
        $this->assertStringContainsString('أسر المؤاخاة', $document);
    }

    /** #75 — the export follows the table's current filters/search. */
    public function test_export_respects_the_current_search(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        // Distinct guardian/holder names too: both are searchable columns, so
        // reusing the fixture defaults would let the searched term match the
        // other family through them and prove nothing about the filtering.
        $this->families->create($this->familyData([
            'martyr_name' => 'أحمد', 'martyr_national_id' => '1111111111',
            'guardian_name' => 'سعاد', 'account_holder_name' => 'سعاد سالم',
        ]));
        $this->families->create($this->familyData([
            'martyr_name' => 'محمد', 'martyr_national_id' => '2222222222',
            'guardian_name' => 'هدى', 'account_holder_name' => 'هدى سالم',
        ]));

        $page = Livewire::test(ListMuwakhaFamilies::class)->searchTable('محمد');

        $rows = $this->exportRowsOf($page->instance());

        $this->assertCount(1, $rows);
        $this->assertSame('محمد', $rows[0]->martyrName);
    }

    /** #76 — multi-project + per-project card codes survive into the export. */
    public function test_export_carries_each_project_with_its_own_card_code(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        $family = $this->families->create($this->familyData());
        $first = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $second = $this->makeMuwakhaProject('مؤاخاة البركة 2026');

        $this->links->link($family, ['project_id' => $first->id, 'card_code' => 'G 10']);
        $this->links->link($family, ['project_id' => $second->id]);

        $rows = $this->exportRowsOf(Livewire::test(ListMuwakhaFamilies::class)->instance());

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['مؤاخاة كاف 2026 — G 10', 'مؤاخاة البركة 2026'],
            $rows[0]->projects,
        );
    }

    /** #77 */
    public function test_export_is_forbidden_without_the_export_permission(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $this->families->create($this->familyData());

        // view_any + view but NO export.
        $this->actingAs($this->userWith('muwakha_families.view_any', 'muwakha_families.view'));

        $this->assertFalse(Livewire::test(ListMuwakhaFamilies::class)->instance()->canExport());

        // A fresh component per call: an aborted (403) request leaves no valid
        // Livewire snapshot to reuse for a second call.
        Livewire::test(ListMuwakhaFamilies::class)->call('exportExcel')->assertForbidden();
        Livewire::test(ListMuwakhaFamilies::class)->call('exportWord')->assertForbidden();
    }

    /** #78 */
    public function test_export_writes_one_report_export_audit_event(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $this->families->create($this->familyData());

        AuditEvent::query()->delete();

        Livewire::test(ListMuwakhaFamilies::class)->call('exportExcel');

        $event = AuditEvent::where('event_category', ReportExportAuditRecorder::EVENT_CATEGORY)->sole();

        $this->assertSame(ReportExportAuditRecorder::EVENT_ACTION, $event->event_action);
        $this->assertSame(ReportExportSubject::MuwakhaFamilies->value, $event->subject_type);
        $this->assertSame('xlsx', $event->new_values['format']);
        $this->assertSame(1, $event->new_values['row_count']);
        // Filters only — never a family's data.
        $this->assertArrayNotHasKey('rows', $event->new_values);
        $this->assertArrayNotHasKey('martyr_name', $event->new_values);
    }

    public function test_a_forbidden_export_writes_no_audit_event(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
        $this->families->create($this->familyData());

        $this->actingAs($this->userWith('muwakha_families.view_any', 'muwakha_families.view'));

        AuditEvent::query()->delete();

        Livewire::test(ListMuwakhaFamilies::class)->call('exportExcel')->assertForbidden();

        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }

    // ------------------------------------------------------------------ pages

    public function test_the_create_page_creates_a_family_and_its_account(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        Livewire::test(\App\Filament\Resources\MuwakhaFamilies\Pages\CreateMuwakhaFamily::class)
            ->fillForm($this->familyData())
            ->call('create')
            ->assertHasNoFormErrors();

        $family = MuwakhaFamily::sole();

        $this->assertSame('أسرة الشهيد أحمد محمد - شيكل - (123456)', $this->accountOf($family)->name);
    }

    public function test_the_edit_page_hydrates_payment_fields_and_the_account_currency(): void
    {
        $reference = $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        $family = $this->families->create($this->familyData([
            'account_code' => '778899',
            'iban' => 'PS12BANK0000000000001',
        ]));

        Livewire::test(\App\Filament\Resources\MuwakhaFamilies\Pages\EditMuwakhaFamily::class, [
            'record' => $family->getKey(),
        ])->assertFormSet([
            'account_code' => '778899',
            'iban' => 'PS12BANK0000000000001',
            // The Select must show the currency of the CURRENTLY linked Account.
            'currency_id' => $reference['currency']->id,
        ]);
    }

    /**
     * `exportRows()` is protected on the page — deliberately, since a public
     * method on a Livewire component is remotely invokable. Reached here by
     * reflection, the same way ExecutionPaymentCreditAccountTest exercises the
     * protected page handlers.
     *
     * @return array<int, \App\Services\Muwakha\MuwakhaFamilyExportRow>
     */
    private function exportRowsOf(ListMuwakhaFamilies $page): array
    {
        $method = new \ReflectionMethod($page, 'exportRows');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    private function capture(mixed $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function tempFile(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'muwakha').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }
}
