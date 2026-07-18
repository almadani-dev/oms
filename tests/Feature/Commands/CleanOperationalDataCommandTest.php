<?php

namespace Tests\Feature\Commands;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Attachment;
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
use App\Models\Reports\ProjectFinancialAlert;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\Reports\ProjectFinancialSnapshotCurrencyTotal;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Maintenance\OperationalDataCleanupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as BackfillTransactionDescriptionsCommandTest:
 * every real migration is applied, per test, to a fresh :memory: connection, except the
 * MySQL-only raw-SQL backfill migration.
 */
class CleanOperationalDataCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        // Both excluded migrations contain raw MySQL-only SQL (a JOIN-based
        // UPDATE backfill, and a `SHOW INDEX FROM` existence guard for
        // performance indexes) that SQLite cannot run. Neither adds a table
        // or column this test suite depends on.
        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/' . basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path'     => $paths,
            '--realpath' => false,
            '--force'    => true,
        ]);
    }

    private function backupFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oms-backup-');
        file_put_contents($path, 'fake backup content');

        return $path;
    }

    // ---- factories -------------------------------------------------------

    private function makeCurrency(string $code = 'USD', bool $isBase = true): Currency
    {
        return Currency::create(['name' => $code, 'code' => $code, 'symbol' => $code, 'is_base' => $isBase]);
    }

    private function makeAccountType(): AccountType
    {
        return AccountType::create(['name' => 'نوع تجريبي']);
    }

    private function makeAccount(string $name, Currency $currency, float $balance = 100): Account
    {
        return Account::create([
            'account_code'    => $name,
            'name'            => $name,
            'account_type_id' => $this->makeAccountType()->id,
            'currency_id'     => $currency->id,
            'current_balance' => $balance,
            'is_active'       => true,
        ]);
    }

    private function makeFiscalYear(): FiscalYear
    {
        return FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
    }

    private function makeTransactionType(string $name = 'مصروف عام'): TransactionType
    {
        return TransactionType::create(['name' => $name]);
    }

    private function makePartnerType(string $name = 'نوع شريك'): PartnerType
    {
        return PartnerType::create(['name' => $name]);
    }

    private function makeDonor(string $name = 'مانح تجريبي'): Partner
    {
        return Partner::create(['name' => $name, 'partner_type_id' => $this->makePartnerType()->id, 'is_donor' => true]);
    }

    private function makeProjectStatus(): ProjectStatus
    {
        return ProjectStatus::create(['name' => 'نشط']);
    }

    private function makeProject(string $name = 'مشروع تجريبي', ?Partner $donor = null): Project
    {
        return Project::create([
            'name'              => $name,
            'project_status_id' => $this->makeProjectStatus()->id,
            'donor_id'          => $donor?->id,
        ]);
    }

    private function makeProjectCost(Project $project, Currency $currency): ProjectCost
    {
        return ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $currency->id]);
    }

    private function makeTransaction(TransactionType $type, ?string $number = null): Transaction
    {
        return Transaction::create([
            'fiscal_year_id'      => $this->makeFiscalYear()->id,
            'transaction_type_id' => $type->id,
            'transaction_number'  => $number ?? ('TST-' . uniqid()),
            'transaction_time'    => now(),
        ]);
    }

    private function makeLine(Transaction $transaction, Account $account, Currency $currency, float $debit, float $credit): TransactionLine
    {
        return TransactionLine::create([
            'transaction_id'  => $transaction->id,
            'account_id'      => $account->id,
            'currency_id'     => $currency->id,
            'amount_currency' => max($debit, $credit),
            'fx_rate'         => 1,
            'debit_base'      => $debit,
            'credit_base'     => $credit,
        ]);
    }

    // ---- 1. dry-run makes no database changes -----------------------------

    public function test_dry_run_makes_no_database_changes(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject(donor: $this->makeDonor());
        $cost = $this->makeProjectCost($project, $usd);
        $account = $this->makeAccount('حساب', $usd, 500);
        $type = $this->makeTransactionType();
        $tx = $this->makeTransaction($type);
        $this->makeLine($tx, $account, $usd, 100, 0);

        $before = [
            'projects'     => Project::withTrashed()->count(),
            'transactions' => Transaction::withTrashed()->count(),
            'lines'        => TransactionLine::withTrashed()->count(),
            'balance'      => Account::find($account->id)->current_balance,
        ];

        Artisan::call('oms:clean-operational-data', ['--dry-run' => true]);

        $this->assertSame($before['projects'], Project::withTrashed()->count());
        $this->assertSame($before['transactions'], Transaction::withTrashed()->count());
        $this->assertSame($before['lines'], TransactionLine::withTrashed()->count());
        $this->assertEquals($before['balance'], Account::find($account->id)->current_balance);
        $this->assertStringContainsString('Dry run performed zero database writes', Artisan::output());
    }

    // ---- 2. refuses production ---------------------------------------------

    public function test_refuses_production_environment(): void
    {
        config(['app.env' => 'production']);

        $exit = Artisan::call('oms:clean-operational-data', ['--dry-run' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("environment 'production'", Artisan::output());
    }

    // ---- 3. refuses apply without confirmation token -----------------------

    public function test_refuses_apply_without_confirmation_token(): void
    {
        $exit = Artisan::call('oms:clean-operational-data', [
            '--apply'       => true,
            '--confirmation' => 'WRONG-TOKEN',
            '--backup-file'  => $this->backupFile(),
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('confirmation token does not match', Artisan::output());
    }

    // ---- 4. refuses apply without a valid backup file -----------------------

    public function test_refuses_apply_without_valid_backup_file(): void
    {
        $exit = Artisan::call('oms:clean-operational-data', [
            '--apply'        => true,
            '--confirmation' => OperationalDataCleanupService::CONFIRMATION_TOKEN,
            '--backup-file'  => '/path/does/not/exist.sql',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not exist', Artisan::output());

        $emptyPath = tempnam(sys_get_temp_dir(), 'oms-empty-');
        $exit2 = Artisan::call('oms:clean-operational-data', [
            '--apply'        => true,
            '--confirmation' => OperationalDataCleanupService::CONFIRMATION_TOKEN,
            '--backup-file'  => $emptyPath,
        ]);

        $this->assertSame(1, $exit2);
        $this->assertStringContainsString('is empty', Artisan::output());
    }

    // ---- command-level apply wiring (end-to-end via Artisan) -----------------

    public function test_command_apply_executes_successfully_via_cli(): void
    {
        $usd = $this->makeCurrency();
        $this->makeAccount('a', $usd, 300);
        $project = $this->makeProject(donor: $this->makeDonor());
        $this->makeProjectCost($project, $usd);

        $exit = Artisan::call('oms:clean-operational-data', [
            '--apply'        => true,
            '--confirmation' => OperationalDataCleanupService::CONFIRMATION_TOKEN,
            '--backup-file'  => $this->backupFile(),
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Post-apply verification: PASSED', Artisan::output());
        $this->assertSame(0, Project::count());
    }

    // ---- 5. donor records are preserved -------------------------------------

    public function test_donor_records_are_preserved(): void
    {
        $donor = $this->makeDonor('جمعية الخير');
        $project = $this->makeProject(donor: $donor);
        $this->makeProjectCost($project, $this->makeCurrency());

        $this->apply();

        $this->assertNotNull(Partner::find($donor->id));
        $this->assertSame('جمعية الخير', Partner::find($donor->id)->name);
        $this->assertTrue((bool) Partner::find($donor->id)->is_donor);
    }

    // ---- 6/7. accounts + account definitions preserved ----------------------

    public function test_accounts_and_definitions_are_preserved(): void
    {
        $usd = $this->makeCurrency();
        $account = $this->makeAccount('حساب البنك', $usd, 750);

        $this->apply();

        $fresh = Account::find($account->id);
        $this->assertNotNull($fresh);
        $this->assertSame('حساب البنك', $fresh->name);
        $this->assertSame($account->account_type_id, $fresh->account_type_id);
        $this->assertSame($account->currency_id, $fresh->currency_id);
    }

    // ---- 8. account balances become zero during apply -----------------------

    public function test_account_balances_become_zero_during_apply(): void
    {
        $usd = $this->makeCurrency();
        $a = $this->makeAccount('a', $usd, 500);
        $b = $this->makeAccount('b', $usd, -250);

        $this->apply();

        $this->assertEquals(0, Account::find($a->id)->current_balance);
        $this->assertEquals(0, Account::find($b->id)->current_balance);
    }

    // ---- 9. currencies are preserved -----------------------------------------

    public function test_currencies_are_preserved(): void
    {
        $usd = $this->makeCurrency('USD');
        $eur = $this->makeCurrency('EUR', false);

        $this->apply();

        $this->assertNotNull(Currency::find($usd->id));
        $this->assertNotNull(Currency::find($eur->id));
        $this->assertTrue((bool) Currency::find($usd->id)->is_base);
    }

    // ---- 10. exchange rate history is preserved ------------------------------

    public function test_exchange_rate_history_is_preserved(): void
    {
        $eur = $this->makeCurrency('EUR', false);
        $rate = ExchangeRateHistory::create(['currency_id' => $eur->id, 'rate' => 1.1234, 'date' => '2026-01-01']);

        $this->apply();

        $this->assertNotNull(ExchangeRateHistory::find($rate->id));
        $this->assertEquals(1.1234, ExchangeRateHistory::find($rate->id)->rate);
    }

    // ---- 11. users, roles, permissions preserved -----------------------------

    public function test_users_roles_and_permissions_are_preserved(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret']);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'manage-oms', 'guard_name' => 'web']);
        $user->assignRole($role);

        $this->apply();

        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(Role::find($role->id));
        $this->assertNotNull(Permission::find($permission->id));
        $this->assertTrue(User::find($user->id)->hasRole('admin'));
    }

    // ---- 12. projects and children removed -----------------------------------

    public function test_projects_and_project_children_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $cost = $this->makeProjectCost($project, $usd);
        $budget = ProjectCostBudget::create(['project_cost_id' => $cost->id, 'original_amount' => 100, 'source_currency_id' => $usd->id, 'disbursement_currency_id' => $usd->id, 'final_amount' => 100]);
        $payment = ProjectCostBudgetsPayment::create(['project_cost_budget_id' => $budget->id, 'amount' => 50, 'currency_id' => $usd->id, 'date' => now()]);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);
        $snapshot = ProjectFinancialSnapshot::create(['project_id' => $project->id]);
        ProjectFinancialSnapshotCurrencyTotal::create(['project_id' => $project->id, 'currency_id' => $usd->id, 'currency_code' => 'USD']);
        ProjectFinancialAlert::create(['project_id' => $project->id, 'severity' => 'note', 'title' => 't']);

        $this->apply();

        $this->assertNull(Project::withTrashed()->find($project->id));
        $this->assertNull(ProjectCost::withTrashed()->find($cost->id));
        $this->assertNull(ProjectCostBudget::withTrashed()->find($budget->id));
        $this->assertNull(ProjectCostBudgetsPayment::withTrashed()->find($payment->id));
        $this->assertNull(ProjectCostReceipt::withTrashed()->find($receipt->id));
        $this->assertSame(0, ProjectFinancialSnapshot::count());
        $this->assertSame(0, ProjectFinancialSnapshotCurrencyTotal::count());
        $this->assertSame(0, ProjectFinancialAlert::count());
    }

    // ---- 13. transactions and lines removed -----------------------------------

    public function test_transactions_and_lines_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $account = $this->makeAccount('a', $usd);
        $type = $this->makeTransactionType();
        $tx = $this->makeTransaction($type);
        $line = $this->makeLine($tx, $account, $usd, 100, 0);

        $this->apply();

        $this->assertNull(Transaction::withTrashed()->find($tx->id));
        $this->assertNull(TransactionLine::withTrashed()->find($line->id));
    }

    // ---- 14. general expenses/exchanges removed --------------------------------

    public function test_general_expenses_and_exchanges_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $type = $this->makeTransactionType();
        $tx = $this->makeTransaction($type);
        $expense = GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);
        $exchange = GeneralExchange::create(['transaction_id' => $tx->id, 'original_amount' => 100, 'final_amount' => 100, 'source_currency_id' => $usd->id, 'disbursement_currency_id' => $usd->id, 'date' => now()]);

        $this->apply();

        $this->assertNull(GeneralExpense::withTrashed()->find($expense->id));
        $this->assertNull(GeneralExchange::withTrashed()->find($exchange->id));
    }

    // ---- 15. active and soft-deleted operational records removed ---------------

    public function test_active_and_soft_deleted_operational_records_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $project->delete(); // soft delete

        $account = $this->makeAccount('a', $usd);
        $type = $this->makeTransactionType();
        $tx = $this->makeTransaction($type);
        $line = $this->makeLine($tx, $account, $usd, 100, 0);
        $tx->delete();
        $line->delete();

        $this->assertSame(1, Project::onlyTrashed()->count());
        $this->assertSame(1, Transaction::onlyTrashed()->count());

        $this->apply();

        $this->assertSame(0, Project::withTrashed()->count());
        $this->assertSame(0, Transaction::withTrashed()->count());
        $this->assertSame(0, TransactionLine::withTrashed()->count());
    }

    // ---- 16. only linked operational attachments selected for deletion ---------

    public function test_only_linked_operational_attachments_are_selected_for_deletion(): void
    {
        Storage::fake('public');

        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $cost = $this->makeProjectCost($project, $usd);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);

        Storage::disk('public')->put('receipts/linked.pdf', 'linked file content');
        Attachment::create([
            'attachable_type' => ProjectCostReceipt::class,
            'attachable_id'   => $receipt->id,
            'file_name'       => 'linked.pdf',
            'file_path'       => 'receipts/linked.pdf',
        ]);

        $this->apply();

        $this->assertSame(0, Attachment::count());
        Storage::disk('public')->assertMissing('receipts/linked.pdf');
    }

    // ---- 17. unknown files are not deleted --------------------------------------

    public function test_unknown_files_are_not_deleted(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('receipts/orphan.pdf', 'no db row for this file');
        Storage::disk('public')->put('logo.png', 'application asset, not in a known attachment dir');

        $report = app(OperationalDataCleanupService::class)->audit();
        $this->assertContains('receipts/orphan.pdf', $report->attachmentPlan['orphan_files']);

        $this->apply();

        Storage::disk('public')->assertExists('receipts/orphan.pdf');
        Storage::disk('public')->assertExists('logo.png');
    }

    // ---- 18. preserved table counts remain unchanged ----------------------------

    public function test_preserved_table_counts_remain_unchanged(): void
    {
        $this->makeCurrency();
        $this->makeDonor();
        $this->makeAccount('a', $this->makeCurrency('EUR', false));
        User::create(['name' => 'Admin', 'email' => 'admin2@example.test', 'password' => 'secret']);

        $before = [
            'currencies' => Currency::count(),
            'partners'   => Partner::count(),
            'accounts'   => Account::count(),
            'users'      => User::count(),
        ];

        $this->apply();

        $this->assertSame($before['currencies'], Currency::count());
        $this->assertSame($before['partners'], Partner::count());
        $this->assertSame($before['accounts'], Account::count());
        $this->assertSame($before['users'], User::count());
    }

    // ---- 19. apply is safe when operational tables are already empty -----------

    public function test_apply_is_safe_when_operational_tables_are_already_empty(): void
    {
        $this->makeCurrency();
        $this->makeDonor();

        $report = $this->apply();

        $this->assertTrue($report->isClean());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Transaction::count());
    }

    // ---- 20. running cleanup twice is idempotent --------------------------------

    public function test_running_cleanup_twice_is_idempotent(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $this->makeProjectCost($project, $usd);
        $account = $this->makeAccount('a', $usd, 500);

        $this->apply();

        $account->refresh();
        $this->assertEquals(0, $account->current_balance);

        $secondReport = $this->apply();

        $this->assertTrue($secondReport->isClean());
        $this->assertEquals(0, $account->fresh()->current_balance);
        $this->assertSame(0, Project::count());
    }

    /**
     * Invokes the service directly (bypassing Artisan) with a fresh valid
     * backup file, so the returned report carries real post-apply
     * verification results. The command's own wiring — refusal on missing
     * confirmation/backup/wrong environment, dry-run output — is exercised
     * separately via Artisan::call() above.
     */
    private function apply(bool $skipFiles = false): \App\Services\Maintenance\OperationalCleanupReport
    {
        return app(OperationalDataCleanupService::class)->apply(
            backupFilePath: $this->backupFile(),
            confirmationToken: OperationalDataCleanupService::CONFIRMATION_TOKEN,
            skipFiles: $skipFiles,
        );
    }
}
