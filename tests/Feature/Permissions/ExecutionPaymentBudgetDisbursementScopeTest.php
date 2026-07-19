<?php

namespace Tests\Feature\Permissions;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Focused HTTP proof for the pair the whole task centers on:
 * ExecutionPaymentResource (model ProjectCostBudgetsPayment, permission
 * prefix `execution_payments`) vs ProjectCostBudgetsPaymentResource (model
 * ProjectCostBudget, permission prefix `project_cost_budgets_payments`).
 * These do NOT share a table — a real ProjectCostBudget disbursement row and
 * a real ProjectCostBudgetsPayment execution-payment row end up with the
 * SAME numeric id (1) here, since each table's auto-increment is
 * independent; that coincidence is exploited deliberately to prove neither
 * resource ever resolves or exposes the other's row.
 *
 * Fixture builders mirror ExecutionPaymentCreditAccountTest's
 * baseFixture()/makeBudget() helpers (same minimal shape: a disbursement
 * transaction with a single LINE_DESTINATION line, all
 * ExecutionPaymentForm::budgetDestinationLine() needs).
 */
class ExecutionPaymentBudgetDisbursementScopeTest extends TestCase
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

    // ---- fixtures (same shape as ExecutionPaymentCreditAccountTest) ----

    private function baseFixture(): array
    {
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);

        $creditAccount = Account::create([
            'account_code' => 'دائن', 'name' => 'دائن', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 5000, 'is_active' => true,
        ]);
        $beneficiary = Account::create([
            'account_code' => 'مستفيد', 'name' => 'مستفيد', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 0, 'is_active' => true,
        ]);

        $fiscalYear = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType = PartnerType::create(['name' => 'نوع شريك']);
        $partner = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        $super = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status = ProjectStatus::create(['name' => 'نشط']);
        $project = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        $budgetTransaction = Transaction::create([
            'fiscal_year_id' => $fiscalYear->id,
            'transaction_type_id' => $transactionType->id,
            'transaction_number' => 'BUD-'.uniqid(),
            'transaction_time' => now(),
        ]);

        TransactionLine::create([
            'transaction_id' => $budgetTransaction->id,
            'account_id' => $creditAccount->id,
            'currency_id' => $currency->id,
            'amount_currency' => 1000,
            'fx_rate' => 1,
            'debit_base' => 1000,
            'credit_base' => 0,
            'notes' => ProjectCostBudget::LINE_DESTINATION,
            'line_role' => TransactionLineRole::Destination->value,
        ]);

        $budget = ProjectCostBudget::create([
            'project_cost_id' => $projectCost->id,
            'transaction_id' => $budgetTransaction->id,
            'original_amount' => 1000,
            'amount_after_deductions' => 1000,
            'source_currency_id' => $currency->id,
            'disbursement_currency_id' => $currency->id,
            'final_amount' => 1000,
        ]);

        return compact('currency', 'accountType', 'bankType', 'creditAccount', 'beneficiary', 'fiscalYear', 'transactionType', 'partner', 'budget');
    }

    private function createExecutionPayment(array $fx): ProjectCostBudgetsPayment
    {
        $data = [
            'project_cost_budget_id' => $fx['budget']->id,
            'amount' => 100,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-19',
            'notes' => null,
            'beneficiary_account_id' => $fx['beneficiary']->id,
            'beneficiary_account_type_id' => $fx['beneficiary']->account_type_id,
            'beneficiary_bank_type_id' => $fx['beneficiary']->bank_type_id,
            'credit_account_id' => $fx['creditAccount']->id,
            'credit_account_type_id' => $fx['creditAccount']->account_type_id,
            'credit_bank_type_id' => $fx['creditAccount']->bank_type_id,
            'payment_image' => null,
        ];

        $page = new CreateExecutionPayment;
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

    // ---- tests ----------------------------------------------------------

    public function test_execution_payments_permission_grants_only_the_execution_payment_workflow(): void
    {
        $this->actingAs(User::factory()->create());
        $fx = $this->baseFixture();
        $payment = $this->createExecutionPayment($fx);
        $budget = $fx['budget'];

        $this->actingAs($this->userWithPermissions([
            'execution_payments.view_any', 'execution_payments.view', 'execution_payments.create', 'execution_payments.update',
        ]));

        $this->get('/admin/execution-payments')->assertOk();
        $this->get('/admin/execution-payments/create')->assertOk();
        $this->get("/admin/execution-payments/{$payment->id}")->assertOk();
        $this->get("/admin/execution-payments/{$payment->id}/edit")->assertOk();

        $this->get('/admin/project-cost-budgets-disbursements')->assertForbidden();
        $this->get('/admin/project-cost-budgets-disbursements/create')->assertForbidden();
        $this->get("/admin/project-cost-budgets-disbursements/{$budget->id}")->assertForbidden();
        $this->get("/admin/project-cost-budgets-disbursements/{$budget->id}/edit")->assertForbidden();
    }

    public function test_project_cost_budgets_payments_permission_grants_only_the_disbursement_workflow(): void
    {
        $this->actingAs(User::factory()->create());
        $fx = $this->baseFixture();
        $payment = $this->createExecutionPayment($fx);
        $budget = $fx['budget'];

        $this->actingAs($this->userWithPermissions([
            'project_cost_budgets_payments.view_any', 'project_cost_budgets_payments.view',
            'project_cost_budgets_payments.create', 'project_cost_budgets_payments.update',
        ]));

        $this->get('/admin/project-cost-budgets-disbursements')->assertOk();
        $this->get('/admin/project-cost-budgets-disbursements/create')->assertOk();
        $this->get("/admin/project-cost-budgets-disbursements/{$budget->id}")->assertOk();
        $this->get("/admin/project-cost-budgets-disbursements/{$budget->id}/edit")->assertOk();

        $this->get('/admin/execution-payments')->assertForbidden();
        $this->get('/admin/execution-payments/create')->assertForbidden();
        $this->get("/admin/execution-payments/{$payment->id}")->assertForbidden();
        $this->get("/admin/execution-payments/{$payment->id}/edit")->assertForbidden();
    }

    public function test_same_numeric_id_exists_in_both_tables_without_cross_exposure(): void
    {
        $this->actingAs(User::factory()->create());
        $fx = $this->baseFixture();
        $payment = $this->createExecutionPayment($fx);

        // Sanity: independent auto-increments, same numeric id in both tables.
        $this->assertSame($fx['budget']->id, $payment->id);

        $bothPermissions = $this->userWithPermissions([
            'execution_payments.view_any', 'execution_payments.view',
            'project_cost_budgets_payments.view_any', 'project_cost_budgets_payments.view',
        ]);
        $this->actingAs($bothPermissions);

        $executionPaymentPage = $this->get("/admin/execution-payments/{$payment->id}");
        $executionPaymentPage->assertOk();
        $executionPaymentPage->assertSee($fx['beneficiary']->name);

        $disbursementPage = $this->get("/admin/project-cost-budgets-disbursements/{$fx['budget']->id}");
        $disbursementPage->assertOk();
        $disbursementPage->assertSee($fx['creditAccount']->name);
    }

    public function test_record_id_belonging_only_to_the_budgets_table_returns_not_found_via_execution_payments(): void
    {
        $this->actingAs(User::factory()->create());
        $fx = $this->baseFixture();
        // No execution payment created against this budget — id 1 only exists
        // in project_cost_budgets, never in project_cost_budgets_payments.

        $this->actingAs($this->userWithPermissions(['execution_payments.view']));

        $this->get("/admin/execution-payments/{$fx['budget']->id}")->assertNotFound();
    }

    public function test_soft_deleted_execution_payment_cannot_be_opened_through_the_normal_edit_route(): void
    {
        $this->actingAs(User::factory()->create());
        $fx = $this->baseFixture();
        $payment = $this->createExecutionPayment($fx);
        $payment->delete();

        $this->actingAs($this->userWithPermissions(['execution_payments.view', 'execution_payments.update']));

        $this->get("/admin/execution-payments/{$payment->id}")->assertForbidden();
        $this->get("/admin/execution-payments/{$payment->id}/edit")->assertForbidden();
    }
}
