<?php

namespace Tests\Feature\Commands;

use App\Enums\TransactionLineRole;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
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
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as TransactionDescriptionBuilderTest
 * / TransactionLineDescriptionBuilderTest: only the real structural migrations
 * for the tables this command touches are migrated, per test, on a fresh
 * :memory: connection, skipping the MySQL-only historical data migration.
 */
class BackfillTransactionDescriptionsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        Artisan::call('migrate', [
            '--path' => [
                // `users` and the two Task 8.2/8.3 reconciliation migrations
                // are needed because Account/AccountType now carry
                // HasUserTracking (OMS Task 9B.3), so every insert writes
                // created_by/updated_by.
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/2026_06_09_130001_create_fiscal_years_table.php',
                'database/migrations/2026_06_09_130002_create_currencies_table.php',
                'database/migrations/2026_06_09_130003_create_projects_super_table.php',
                'database/migrations/2026_06_09_130004_create_projects_status_table.php',
                'database/migrations/2026_06_09_130005_create_partners_types_table.php',
                'database/migrations/2026_06_09_130006_create_accounts_type_table.php',
                'database/migrations/2026_06_09_130007_create_transactions_types_table.php',
                'database/migrations/2026_06_09_130010_create_partners_table.php',
                'database/migrations/2026_06_09_130012_create_accounts_table.php',
                'database/migrations/2026_06_09_130013_create_projects_table.php',
                'database/migrations/2026_06_09_130014_create_projects_costs_table.php',
                'database/migrations/2026_06_09_130017_create_transactions_table.php',
                'database/migrations/2026_06_09_130018_create_transaction_lines_table.php',
                'database/migrations/2026_06_09_193018_add_columns_to_accounts_table.php',
                'database/migrations/2026_06_09_195154_drop_budget_amount_and_approved_by_from_projects.php',
                'database/migrations/2026_06_09_200223_drop_currency_id_from_projects_table.php',
                'database/migrations/2026_06_10_133957_replace_account_id_with_account_type_id_in_projects_costs.php',
                'database/migrations/2026_06_10_150000_create_transaction_super_types_table.php',
                'database/migrations/2026_06_10_150001_add_transaction_super_type_id_to_transactions_types_table.php',
                'database/migrations/2026_06_10_160000_drop_columns_from_projects_costs_table.php',
                'database/migrations/2026_06_10_160001_create_project_cost_budgets_table.php',
                'database/migrations/2026_06_10_160002_create_project_cost_budgets_payments_table.php',
                'database/migrations/2026_06_10_160003_create_project_cost_receipts_table.php',
                'database/migrations/2026_06_11_000001_add_currency_id_to_projects_costs_table.php',
                'database/migrations/2026_06_11_000003_add_code_to_projects_table.php',
                'database/migrations/2026_06_16_000001_make_project_cost_budget_id_nullable_on_payments.php',
                'database/migrations/2026_06_16_000002_add_disbursement_columns_to_project_cost_budgets.php',
                'database/migrations/2026_06_21_000001_create_general_expenses_table.php',
                'database/migrations/2026_06_21_000002_create_general_exchanges_table.php',
                'database/migrations/2026_06_21_000006_add_deleted_at_to_transaction_lines_table.php',
                'database/migrations/2026_06_24_000001_add_currency_id_to_project_cost_receipts_table.php',
                'database/migrations/2026_06_24_000002_denormalize_columns_on_project_cost_budgets_table.php',
                'database/migrations/2026_06_24_000003_add_currency_id_to_project_cost_budgets_payments_table.php',
                'database/migrations/2026_06_24_000004_add_currency_id_to_general_expenses_table.php',
                'database/migrations/2026_06_24_000006_create_project_financial_snapshots_table.php',
                'database/migrations/2026_07_14_120000_add_description_and_line_role_to_transaction_lines_table.php',
                'database/migrations/2026_07_28_110001_reconcile_accounts_type_schema_drift.php',
                'database/migrations/2026_07_28_120000_reconcile_accounts_user_tracking_schema_drift.php',
            ],
            '--realpath' => false,
            '--force'    => true,
        ]);
    }

    // ---- factories -----------------------------------------------------

    protected function makeCurrency(string $code = 'USD'): Currency
    {
        return Currency::create(['name' => $code, 'code' => $code, 'symbol' => $code]);
    }

    protected function makeAccountType(): AccountType
    {
        return AccountType::create(['name' => 'نوع تجريبي']);
    }

    protected function makeAccount(string $name, Currency $currency, ?AccountType $accountType = null): Account
    {
        return Account::create([
            'name'            => $name,
            'account_type_id' => ($accountType ?? $this->makeAccountType())->id,
            'currency_id'     => $currency->id,
            'current_balance' => 0,
            'is_active'       => true,
        ]);
    }

    protected function makeFiscalYear(): FiscalYear
    {
        return FiscalYear::create([
            'name'       => 'سنة تجريبية',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_active'  => true,
        ]);
    }

    protected function makeTransactionType(string $name): TransactionType
    {
        return TransactionType::create(['name' => $name]);
    }

    protected function makePartner(string $name): Partner
    {
        $type = PartnerType::create(['name' => 'نوع شريك']);

        return Partner::create(['name' => $name, 'partner_type_id' => $type->id]);
    }

    protected function makeProject(string $name): Project
    {
        $status = ProjectStatus::create(['name' => 'نشط']);

        return Project::create(['name' => $name, 'project_status_id' => $status->id]);
    }

    protected function makeProjectCost(Project $project, Currency $currency): ProjectCost
    {
        return ProjectCost::create([
            'project_id'  => $project->id,
            'amount'      => 1000,
            'currency_id' => $currency->id,
        ]);
    }

    protected function makeTransaction(TransactionType $type, ?Partner $partner = null, ?string $number = null): Transaction
    {
        return Transaction::create([
            'fiscal_year_id'      => $this->makeFiscalYear()->id,
            'transaction_type_id' => $type->id,
            'transaction_number'  => $number ?? ('TST-' . uniqid()),
            'transaction_time'    => now(),
            'partner_id'          => $partner?->id,
        ]);
    }

    protected function makeLine(
        Transaction $transaction,
        Account $account,
        Currency $currency,
        float $debit,
        float $credit,
        ?string $notes = null
    ): TransactionLine {
        return TransactionLine::create([
            'transaction_id'  => $transaction->id,
            'account_id'      => $account->id,
            'currency_id'     => $currency->id,
            'amount_currency' => max($debit, $credit),
            'fx_rate'         => 1,
            'debit_base'      => $debit,
            'credit_base'     => $credit,
            'notes'           => $notes,
        ]);
    }

    protected function makeOpeningBalanceType(): TransactionType
    {
        return $this->makeTransactionType('قيد افتتاحي');
    }

    // ---- tests -----------------------------------------------------------

    public function test_dry_run_performs_no_writes(): void
    {
        $usd    = $this->makeCurrency();
        $type   = $this->makeTransactionType('مصروف عام');
        $tx     = $this->makeTransaction($type);
        $debit  = $this->makeLine($tx, $this->makeAccount('حساب أ', $usd), $usd, 100, 0);
        $credit = $this->makeLine($tx, $this->makeAccount('حساب ب', $usd), $usd, 0, 100);
        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 100, 'date' => now(), 'description' => 'قرطاسية']);

        Artisan::call('transactions:backfill-descriptions', ['--dry-run' => true]);

        $this->assertNull($tx->fresh()->description);
        $this->assertNull($debit->fresh()->line_role);
        $this->assertNull($debit->fresh()->description);
        $this->assertNull($credit->fresh()->line_role);
    }

    public function test_apply_updates_a_classified_receipt(): void
    {
        $usd     = $this->makeCurrency();
        $type    = $this->makeTransactionType('استلام مبلغ');
        $partner = $this->makePartner('جمعية كاف');
        $project = $this->makeProject('الرغيف الخيري');
        $pcost   = $this->makeProjectCost($project, $usd);
        $tx      = $this->makeTransaction($type, $partner);

        $debit  = $this->makeLine($tx, $this->makeAccount('بنك المشروع', $usd), $usd, 12000, 0);
        $credit = $this->makeLine($tx, $this->makeAccount('صندوق الجمعية', $usd), $usd, 0, 12000);

        ProjectCostReceipt::create([
            'project_cost_id' => $pcost->id,
            'transaction_id'  => $tx->id,
            'amount'          => 12000,
            'date'            => now(),
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::ReceiptDestination->value, $debit->fresh()->line_role);
        $this->assertSame(TransactionLineRole::FundingSource->value, $credit->fresh()->line_role);
        $this->assertStringContainsString('إيداع المبلغ المستلم لمشروع الرغيف الخيري', $debit->fresh()->description);
        $this->assertStringContainsString('إثبات تمويل مشروع الرغيف الخيري', $credit->fresh()->description);
        $this->assertStringContainsString('استلام مبلغ من جمعية كاف لتمويل مشروع الرغيف الخيري', $tx->fresh()->description);
    }

    public function test_apply_updates_a_classified_disbursement(): void
    {
        $usd     = $this->makeCurrency();
        $type    = $this->makeTransactionType('صرف مبلغ مشروع');
        $project = $this->makeProject('مشروع الشتاء');
        $pcost   = $this->makeProjectCost($project, $usd);
        $tx      = $this->makeTransaction($type);

        $source      = $this->makeLine($tx, $this->makeAccount('حساب المصدر', $usd), $usd, 0, 10000, ProjectCostBudget::LINE_SOURCE);
        $admin       = $this->makeLine($tx, $this->makeAccount('حساب إداري', $usd), $usd, 300, 0, ProjectCostBudget::LINE_ADMIN);
        $transfer    = $this->makeLine($tx, $this->makeAccount('حساب تحويل', $usd), $usd, 200, 0, ProjectCostBudget::LINE_TRANSFER);
        $destination = $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 9500, 0, ProjectCostBudget::LINE_DESTINATION);

        ProjectCostBudget::create([
            'project_cost_id' => $pcost->id,
            'transaction_id'  => $tx->id,
            'final_amount'    => 9500,
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Source->value, $source->fresh()->line_role);
        $this->assertSame(TransactionLineRole::AdministrativeDeduction->value, $admin->fresh()->line_role);
        $this->assertSame(TransactionLineRole::TransferFee->value, $transfer->fresh()->line_role);
        $this->assertSame(TransactionLineRole::Destination->value, $destination->fresh()->line_role);
        $this->assertStringContainsString('صرف مبلغ لمشروع مشروع الشتاء بعد الخصومات والتحويل', $tx->fresh()->description);
    }

    /* =====================================================================
     | Optional deduction tags: 2- and 3-line BUD/EXT flows
     |
     | Since 2026-08-19 a 0% administrative or transfer percentage writes no
     | line at all, so a disbursement or general exchange legitimately carries
     | a SUBSET of its four notes tags. LINE_SOURCE and LINE_DESTINATION stay
     | required; the two deduction tags are optional. Everything else about
     | the classifier stays strict.
     ===================================================================== */

    public function test_apply_updates_a_three_line_disbursement_without_an_administrative_deduction(): void
    {
        $usd     = $this->makeCurrency();
        $type    = $this->makeTransactionType('صرف مبلغ مشروع');
        $project = $this->makeProject('مشروع بلا خصم إداري');
        $pcost   = $this->makeProjectCost($project, $usd);
        $tx      = $this->makeTransaction($type);

        $source      = $this->makeLine($tx, $this->makeAccount('حساب المصدر', $usd), $usd, 0, 10000, ProjectCostBudget::LINE_SOURCE);
        $transfer    = $this->makeLine($tx, $this->makeAccount('حساب تحويل', $usd), $usd, 200, 0, ProjectCostBudget::LINE_TRANSFER);
        $destination = $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 9800, 0, ProjectCostBudget::LINE_DESTINATION);

        ProjectCostBudget::create([
            'project_cost_id' => $pcost->id,
            'transaction_id'  => $tx->id,
            'final_amount'    => 9800,
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Source->value, $source->fresh()->line_role);
        $this->assertSame(TransactionLineRole::TransferFee->value, $transfer->fresh()->line_role);
        $this->assertSame(TransactionLineRole::Destination->value, $destination->fresh()->line_role);
        $this->assertStringContainsString('صرف مبلغ لمشروع مشروع بلا خصم إداري بعد الخصومات والتحويل', $tx->fresh()->description);
    }

    public function test_apply_updates_a_two_line_disbursement_with_neither_deduction(): void
    {
        $usd     = $this->makeCurrency();
        $type    = $this->makeTransactionType('صرف مبلغ مشروع');
        $project = $this->makeProject('مشروع بلا خصومات');
        $pcost   = $this->makeProjectCost($project, $usd);
        $tx      = $this->makeTransaction($type);

        $source      = $this->makeLine($tx, $this->makeAccount('حساب المصدر', $usd), $usd, 0, 10000, ProjectCostBudget::LINE_SOURCE);
        $destination = $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 10000, 0, ProjectCostBudget::LINE_DESTINATION);

        ProjectCostBudget::create([
            'project_cost_id' => $pcost->id,
            'transaction_id'  => $tx->id,
            'final_amount'    => 10000,
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Source->value, $source->fresh()->line_role);
        $this->assertSame(TransactionLineRole::Destination->value, $destination->fresh()->line_role);
        $this->assertStringContainsString('صرف مبلغ لمشروع مشروع بلا خصومات بعد الخصومات والتحويل', $tx->fresh()->description);
    }

    public function test_apply_updates_a_two_line_general_exchange_with_neither_deduction(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('تحويل عام');
        $tx   = $this->makeTransaction($type);

        $source      = $this->makeLine($tx, $this->makeAccount('حساب المصدر', $usd), $usd, 0, 2000, GeneralExchange::LINE_SOURCE);
        $destination = $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 2000, 0, GeneralExchange::LINE_DESTINATION);

        GeneralExchange::create([
            'transaction_id'  => $tx->id,
            'original_amount' => 2000,
            'final_amount'    => 2000,
            'date'            => '2026-07-18',
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Source->value, $source->fresh()->line_role);
        $this->assertSame(TransactionLineRole::Destination->value, $destination->fresh()->line_role);
        $this->assertNotNull($tx->fresh()->description);
    }

    public function test_a_disbursement_missing_its_required_source_tag_is_not_classified(): void
    {
        $usd     = $this->makeCurrency();
        $type    = $this->makeTransactionType('صرف مبلغ مشروع');
        $project = $this->makeProject('مشروع ناقص');
        $pcost   = $this->makeProjectCost($project, $usd);
        $tx      = $this->makeTransaction($type);

        // Only a deduction and a destination: LINE_SOURCE is required and
        // absent, so this must not be classified on a guess.
        $admin       = $this->makeLine($tx, $this->makeAccount('حساب إداري', $usd), $usd, 300, 0, ProjectCostBudget::LINE_ADMIN);
        $destination = $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 9500, 0, ProjectCostBudget::LINE_DESTINATION);

        ProjectCostBudget::create([
            'project_cost_id' => $pcost->id,
            'transaction_id'  => $tx->id,
            'final_amount'    => 9500,
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertNull($admin->fresh()->line_role);
        $this->assertNull($destination->fresh()->line_role);
        $this->assertNull($tx->fresh()->description);
    }

    public function test_a_disbursement_with_a_duplicated_tag_is_not_classified(): void
    {
        $usd     = $this->makeCurrency();
        $type    = $this->makeTransactionType('صرف مبلغ مشروع');
        $project = $this->makeProject('مشروع مكرر');
        $pcost   = $this->makeProjectCost($project, $usd);
        $tx      = $this->makeTransaction($type);

        $source      = $this->makeLine($tx, $this->makeAccount('حساب المصدر', $usd), $usd, 0, 10000, ProjectCostBudget::LINE_SOURCE);
        $duplicate   = $this->makeLine($tx, $this->makeAccount('حساب المصدر 2', $usd), $usd, 0, 500, ProjectCostBudget::LINE_SOURCE);
        $destination = $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 10500, 0, ProjectCostBudget::LINE_DESTINATION);

        ProjectCostBudget::create([
            'project_cost_id' => $pcost->id,
            'transaction_id'  => $tx->id,
            'final_amount'    => 10500,
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertNull($source->fresh()->line_role);
        $this->assertNull($duplicate->fresh()->line_role);
        $this->assertNull($destination->fresh()->line_role);
    }

    public function test_apply_updates_a_classified_execution_payment(): void
    {
        $usd     = $this->makeCurrency();
        $type    = $this->makeTransactionType('مبلغ تنفيذ للمشروع');
        $project = $this->makeProject('مشروع الإغاثة');
        $pcost   = $this->makeProjectCost($project, $usd);
        $tx      = $this->makeTransaction($type);

        $budget = ProjectCostBudget::create([
            'project_cost_id' => $pcost->id,
            'final_amount'    => 5000,
        ]);

        $beneficiary = $this->makeLine($tx, $this->makeAccount('حساب المستفيد', $usd), $usd, 5000, 0, ProjectCostBudgetsPayment::LINE_BENEFICIARY);
        $credit      = $this->makeLine($tx, $this->makeAccount('حساب التنفيذ', $usd), $usd, 0, 5000, ProjectCostBudgetsPayment::LINE_CREDIT);

        ProjectCostBudgetsPayment::create([
            'project_cost_budget_id' => $budget->id,
            'transaction_id'         => $tx->id,
            'amount'                 => 5000,
            'date'                   => now(),
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Beneficiary->value, $beneficiary->fresh()->line_role);
        $this->assertSame(TransactionLineRole::ExecutionSource->value, $credit->fresh()->line_role);
        $this->assertStringContainsString('صرف مبلغ تنفيذ ضمن مشروع مشروع الإغاثة', $tx->fresh()->description);
    }

    public function test_apply_updates_a_classified_general_expense(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);

        $expenseLine = $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0);
        $sourceLine  = $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);

        GeneralExpense::create([
            'transaction_id' => $tx->id,
            'amount'         => 500,
            'date'           => now(),
            'description'    => 'محروقات مركبة',
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Expense->value, $expenseLine->fresh()->line_role);
        $this->assertSame(TransactionLineRole::Source->value, $sourceLine->fresh()->line_role);
        $this->assertStringContainsString('إثبات مصروف محروقات مركبة', $expenseLine->fresh()->description);
        $this->assertStringContainsString('دفع مصروف محروقات مركبة', $sourceLine->fresh()->description);
        $this->assertStringContainsString('تسجيل مصروف عام: محروقات مركبة', $tx->fresh()->description);
    }

    public function test_apply_updates_a_classified_general_exchange(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('تحويل بين الحسابات');
        $tx   = $this->makeTransaction($type);

        $source      = $this->makeLine($tx, $this->makeAccount('حساب المصدر', $usd), $usd, 0, 2000, GeneralExchange::LINE_SOURCE);
        $admin       = $this->makeLine($tx, $this->makeAccount('حساب إداري', $usd), $usd, 0, 0, GeneralExchange::LINE_ADMIN);
        $transfer    = $this->makeLine($tx, $this->makeAccount('حساب تحويل', $usd), $usd, 0, 0, GeneralExchange::LINE_TRANSFER);
        $destination = $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 2000, 0, GeneralExchange::LINE_DESTINATION);

        GeneralExchange::create([
            'transaction_id'  => $tx->id,
            'original_amount' => 2000,
            'final_amount'    => 2000,
            'date'            => now(),
        ]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Source->value, $source->fresh()->line_role);
        $this->assertSame(TransactionLineRole::AdministrativeDeduction->value, $admin->fresh()->line_role);
        $this->assertSame(TransactionLineRole::TransferFee->value, $transfer->fresh()->line_role);
        $this->assertSame(TransactionLineRole::Destination->value, $destination->fresh()->line_role);

        // Zero-amount admin/transfer lines: role assigned, description stays NULL.
        $this->assertNull($admin->fresh()->description);
        $this->assertNull($transfer->fresh()->description);
        $this->assertNotNull($source->fresh()->description);
        $this->assertNotNull($destination->fresh()->description);
    }

    public function test_apply_updates_a_deterministic_opening_balance(): void
    {
        $usd     = $this->makeCurrency();
        $openingType = $this->makeOpeningBalanceType();
        $tx      = $this->makeTransaction($openingType);

        $target      = $this->makeLine($tx, $this->makeAccount('محمد صبري المدني', $usd), $usd, 100000, 0, 'قيد افتتاحي');
        $counterpart = $this->makeLine($tx, $this->makeAccount('أرصدة افتتاحية', $usd), $usd, 0, 100000, 'قيد افتتاحي');

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::OpeningBalanceTarget->value, $target->fresh()->line_role);
        $this->assertSame(TransactionLineRole::OpeningBalanceCounterpart->value, $counterpart->fresh()->line_role);
        $this->assertStringContainsString('تسجيل الرصيد الافتتاحي لحساب محمد صبري المدني', $tx->fresh()->description);
    }

    public function test_zero_value_line_gets_role_and_null_description(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('تحويل بين الحسابات');
        $tx   = $this->makeTransaction($type);

        $this->makeLine($tx, $this->makeAccount('حساب المصدر', $usd), $usd, 0, 1000, GeneralExchange::LINE_SOURCE);
        $admin = $this->makeLine($tx, $this->makeAccount('حساب إداري', $usd), $usd, 0, 0, GeneralExchange::LINE_ADMIN);
        $this->makeLine($tx, $this->makeAccount('حساب تحويل', $usd), $usd, 0, 0, GeneralExchange::LINE_TRANSFER);
        $this->makeLine($tx, $this->makeAccount('حساب الوجهة', $usd), $usd, 1000, 0, GeneralExchange::LINE_DESTINATION);

        GeneralExchange::create(['transaction_id' => $tx->id, 'original_amount' => 1000, 'final_amount' => 1000, 'date' => now()]);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::AdministrativeDeduction->value, $admin->fresh()->line_role);
        $this->assertNull($admin->fresh()->description);
    }

    public function test_existing_non_canonical_metadata_is_regenerated_canonically(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);
        $tx->update(['description' => 'وصف قديم غير معتمد']);

        $expenseLine = $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0);
        $expenseLine->update(['line_role' => 'some_stale_role', 'description' => 'نص قديم']);
        $sourceLine = $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);

        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame(TransactionLineRole::Expense->value, $expenseLine->fresh()->line_role);
        $this->assertStringContainsString('إثبات مصروف كهرباء', $expenseLine->fresh()->description);
        $this->assertStringContainsString('تسجيل مصروف عام: كهرباء', $tx->fresh()->description);
    }

    public function test_already_canonical_transaction_remains_unchanged(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);

        $expenseLine = $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0);
        $sourceLine  = $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);

        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);
        $firstUpdatedAt = $expenseLine->fresh()->updated_at;

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Transactions updated:   0', $output);
        $this->assertStringContainsString('Lines updated:           0', $output);
        $this->assertEquals($firstUpdatedAt, $expenseLine->fresh()->updated_at);
    }

    public function test_second_apply_run_is_idempotent(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);
        $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0);
        $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);
        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);
        $txCountAfterFirst   = Transaction::count();
        $lineCountAfterFirst = TransactionLine::count();

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame($txCountAfterFirst, Transaction::count());
        $this->assertSame($lineCountAfterFirst, TransactionLine::count());
    }

    public function test_unclassified_transaction_is_skipped(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('نوع غير معروف');
        $tx   = $this->makeTransaction($type);
        $line = $this->makeLine($tx, $this->makeAccount('حساب أ', $usd), $usd, 100, 0);
        $this->makeLine($tx, $this->makeAccount('حساب ب', $usd), $usd, 0, 100);
        // No domain record links this transaction to any of the six flows.

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Unclassified transactions: 1', $output);
        $this->assertNull($tx->fresh()->description);
        $this->assertNull($line->fresh()->line_role);
    }

    public function test_soft_deleted_line_is_not_updated(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);
        $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0);
        $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);
        $trashed = $this->makeLine($tx, $this->makeAccount('حساب قديم', $usd), $usd, 999, 0);
        $trashed->delete();

        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertNull($trashed->fresh()->line_role);
        $this->assertNull($trashed->fresh()->description);
    }

    public function test_notes_and_monetary_fields_remain_unchanged(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);
        $tx->update(['notes' => 'ملاحظة أصلية']);

        $expenseLine = $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0, 'ملاحظة سطر أصلية');
        $sourceLine  = $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);

        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame('ملاحظة أصلية', $tx->fresh()->notes);
        $this->assertSame('ملاحظة سطر أصلية', $expenseLine->fresh()->notes);
        $this->assertEquals(500, (float) $expenseLine->fresh()->debit_base);
        $this->assertEquals(0, (float) $expenseLine->fresh()->credit_base);
        $this->assertEquals(500, (float) $sourceLine->fresh()->credit_base);
    }

    public function test_no_additional_transaction_or_line_is_created(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);
        $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0);
        $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);
        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        $txCountBefore   = Transaction::count();
        $lineCountBefore = TransactionLine::count();

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true]);

        $this->assertSame($txCountBefore, Transaction::count());
        $this->assertSame($lineCountBefore, TransactionLine::count());
    }

    public function test_neither_option_shows_usage_and_writes_nothing(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');
        $tx   = $this->makeTransaction($type);
        $this->makeLine($tx, $this->makeAccount('حساب المصروف', $usd), $usd, 500, 0);
        $this->makeLine($tx, $this->makeAccount('حساب الصندوق', $usd), $usd, 0, 500);
        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        $exitCode = Artisan::call('transactions:backfill-descriptions');

        $this->assertNotSame(0, $exitCode);
        $this->assertNull($tx->fresh()->description);
    }

    public function test_transaction_id_option_limits_scope(): void
    {
        $usd  = $this->makeCurrency();
        $type = $this->makeTransactionType('محروقات');

        $tx1 = $this->makeTransaction($type);
        $this->makeLine($tx1, $this->makeAccount('حساب أ', $usd), $usd, 500, 0);
        $this->makeLine($tx1, $this->makeAccount('حساب ب', $usd), $usd, 0, 500);
        GeneralExpense::create(['transaction_id' => $tx1->id, 'amount' => 500, 'date' => now(), 'description' => 'كهرباء']);

        $tx2 = $this->makeTransaction($type);
        $this->makeLine($tx2, $this->makeAccount('حساب ج', $usd), $usd, 200, 0);
        $this->makeLine($tx2, $this->makeAccount('حساب د', $usd), $usd, 0, 200);
        GeneralExpense::create(['transaction_id' => $tx2->id, 'amount' => 200, 'date' => now(), 'description' => 'صيانة']);

        Artisan::call('transactions:backfill-descriptions', ['--apply' => true, '--transaction-id' => $tx1->id]);

        $this->assertNotNull($tx1->fresh()->description);
        $this->assertNull($tx2->fresh()->description);
    }
}
