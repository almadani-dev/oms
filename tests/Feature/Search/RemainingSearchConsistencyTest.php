<?php

namespace Tests\Feature\Search;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Filament\Resources\Attachments\AttachmentResource;
use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\ExchangeRateHistories\ExchangeRateHistoryResource;
use App\Filament\Resources\Partners\PartnerResource;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\ProjectSupers\ProjectSuperResource;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Settings\SettingResource;
use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Search Batch F: the المعاملات المالية list, the remaining admin lists
 * (attachments, audit log, users, roles, permissions) and every topbar
 * global-search resource not already handled by Batches B and D.
 *
 * Probe terms are chosen so each can only reach the field under test; hamza
 * spellings are written as code points where stored and typed differ.
 */
class RemainingSearchConsistencyTest extends TestCase
{
    use IntegrityTestFixtures;

    private FiscalYear $fiscalYear;

    private TransactionType $grant;

    private TransactionType $salary;

    private Partner $hope;

    private Partner $light;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(PermissionSyncService::class)->sync();

        $this->actingAs($this->superAdmin());

        $this->fiscalYear = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $this->grant = TransactionType::create(['name' => "منحة \u{0625}غاثية"]); // منحة إغاثية
        $this->salary = TransactionType::create(['name' => 'رواتب']);

        $type = PartnerType::create(['name' => 'جمعية']);
        $this->hope = Partner::create(['name' => "مؤسسة ال\u{0623}مل", 'partner_type_id' => $type->id]);
        $this->light = Partner::create(['name' => 'جمعية النور', 'partner_type_id' => $type->id]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    /**
     * @param  list<Model>  $expected
     * @param  list<Model>  $notExpected
     */
    private function assertSearch(string $page, string $term, array $expected, array $notExpected): void
    {
        Livewire::test($page)
            ->searchTable($term)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($notExpected);
    }

    /**
     * @param  class-string  $resource
     * @return list<string>
     */
    private function globalTitles(string $resource, string $search): array
    {
        return $resource::getGlobalSearchResults($search)
            ->map(fn (GlobalSearchResult $result): string => (string) $result->title)
            ->values()
            ->all();
    }

    // =====================================================================
    // المعاملات المالية LIST
    // =====================================================================

    /**
     * @return array{Transaction, Transaction}
     */
    private function transactions(): array
    {
        $target = Transaction::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'transaction_type_id' => $this->grant->id,
            'partner_id' => $this->hope->id,
            'transaction_number' => 'GEN-2026-0001',
            'reference' => 'REF-ALPHA',
            'description' => "صرف مساعدة \u{0623}سر الشهداء",   // أسر
            'notes' => 'دفعة شهر رمضان',
            'transaction_time' => now(),
        ]);
        $other = Transaction::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'transaction_type_id' => $this->salary->id,
            'partner_id' => $this->light->id,
            'transaction_number' => 'PAY_2026_0002',
            'reference' => 'REF-BETA',
            'description' => 'رواتب الموظفين',
            'notes' => null,
            'transaction_time' => now(),
        ]);

        return [$target, $other];
    }

    public function test_transactions_search_number_and_reference_as_identifiers(): void
    {
        [$target, $other] = $this->transactions();

        $this->assertSearch(ListTransactions::class, 'GEN-2026-0001', [$target], [$other]);
        $this->assertSearch(ListTransactions::class, 'REF-ALPHA', [$target], [$other]);
    }

    public function test_transactions_search_description_and_notes_as_arabic_text(): void
    {
        [$target, $other] = $this->transactions();

        $this->assertSearch(ListTransactions::class, 'اسر', [$target], [$other]);       // stored أسر
        $this->assertSearch(ListTransactions::class, 'رمضان', [$target], [$other]);    // notes
    }

    public function test_transactions_search_partner_and_type_names(): void
    {
        [$target, $other] = $this->transactions();

        $this->assertSearch(ListTransactions::class, 'الامل', [$target], [$other]);      // partner, stored الأمل
        $this->assertSearch(ListTransactions::class, 'اغاثية', [$target], [$other]);     // type, stored إغاثية
    }

    public function test_transactions_search_treats_percent_and_underscore_literally(): void
    {
        [$target, $other] = $this->transactions();

        $this->assertSearch(ListTransactions::class, '%', [], [$target, $other]);
        // Only the other number contains a literal underscore; as a wildcard
        // 'GEN_2026' would also match 'GEN-2026-0001'.
        $this->assertSearch(ListTransactions::class, 'PAY_2026', [$other], [$target]);
        $this->assertSearch(ListTransactions::class, 'GEN_2026', [], [$target, $other]);
    }

    public function test_transactions_search_reaches_beyond_the_first_page_and_skips_deleted_rows(): void
    {
        [$target, $other] = $this->transactions();

        $records = Livewire::test(ListTransactions::class)
            ->set('tableRecordsPerPage', 1)
            ->searchTable('2026')
            ->instance()
            ->getTableRecords();
        $this->assertCount(1, $records->items());
        $this->assertSame(2, $records->total());

        $target->delete();
        $this->assertSearch(ListTransactions::class, 'GEN-2026-0001', [], [$target, $other]);
    }

    public function test_transactions_search_sql_shape_and_no_per_row_queries(): void
    {
        $this->transactions();

        $query = Livewire::test(ListTransactions::class)->searchTable('الامل')->instance()->getFilteredSortedTableQuery();
        $sql = strtolower($query->toSql());
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertSame(2, substr_count($sql, 'exists ('));
        $this->assertContains('%الامل%', $query->getBindings());
        $this->assertMatchesRegularExpression('/order by "(transactions"\.")?id" desc$/', $sql);

        $count = function (string $term): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListTransactions::class)->searchTable($term)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // Searching a term that matches both rows costs no more than no search.
        $this->assertLessThanOrEqual($count('') + 2, $count('2026'));
    }

    // =====================================================================
    // ATTACHMENTS LIST
    // =====================================================================

    private function attachment(string $fileName, string $projectName, string $number): Attachment
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'دولار', 'symbol' => '$']);
        $project = Project::create([
            'name' => $projectName,
            'project_super_id' => ProjectSuper::create(['name' => 'رئيسي '.uniqid(), 'code_prefix' => 'P'.substr(uniqid(), -4)])->id,
            'project_status_id' => ProjectStatus::firstOrCreate(['name' => 'نشط'])->id,
        ]);
        $receipt = ProjectCostReceipt::create([
            'project_cost_id' => ProjectCost::create(['project_id' => $project->id, 'amount' => 100, 'currency_id' => $currency->id])->id,
            'amount' => 10, 'currency_id' => $currency->id, 'date' => '2026-07-01',
            'transaction_id' => Transaction::create([
                'fiscal_year_id' => $this->fiscalYear->id, 'transaction_type_id' => $this->salary->id,
                'transaction_number' => $number, 'transaction_time' => now(),
            ])->id,
        ]);

        return Attachment::create([
            'attachable_type' => ProjectCostReceipt::class, 'attachable_id' => $receipt->id,
            'file_name' => $fileName, 'file_path' => 'receipts/'.uniqid().'.pdf',
            'file_type' => 'application/pdf', 'file_size' => 100, 'disk' => Attachment::DISK_ATTACHMENTS,
        ]);
    }

    public function test_attachments_search_project_as_arabic_text_and_names_numbers_literally(): void
    {
        $target = $this->attachment('scan_2026.pdf', "مشروع \u{0625}غاثة", 'RCP-7001');
        $other = $this->attachment('scan-2026.pdf', 'مشروع التعليم', 'RCP_7002');

        $this->assertSearch(ListAttachments::class, 'اغاثة', [$target], [$other]);          // project, stored إغاثة
        $this->assertSearch(ListAttachments::class, 'scan_2026', [$target], [$other]);      // literal _
        $this->assertSearch(ListAttachments::class, 'RCP_7002', [$other], [$target]);       // operation number, literal _
        $this->assertSearch(ListAttachments::class, '%', [], [$target, $other]);
    }

    // =====================================================================
    // AUDIT LOG / USERS / ROLES / PERMISSIONS LISTS
    // =====================================================================

    private function auditEvent(string $actor, string $subjectKey, string $email): AuditEvent
    {
        return AuditEvent::create([
            'event_category' => 'crud', 'event_action' => 'created',
            'actor_type' => AuditActorType::User, 'status' => AuditStatus::Success,
            'actor_name' => $actor, 'actor_email' => $email,
            'subject_type' => 'partner', 'subject_key' => $subjectKey, 'subject_label' => 'سجل '.$subjectKey,
        ]);
    }

    public function test_audit_log_search_actor_as_arabic_text_and_keys_literally(): void
    {
        $target = $this->auditEvent("\u{0623}حمد المدقق", 'KEY_1', 'ahmad@example.org');
        $other = $this->auditEvent('سامي', 'KEY-1', 'sami@example.org');

        $this->assertSearch(ListAuditEvents::class, 'احمد', [$target], [$other]);
        $this->assertSearch(ListAuditEvents::class, 'KEY_1', [$target], [$other]);
        $this->assertSearch(ListAuditEvents::class, 'sami@', [$other], [$target]);
    }

    public function test_users_search_name_email_and_role(): void
    {
        $target = User::factory()->create(['name' => "\u{0625}براهيم خالد", 'email' => 'ibrahim@example.org']);
        $target->assignRole(Role::create(['name' => "مدير ال\u{0623}رشيف", 'guard_name' => 'web']));
        $other = User::factory()->create(['name' => 'سامي', 'email' => 'sami_x@example.org']);

        $this->assertSearch(ListUsers::class, 'ابراهيم', [$target], [$other]);
        $this->assertSearch(ListUsers::class, 'ibrahim@', [$target], [$other]);
        $this->assertSearch(ListUsers::class, 'الارشيف', [$target], [$other]);           // role, stored الأرشيف
        $this->assertSearch(ListUsers::class, 'sami_x', [$other], [$target]);
    }

    public function test_roles_search_arabic_role_names(): void
    {
        $archive = Role::create(['name' => "مدير ال\u{0623}رشيف", 'guard_name' => 'web']);

        $this->assertSearch(ListRoles::class, 'الارشيف', [$archive], []);
    }

    public function test_permissions_search_the_arabic_label_across_alef_variants(): void
    {
        // PermissionRegistry labels create permissions 'إضافة …'.
        $create = Permission::where('name', 'accounts.create')->firstOrFail();
        $viewAny = Permission::where('name', 'accounts.view_any')->firstOrFail();

        $this->assertSearch(ListPermissions::class, 'اضافة الحسابات', [$create], [$viewAny]);
    }

    // =====================================================================
    // TOPBAR GLOBAL SEARCH
    // =====================================================================

    public function test_disabled_global_search_resources_contribute_nothing(): void
    {
        foreach ([ExchangeRateHistoryResource::class, AuditEventResource::class, PermissionResource::class, SettingResource::class] as $resource) {
            $this->assertFalse($resource::canGloballySearch(), $resource);
        }
    }

    public function test_transactions_global_search_by_number_and_reference_only(): void
    {
        [$target] = $this->transactions();

        $this->assertSame(['GEN-2026-0001'], $this->globalTitles(TransactionResource::class, 'GEN-2026-0001'));
        $this->assertSame(['GEN-2026-0001'], $this->globalTitles(TransactionResource::class, 'REF-ALPHA'));
        $this->assertSame([], $this->globalTitles(TransactionResource::class, 'رمضان')); // notes are not a topbar key

        $target->delete();
        $this->assertSame([], $this->globalTitles(TransactionResource::class, 'GEN-2026-0001'));
    }

    public function test_users_global_search_by_arabic_name_and_email(): void
    {
        $user = User::factory()->create(['name' => "\u{0625}براهيم خالد", 'email' => 'ibrahim@example.org']);

        $this->assertSame([$user->name], $this->globalTitles(UserResource::class, 'ابراهيم'));
        $this->assertSame([$user->name], $this->globalTitles(UserResource::class, 'ibrahim@'));
    }

    public function test_name_keyed_global_search_folds_alef_and_codes_stay_identifiers(): void
    {
        $this->assertSame([$this->hope->name], $this->globalTitles(PartnerResource::class, 'الامل'));
        $this->assertSame([$this->grant->name], $this->globalTitles(TransactionTypeResource::class, 'اغاثية'));

        $archive = Role::create(['name' => "مدير ال\u{0623}رشيف", 'guard_name' => 'web']);
        $this->assertSame([$archive->name], $this->globalTitles(RoleResource::class, 'الارشيف'));

        $currency = Currency::create(['name' => 'دولار امريكي', 'code' => 'USD', 'symbol' => '$']);
        $this->assertSame([$currency->name], $this->globalTitles(CurrencyResource::class, "\u{0623}مريكي"));
        $this->assertSame([$currency->name], $this->globalTitles(CurrencyResource::class, 'USD'));

        $super = ProjectSuper::create(['name' => "مشاريع ال\u{0625}يواء", 'code_prefix' => 'SHL'])->refresh();
        $this->assertSame([$super->name], $this->globalTitles(ProjectSuperResource::class, $super->code));
        $this->assertSame([$super->name], $this->globalTitles(ProjectSuperResource::class, 'الايواء'));
    }

    public function test_attachment_global_search_keeps_its_scope_and_reads_names_literally(): void
    {
        $target = $this->attachment('scan_2026.pdf', 'مشروع', 'RCP-8001');
        $this->attachment('scan-2026.pdf', 'مشروع', 'RCP-8002');

        $this->assertSame([$target->file_name], $this->globalTitles(AttachmentResource::class, 'scan_2026'));

        // Batch 0 scope still applies: no parent-module permission, no rows.
        $this->actingAs($this->userWith('attachments.view_any', 'attachments.view'));
        $this->assertSame([], $this->globalTitles(AttachmentResource::class, 'scan'));
    }

    public function test_global_search_still_respects_authorization(): void
    {
        $this->transactions();
        User::factory()->create(['name' => "\u{0625}براهيم خالد"]);

        $this->actingAs($this->userWith());
        $this->assertFalse(TransactionResource::canGloballySearch());
        $this->assertFalse(UserResource::canGloballySearch());
        $this->assertFalse(PartnerResource::canGloballySearch());

        // List permission without record view permission: no result links.
        $this->actingAs($this->userWith('transactions.view_any'));
        $this->assertTrue(TransactionResource::canGloballySearch());
        $this->assertSame([], $this->globalTitles(TransactionResource::class, 'GEN-2026-0001'));
    }
}
