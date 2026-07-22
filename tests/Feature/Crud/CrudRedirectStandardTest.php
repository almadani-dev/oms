<?php

namespace Tests\Feature\Crud;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Filament\Resources\GeneralExpenses\GeneralExpenseResource;
use App\Filament\Resources\ProjectStatuses\Pages\CreateProjectStatus;
use App\Filament\Resources\ProjectStatuses\Pages\EditProjectStatus;
use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\ProjectStatus;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Genuine Livewire/HTTP proof of the OMS-wide CRUD redirect standard:
 *
 *   Create  -> newly created record's View page
 *   Edit    -> updated record's View page
 *   Delete  -> resource Index page (page-level DeleteAction)
 *
 * Representative functional set required by the task:
 *   - general/master-data   : ProjectStatus (simple form, SoftDeletes)
 *   - permission-sensitive   : Role
 *   - financial              : GeneralExpense (balanced double-entry payload)
 *
 * Also proves: success notifications still fire, failed validation does not
 * redirect, an Index-table row delete stays on the Index, Super Admin gets the
 * same navigation, and a user without View permission is redirected to the
 * Index (not into a 403 View) — authorization is never bypassed.
 *
 * Uses the same schema-only in-memory SQLite + URL::forceRootUrl setup as the
 * existing Resource HTTP/Livewire suites.
 */
class CrudRedirectStandardTest extends TestCase
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

    // ---------------------------------------------------------------------
    // Master-data resource (ProjectStatus) — full Create/Edit/Delete cycle
    // ---------------------------------------------------------------------

    public function test_create_redirects_to_the_created_record_view_page(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProjectStatus::class)
            ->fillForm(['name' => 'Redirect Target Status'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProjectStatusResource::getUrl('view', [
                'record' => ProjectStatus::where('name', 'Redirect Target Status')->firstOrFail(),
            ]));
    }

    public function test_created_record_view_page_displays_the_record(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProjectStatus::class)
            ->fillForm(['name' => 'Visible After Create'])
            ->call('create')
            ->assertHasNoFormErrors();

        $record = ProjectStatus::where('name', 'Visible After Create')->firstOrFail();

        $this->get(ProjectStatusResource::getUrl('view', ['record' => $record]))
            ->assertOk()
            ->assertSee('Visible After Create');
    }

    public function test_edit_redirects_to_the_updated_record_view_page(): void
    {
        $this->actingAsSuperAdmin();
        $record = ProjectStatus::create(['name' => 'Before Edit']);

        Livewire::test(EditProjectStatus::class, ['record' => $record->getKey()])
            ->fillForm(['name' => 'After Edit'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProjectStatusResource::getUrl('view', ['record' => $record]));

        $this->assertSame('After Edit', $record->fresh()->name);
    }

    public function test_edit_view_page_displays_the_updated_data(): void
    {
        $this->actingAsSuperAdmin();
        $record = ProjectStatus::create(['name' => 'Stale Name']);

        Livewire::test(EditProjectStatus::class, ['record' => $record->getKey()])
            ->fillForm(['name' => 'Fresh Updated Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get(ProjectStatusResource::getUrl('view', ['record' => $record]))
            ->assertOk()
            ->assertSee('Fresh Updated Name')
            ->assertDontSee('Stale Name');
    }

    public function test_delete_from_edit_page_redirects_to_the_index(): void
    {
        $this->actingAsSuperAdmin();
        $record = ProjectStatus::create(['name' => 'To Be Deleted']);

        Livewire::test(EditProjectStatus::class, ['record' => $record->getKey()])
            ->callAction('delete')
            ->assertRedirect(ProjectStatusResource::getUrl('index'));

        $this->assertNull(ProjectStatus::find($record->getKey()));
        $this->assertNotNull(ProjectStatus::withTrashed()->find($record->getKey()), 'SoftDeletes must be respected.');
    }

    public function test_create_still_shows_the_success_notification(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProjectStatus::class)
            ->fillForm(['name' => 'Notify On Create'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();
    }

    public function test_failed_validation_does_not_redirect(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProjectStatus::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name'])
            ->assertNoRedirect();

        $this->assertSame(0, ProjectStatus::count());
    }

    // ---------------------------------------------------------------------
    // Index-table row delete stays on the Index (Role has a row DeleteAction)
    // ---------------------------------------------------------------------

    public function test_index_table_row_delete_stays_on_the_index(): void
    {
        $role = Role::create(['name' => 'Deletable Custom Role', 'guard_name' => 'web']);
        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.delete']));

        Livewire::test(ListRoles::class)
            ->callTableAction('delete', $role)
            ->assertNoRedirect();

        $this->assertNull(Role::find($role->getKey()));
    }

    // ---------------------------------------------------------------------
    // Permission-sensitive administrative resource (Role)
    // ---------------------------------------------------------------------

    public function test_role_create_redirects_to_its_view_page(): void
    {
        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.view', 'roles.create']));

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Redirected Role', 'permissions' => []])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(RoleResource::getUrl('view', [
                'record' => Role::where('name', 'Redirected Role')->firstOrFail(),
            ]));
    }

    // ---------------------------------------------------------------------
    // Authorization is never bypassed: a user allowed to Create but NOT to
    // View is sent to the Index, and the View route still 403s for them.
    // ---------------------------------------------------------------------

    public function test_user_without_view_permission_is_redirected_to_index_not_a_forbidden_view(): void
    {
        // create + view_any (to reach the create page) but NOT view.
        $user = $this->userWithPermissions(['project_statuses.view_any', 'project_statuses.create']);
        $this->actingAs($user);

        Livewire::test(CreateProjectStatus::class)
            ->fillForm(['name' => 'No View For Me'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProjectStatusResource::getUrl('index'));

        $record = ProjectStatus::where('name', 'No View For Me')->firstOrFail();

        // The redirect never leaks a record the user cannot View: the View
        // route itself still enforces the policy and returns 403.
        $this->get(ProjectStatusResource::getUrl('view', ['record' => $record]))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Financial resource (GeneralExpense): the balanced double-entry payload
    // is unchanged AND both Create and Edit resolve the redirect to the
    // saved record's View page (asserted via the identical protected
    // getRedirectUrl() Filament itself calls, against a genuinely persisted
    // balanced expense — the form drives complex reactive account selects, so
    // this exercises the redirect against the real saved record directly).
    // ---------------------------------------------------------------------

    public function test_financial_create_persists_balanced_payload_and_resolves_view_redirect(): void
    {
        $this->actingAsSuperAdmin();
        $fx = $this->financialFixture();

        $expense = $this->createExpense($this->expenseData($fx));

        // Balanced double-entry, unchanged: debit +250, credit -250.
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
        $lines = $expense->transaction->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(
            (float) $lines->sum('debit_base'),
            (float) $lines->sum('credit_base'),
            'Transaction lines must remain balanced.',
        );

        $createPage = new CreateGeneralExpense();
        $createPage->record = $expense;

        $this->assertSame(
            GeneralExpenseResource::getUrl('view', ['record' => $expense]),
            $this->invokeGetRedirectUrl($createPage),
        );
    }

    public function test_financial_edit_resolves_view_redirect_for_the_saved_record(): void
    {
        $this->actingAsSuperAdmin();
        $fx = $this->financialFixture();
        $expense = $this->createExpense($this->expenseData($fx));

        $editPage = new EditGeneralExpense();
        $editPage->record = $expense->fresh();

        $this->assertSame(
            GeneralExpenseResource::getUrl('view', ['record' => $expense]),
            $this->invokeGetRedirectUrl($editPage),
        );
    }

    // ---------------------------------------------------------------------
    // Super Admin gets the same redirect navigation (Create -> View) without
    // any special-casing, and never gains a full-page mutation route on a
    // read-only resource (that guarantee is covered structurally in
    // CrudRedirectStandardStructureTest).
    // ---------------------------------------------------------------------

    public function test_super_admin_receives_the_same_create_to_view_redirect(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProjectStatus::class)
            ->fillForm(['name' => 'Super Admin Status'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ProjectStatusResource::getUrl('view', [
                'record' => ProjectStatus::where('name', 'Super Admin Status')->firstOrFail(),
            ]));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function invokeGetRedirectUrl(object $page): string
    {
        $method = new ReflectionMethod($page, 'getRedirectUrl');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    /**
     * @return array<string, mixed>
     */
    private function financialFixture(): array
    {
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);

        $debitAccount = Account::create([
            'account_code' => 'مدين', 'name' => 'مدين', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 0, 'is_active' => true,
        ]);
        $creditAccount = Account::create([
            'account_code' => 'دائن', 'name' => 'دائن', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 5000, 'is_active' => true,
        ]);

        $fiscalYear = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType = PartnerType::create(['name' => 'نوع شريك']);
        $partner = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        return compact('currency', 'accountType', 'bankType', 'debitAccount', 'creditAccount', 'fiscalYear', 'transactionType', 'partner');
    }

    /**
     * @param  array<string, mixed>  $fx
     * @return array<string, mixed>
     */
    private function expenseData(array $fx): array
    {
        $debit = $fx['debitAccount'];
        $credit = $fx['creditAccount'];

        return [
            'amount' => 250,
            'currency_id' => $fx['currency']->id,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-18',
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'description' => null,
            'notes' => null,
            'debit_account_id' => $debit->id,
            'debit_account_type_id' => $debit->account_type_id,
            'debit_bank_type_id' => $debit->bank_type_id,
            'credit_account_id' => $credit->id,
            'credit_account_type_id' => $credit->account_type_id,
            'credit_bank_type_id' => $credit->bank_type_id,
            'expense_image' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createExpense(array $data): GeneralExpense
    {
        $page = new CreateGeneralExpense();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
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
