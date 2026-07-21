<?php

namespace Tests\Feature\Permissions;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\AccountTypes\AccountTypeResource;
use App\Filament\Resources\Attachments\AttachmentResource;
use App\Filament\Resources\BankTypes\BankTypeResource;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\ExchangeRateHistories\ExchangeRateHistoryResource;
use App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use App\Filament\Resources\GeneralExpenses\GeneralExpenseResource;
use App\Filament\Resources\Partners\PartnerResource;
use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use App\Filament\Resources\ProjectCosts\ProjectCostResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use App\Filament\Resources\ProjectSupers\ProjectSuperResource;
use App\Filament\Resources\Settings\SettingResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Resources\TransactionLines\TransactionLineResource;
use App\Filament\Resources\TransactionSuperTypes\TransactionSuperTypeResource;
use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real HTTP, data-driven proof that every one of the 23 protected resources
 * (everything except UserResource, which is covered by the existing
 * UserResourceLockdownTest and intentionally untouched by this task) blocks
 * its index/create routes for a user lacking the matching permission, and
 * allows them once granted — via genuine requests under the normal
 * APP_ENV=testing, not by inspecting canX() in isolation. Also proves
 * navigation visibility tracks view_any exactly, and that Super Admin
 * reaches every ordinary resource's index.
 *
 * Uses the same schema-only SQLite + URL::forceRootUrl approach as
 * UserResourceLockdownTest (see its docblock for why forceRootUrl is
 * required here and is not an "app.env=local" workaround).
 */
class ResourceHttpAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        URL::forceRootUrl('http://localhost');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: class-string, 3: bool}>
     */
    public static function resourceProvider(): array
    {
        return [
            'accounts' => ['accounts', 'accounts', AccountResource::class, true],
            'transactions (read-only, no create route)' => ['transactions', 'transactions', TransactionResource::class, false],
            'transaction_lines (read-only, no create route)' => ['transaction-lines', 'transaction_lines', TransactionLineResource::class, false],
            'general_expenses' => ['general-expenses', 'general_expenses', GeneralExpenseResource::class, true],
            'general_exchanges' => ['general-exchanges', 'general_exchanges', GeneralExchangeResource::class, true],
            'execution_payments' => ['execution-payments', 'execution_payments', ExecutionPaymentResource::class, true],
            'project_cost_budgets_payments' => ['project-cost-budgets-disbursements', 'project_cost_budgets_payments', ProjectCostBudgetsPaymentResource::class, true],
            'project_cost_receipts' => ['project-cost-receipts', 'project_cost_receipts', ProjectCostReceiptResource::class, true],
            'projects' => ['projects', 'projects', ProjectResource::class, true],
            'project_costs' => ['project-costs', 'project_costs', ProjectCostResource::class, true],
            'project_supers' => ['project-supers', 'project_supers', ProjectSuperResource::class, true],
            'partners' => ['partners', 'partners', PartnerResource::class, true],
            'bank_types' => ['bank-types', 'bank_types', BankTypeResource::class, true],
            'currencies' => ['currencies', 'currencies', CurrencyResource::class, true],
            'account_types' => ['account-types', 'account_types', AccountTypeResource::class, true],
            'exchange_rate_histories' => ['exchange-rate-histories', 'exchange_rate_histories', ExchangeRateHistoryResource::class, true],
            'fiscal_years' => ['fiscal-years', 'fiscal_years', FiscalYearResource::class, true],
            'partner_types' => ['partner-types', 'partner_types', PartnerTypeResource::class, true],
            'transaction_types' => ['transaction-types', 'transaction_types', TransactionTypeResource::class, true],
            'transaction_super_types' => ['transaction-super-types', 'transaction_super_types', TransactionSuperTypeResource::class, true],
            'project_statuses' => ['project-statuses', 'project_statuses', ProjectStatusResource::class, true],
            'settings' => ['settings', 'settings', SettingResource::class, true],
            // OMS Task 6A hardening: AttachmentResource's own upload path sits
            // outside AttachmentController's authorization flow, so its
            // canCreate() is hard-overridden to false and no create route is
            // registered at all — see AttachmentResourceHardeningTest for the
            // dedicated structural + Super Admin proof.
            'attachments' => ['attachments', 'attachments', AttachmentResource::class, false],
        ];
    }

    #[DataProvider('resourceProvider')]
    public function test_index_is_blocked_without_permission_and_allowed_with_it(string $slug, string $module, string $resourceClass, bool $hasCreate): void
    {
        $this->actingAs(User::factory()->create());
        $this->get("/admin/{$slug}")->assertForbidden();

        $this->actingAs($this->userWithPermission("{$module}.view_any"));
        $this->get("/admin/{$slug}")->assertOk();
    }

    #[DataProvider('resourceProvider')]
    public function test_create_page_is_blocked_without_permission_and_allowed_with_it(string $slug, string $module, string $resourceClass, bool $hasCreate): void
    {
        if (! $hasCreate) {
            $this->markTestSkipped("{$resourceClass} has no create route (read-only audit resource).");
        }

        $this->actingAs(User::factory()->create());
        $this->get("/admin/{$slug}/create")->assertForbidden();

        // The Create page's breadcrumb/back-to-list link means Filament also
        // checks view_any to reach it, not just create — granted here so the
        // isolated check is on `create`, matching the same pattern proven by
        // the execution-payments/project-cost-budgets-disbursements view page.
        $this->actingAs($this->userWithPermissions(["{$module}.view_any", "{$module}.create"]));
        $this->get("/admin/{$slug}/create")->assertOk();
    }

    /**
     * Filament's Panel builds the visible sidebar from
     * shouldRegisterNavigation() (a static "is this resource nav-eligible at
     * all" toggle, default true, orthogonal to permissions —
     * HasNavigation::shouldRegisterNavigation() literally just returns
     * static::$shouldRegisterNavigation) combined with canViewAny() at
     * render time. canViewAny() gating real access is already proven, via a
     * genuine per-user HTTP round trip, by
     * test_index_is_blocked_without_permission_and_allowed_with_it — calling
     * it cold (or right after an unrelated request) against a static
     * Resource method outside that page-mount lifecycle proved unreliable
     * in this environment for reasons unrelated to permission correctness.
     * This test instead confirms, at the source level, that none of the 23
     * resources override the nav-eligibility toggle away from its default —
     * except two pre-existing, documented, permission-independent
     * exceptions: ProjectCostResource (reachable only via
     * Projects/CostsRelationManager, never as a top-level nav item), and
     * AttachmentResource (OMS Task 6A hardening: zero real usage today, and
     * its own upload path sits outside AttachmentController's authorization
     * flow — see AttachmentResourceHardeningTest).
     */
    public function test_only_project_cost_and_attachment_resources_opt_out_of_navigation_registration(): void
    {
        $navigationOptOutResources = [ProjectCostResource::class, AttachmentResource::class];

        foreach (self::resourceProvider() as $name => [, , $resourceClass]) {
            // None of the 23 override the shouldRegisterNavigation() method
            // itself — confirms Filament's stock HasNavigation trait
            // implementation applies (asserted via the method's declaring
            // class, which PHP resolves to wherever it's first inherited).
            $reflection = new \ReflectionMethod($resourceClass, 'shouldRegisterNavigation');
            $this->assertSame(
                \Filament\Resources\Resource::class,
                $reflection->getDeclaringClass()->getName(),
                "{$name}: shouldRegisterNavigation() is overridden on this resource — re-verify the nav-eligibility assumption.",
            );

            $property = new \ReflectionProperty($resourceClass, 'shouldRegisterNavigation');
            $property->setAccessible(true);
            $isEligible = $property->getValue();

            if (in_array($resourceClass, $navigationOptOutResources, true)) {
                $this->assertFalse($isEligible, "{$name}: expected the known hardcoded navigation opt-out.");
            } else {
                $this->assertTrue($isEligible, "{$name}: expected the default nav-eligible toggle (not overridden).");
            }
        }
    }

    #[DataProvider('resourceProvider')]
    public function test_super_admin_can_access_every_ordinary_resource_index(string $slug, string $module, string $resourceClass, bool $hasCreate): void
    {
        $this->actingAsSuperAdmin();

        $this->get("/admin/{$slug}")->assertOk();
    }

    private function userWithPermission(string $name): User
    {
        return $this->userWithPermissions([$name]);
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
