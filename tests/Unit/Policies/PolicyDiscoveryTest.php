<?php

namespace Tests\Unit\Policies;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Attachment;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\ExchangeRateHistory;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Policies\AccountPolicy;
use App\Policies\AccountTypePolicy;
use App\Policies\AttachmentPolicy;
use App\Policies\BankTypePolicy;
use App\Policies\CurrencyPolicy;
use App\Policies\ExchangeRateHistoryPolicy;
use App\Policies\FiscalYearPolicy;
use App\Policies\GeneralExchangePolicy;
use App\Policies\GeneralExpensePolicy;
use App\Policies\PartnerPolicy;
use App\Policies\PartnerTypePolicy;
use App\Policies\ProjectCostBudgetPolicy;
use App\Policies\ProjectCostBudgetsPaymentPolicy;
use App\Policies\ProjectCostPolicy;
use App\Policies\ProjectCostReceiptPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectStatusPolicy;
use App\Policies\ProjectSuperPolicy;
use App\Policies\SettingPolicy;
use App\Policies\TransactionLinePolicy;
use App\Policies\TransactionPolicy;
use App\Policies\TransactionSuperTypePolicy;
use App\Policies\TransactionTypePolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves Laravel's Gate actually resolves each concrete model to the
 * intended policy class and permission-module prefix — required per the
 * OMS Permissions Task 2A spec: "Do not rely only on class-name similarity."
 * The two entries for ProjectCostBudget / ProjectCostBudgetsPayment are the
 * critical ones: their permission prefixes are deliberately swapped relative
 * to what the class names would suggest (see the docblocks on
 * ProjectCostBudgetPolicy / ProjectCostBudgetsPaymentPolicy).
 *
 * No database/migrations needed — Gate::getPolicyFor() only resolves class
 * names via Laravel's policy-discovery convention.
 */
class PolicyDiscoveryTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string, 2: string}>
     */
    public static function modelPolicyModuleProvider(): array
    {
        return [
            'Account' => [Account::class, AccountPolicy::class, 'accounts'],
            'Transaction' => [Transaction::class, TransactionPolicy::class, 'transactions'],
            'TransactionLine' => [TransactionLine::class, TransactionLinePolicy::class, 'transaction_lines'],
            'GeneralExpense' => [GeneralExpense::class, GeneralExpensePolicy::class, 'general_expenses'],
            'GeneralExchange' => [GeneralExchange::class, GeneralExchangePolicy::class, 'general_exchanges'],
            // Deliberately swapped mapping — see class docblocks.
            'ProjectCostBudgetsPayment (execution_payments)' => [ProjectCostBudgetsPayment::class, ProjectCostBudgetsPaymentPolicy::class, 'execution_payments'],
            'ProjectCostBudget (project_cost_budgets_payments)' => [ProjectCostBudget::class, ProjectCostBudgetPolicy::class, 'project_cost_budgets_payments'],
            'ProjectCostReceipt' => [ProjectCostReceipt::class, ProjectCostReceiptPolicy::class, 'project_cost_receipts'],
            'Project' => [Project::class, ProjectPolicy::class, 'projects'],
            'ProjectCost' => [ProjectCost::class, ProjectCostPolicy::class, 'project_costs'],
            'ProjectSuper' => [ProjectSuper::class, ProjectSuperPolicy::class, 'project_supers'],
            'Partner' => [Partner::class, PartnerPolicy::class, 'partners'],
            'BankType' => [BankType::class, BankTypePolicy::class, 'bank_types'],
            'Currency' => [Currency::class, CurrencyPolicy::class, 'currencies'],
            'AccountType' => [AccountType::class, AccountTypePolicy::class, 'account_types'],
            'ExchangeRateHistory' => [ExchangeRateHistory::class, ExchangeRateHistoryPolicy::class, 'exchange_rate_histories'],
            'FiscalYear' => [FiscalYear::class, FiscalYearPolicy::class, 'fiscal_years'],
            'PartnerType' => [PartnerType::class, PartnerTypePolicy::class, 'partner_types'],
            'TransactionType' => [TransactionType::class, TransactionTypePolicy::class, 'transaction_types'],
            'TransactionSuperType' => [TransactionSuperType::class, TransactionSuperTypePolicy::class, 'transaction_super_types'],
            'ProjectStatus' => [ProjectStatus::class, ProjectStatusPolicy::class, 'project_statuses'],
            'Setting' => [Setting::class, SettingPolicy::class, 'settings'],
            'Attachment' => [Attachment::class, AttachmentPolicy::class, 'attachments'],
        ];
    }

    #[DataProvider('modelPolicyModuleProvider')]
    public function test_model_resolves_to_the_intended_policy_and_permission_module(
        string $modelClass,
        string $expectedPolicyClass,
        string $expectedModule,
    ): void {
        $resolvedPolicy = Gate::getPolicyFor($modelClass);

        $this->assertInstanceOf(
            $expectedPolicyClass,
            $resolvedPolicy,
            "Gate resolved [{$modelClass}] to a different policy than expected.",
        );

        $this->assertSame(
            $expectedModule,
            $resolvedPolicy->permissionModule(),
            "Policy for [{$modelClass}] does not enforce the expected permission module.",
        );
    }

    public function test_project_cost_budget_and_project_cost_budgets_payment_do_not_share_a_permission_module(): void
    {
        $budgetPolicy = Gate::getPolicyFor(ProjectCostBudget::class);
        $paymentPolicy = Gate::getPolicyFor(ProjectCostBudgetsPayment::class);

        $this->assertNotSame($budgetPolicy->permissionModule(), $paymentPolicy->permissionModule());
        $this->assertSame('project_cost_budgets_payments', $budgetPolicy->permissionModule());
        $this->assertSame('execution_payments', $paymentPolicy->permissionModule());
    }
}
