<?php

namespace Tests\Feature\Crud;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Attachments\AttachmentResource;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\TransactionLines\TransactionLineResource;
use App\Filament\Resources\Transactions\TransactionResource;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Structural, DB-free proof of the OMS-wide CRUD redirect standard so a future
 * page can never silently revert to the old Filament defaults (Edit stays on
 * the edit page; a panel-level createPageRedirect override could move Create):
 *
 *  - every current full-page Create/Edit page uses the single shared
 *    RedirectsToResourceView concern (Create/Edit -> record View page);
 *  - every current full-page Edit page still inherits Filament's stock
 *    page-level delete redirect (DeleteAction/ForceDeleteAction -> resource
 *    Index) unmodified;
 *  - no redirect destination is a hard-coded "/admin/..." string;
 *  - the four read-only audit resources register no Create/Edit pages at all
 *    and therefore never adopt the concern.
 *
 * The functional (genuine Livewire) proof lives in CrudRedirectStandardTest.
 */
class CrudRedirectStandardStructureTest extends TestCase
{
    /**
     * Every current full-page CRUD resource: directory => model singular.
     * The 22 resources with index/create/view/edit routes. The four read-only
     * resources (Attachments, Permissions, TransactionLines, Transactions) are
     * deliberately absent — they are asserted separately below.
     *
     * @var array<string, string>
     */
    private const FULL_PAGE_RESOURCES = [
        'AccountTypes' => 'AccountType',
        'Accounts' => 'Account',
        'BankTypes' => 'BankType',
        'Currencies' => 'Currency',
        'ExchangeRateHistories' => 'ExchangeRateHistory',
        'ExecutionPayments' => 'ExecutionPayment',
        'FiscalYears' => 'FiscalYear',
        'GeneralExchanges' => 'GeneralExchange',
        'GeneralExpenses' => 'GeneralExpense',
        'PartnerTypes' => 'PartnerType',
        'Partners' => 'Partner',
        'ProjectCostBudgetsPayments' => 'ProjectCostBudgetsPayment',
        'ProjectCostReceipts' => 'ProjectCostReceipt',
        'ProjectCosts' => 'ProjectCost',
        'ProjectStatuses' => 'ProjectStatus',
        'ProjectSupers' => 'ProjectSuper',
        'Projects' => 'Project',
        'Roles' => 'Role',
        'Settings' => 'Setting',
        'TransactionSuperTypes' => 'TransactionSuperType',
        'TransactionTypes' => 'TransactionType',
        'Users' => 'User',
    ];

    /**
     * @return array<string, array{0: string, 1: string}> name => [createFqcn, editFqcn]
     */
    public static function fullPageResourceProvider(): array
    {
        $cases = [];

        foreach (self::FULL_PAGE_RESOURCES as $dir => $singular) {
            $base = "App\\Filament\\Resources\\{$dir}\\Pages\\";
            $cases[$dir] = [$base."Create{$singular}", $base."Edit{$singular}"];
        }

        return $cases;
    }

    #[DataProvider('fullPageResourceProvider')]
    public function test_create_page_uses_the_shared_redirect_concern(string $createClass, string $editClass): void
    {
        $this->assertTrue(class_exists($createClass), "{$createClass} is missing.");
        $this->assertContains(
            RedirectsToResourceView::class,
            class_uses_recursive($createClass),
            "{$createClass} must use RedirectsToResourceView so Create redirects to the record View page.",
        );
    }

    #[DataProvider('fullPageResourceProvider')]
    public function test_edit_page_uses_the_shared_redirect_concern(string $createClass, string $editClass): void
    {
        $this->assertTrue(class_exists($editClass), "{$editClass} is missing.");
        $this->assertContains(
            RedirectsToResourceView::class,
            class_uses_recursive($editClass),
            "{$editClass} must use RedirectsToResourceView so Edit redirects to the record View page.",
        );
    }

    /**
     * Filament's page-level delete redirect (DeleteAction / ForceDeleteAction
     * -> resource Index) is provided by InteractsWithRecord (inherited through
     * EditRecord) and already matches the required standard. Assert no Edit
     * page has overridden getDefaultActionSuccessRedirectUrl() — the method
     * must still be declared upstream in Filament, not on the concrete page —
     * so every current page-level delete keeps redirecting to the Index.
     */
    #[DataProvider('fullPageResourceProvider')]
    public function test_edit_page_keeps_stock_delete_index_redirect(string $createClass, string $editClass): void
    {
        $declaring = (new ReflectionMethod($editClass, 'getDefaultActionSuccessRedirectUrl'))
            ->getDeclaringClass()
            ->getName();

        $this->assertNotSame(
            $editClass,
            $declaring,
            "{$editClass} overrides getDefaultActionSuccessRedirectUrl() — re-verify the page-level DeleteAction still redirects to the resource Index.",
        );
        $this->assertStringStartsWith(
            'Filament\\',
            $declaring,
            "{$editClass}: page-level delete redirect must stay Filament's stock InteractsWithRecord behavior (resource Index).",
        );
    }

    /**
     * The redirect must always resolve through the Resource (getUrl), never a
     * literal "/admin/..." path — proven by scanning the concern and every
     * Create/Edit page source.
     */
    public function test_no_create_or_edit_redirect_uses_a_hardcoded_admin_url(): void
    {
        $files = array_merge(
            [app_path('Filament/Concerns/RedirectsToResourceView.php')],
            glob(app_path('Filament/Resources/*/Pages/Create*.php')),
            glob(app_path('Filament/Resources/*/Pages/Edit*.php')),
        );

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                '/admin/',
                (string) file_get_contents($file),
                basename($file).' contains a hard-coded /admin/ URL — redirects must resolve through the Resource.',
            );
        }
    }

    /**
     * The read-only audit resources expose no Create/Edit routes, so full-page
     * mutation (and therefore the redirect concern) never applies to them —
     * even for Super Admin. This guards requirement 11/12: read-only resources
     * stay read-only and untouched by this task.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function readOnlyResourceProvider(): array
    {
        return [
            'attachments' => [AttachmentResource::class],
            'permissions' => [PermissionResource::class],
            'transaction_lines' => [TransactionLineResource::class],
            'transactions' => [TransactionResource::class],
        ];
    }

    #[DataProvider('readOnlyResourceProvider')]
    public function test_read_only_resource_registers_no_create_or_edit_page(string $resourceClass): void
    {
        $pages = array_keys($resourceClass::getPages());

        $this->assertNotContains('create', $pages, "{$resourceClass} unexpectedly registers a create page.");
        $this->assertNotContains('edit', $pages, "{$resourceClass} unexpectedly registers an edit page.");
        $this->assertFalse($resourceClass::hasPage('create'), "{$resourceClass}::hasPage('create') must be false.");
        $this->assertFalse($resourceClass::hasPage('edit'), "{$resourceClass}::hasPage('edit') must be false.");
    }

    #[DataProvider('readOnlyResourceProvider')]
    public function test_read_only_resource_pages_do_not_use_the_redirect_concern(string $resourceClass): void
    {
        foreach ($resourceClass::getPages() as $registration) {
            $pageClass = $registration->getPage();

            $this->assertNotContains(
                RedirectsToResourceView::class,
                class_uses_recursive($pageClass),
                "{$pageClass} (read-only resource) must not adopt the Create/Edit redirect concern.",
            );
        }
    }
}
