<?php

namespace Tests\Feature\Commands;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Attachment;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\ExchangeRateHistory;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Models\MuwakhaFamilyProject;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Reports\ProjectFinancialAlert;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\Reports\ProjectFinancialSnapshotCurrencyTotal;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Maintenance\OperationalCleanupReport;
use App\Services\Maintenance\OperationalDataCleanupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Targeted coverage for the OMS operational reset — the only tests that are
 * run for this task. Uses the same schema-only SQLite approach as
 * BackfillTransactionDescriptionsCommandTest: every real migration is applied,
 * per test, to a fresh :memory: connection, except the MySQL-only raw-SQL
 * migrations.
 *
 * Foreign keys are left OFF for most tests so a test can construct exactly the
 * rows it cares about. test_deletion_order_survives_foreign_key_enforcement
 * deliberately turns them ON, because the whole point of the deletion order is
 * that accounts can be deleted while three RESTRICT foreign keys point at it.
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
        return AccountType::create(['name' => 'نوع تجريبي ' . Str::random(5)]);
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
        return FiscalYear::create(['name' => 'سنة ' . Str::random(4), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
    }

    private function makeTransactionType(string $name = 'مصروف عام'): TransactionType
    {
        return TransactionType::create(['name' => $name]);
    }

    private function makePartnerType(string $name = 'نوع شريك'): PartnerType
    {
        return PartnerType::create(['name' => $name . ' ' . Str::random(4)]);
    }

    private function makePartner(string $name = 'شريك تجريبي', bool $isDonor = false): Partner
    {
        return Partner::create(['name' => $name, 'partner_type_id' => $this->makePartnerType()->id, 'is_donor' => $isDonor]);
    }

    private function makeDonor(string $name = 'مانح تجريبي'): Partner
    {
        return $this->makePartner($name, true);
    }

    private function makeProjectStatus(): ProjectStatus
    {
        return ProjectStatus::create(['name' => 'نشط ' . Str::random(4)]);
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

    private function makeMuwakhaFamily(Account $account): MuwakhaFamily
    {
        return MuwakhaFamily::create([
            'martyr_name'         => 'شهيد تجريبي',
            'guardian_name'       => 'ولي أمر تجريبي',
            'children_count'      => 3,
            'account_holder_name' => 'صاحب الحساب',
            'account_id'          => $account->id,
        ]);
    }

    private function makeSetting(string $key, string $value = 'v'): Setting
    {
        return Setting::create(['key' => $key, 'value' => $value, 'group' => 'general']);
    }

    private function insertAuditEvent(): int
    {
        return DB::table('audit_events')->insertGetId([
            'uuid'           => (string) Str::uuid(),
            'event_category' => 'maintenance',
            'event_action'   => 'test.event',
            'actor_type'     => 'system',
            'status'         => 'succeeded',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function insertBackupOperation(): int
    {
        return DB::table('backup_operations')->insertGetId([
            'uuid'       => (string) Str::uuid(),
            'type'       => 'manual',
            'scope'      => 'full',
            'status'     => 'completed',
            'disk'       => 'backups',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertNotification(User $user): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => 'App\Notifications\BackupOperationNotification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id'   => $user->id,
            'data'            => json_encode(['backup' => 'ok']),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $id;
    }

    private function makeUser(string $email): User
    {
        return User::create(['name' => 'User ' . Str::random(4), 'email' => $email, 'password' => 'secret']);
    }

    /**
     * Invokes the service directly (bypassing Artisan) with a fresh valid
     * backup file, so the returned report carries real post-apply
     * verification results. The command's own wiring — refusal on missing
     * confirmation/backup/wrong environment, dry-run output, the interactive
     * prompt — is exercised separately via Artisan::call().
     */
    private function apply(bool $skipFiles = false): OperationalCleanupReport
    {
        return app(OperationalDataCleanupService::class)->apply(
            backupFilePath: $this->backupFile(),
            confirmationToken: OperationalDataCleanupService::CONFIRMATION_TOKEN,
            skipFiles: $skipFiles,
        );
    }

    // ---- guards ----------------------------------------------------------

    public function test_dry_run_makes_no_database_changes(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject(donor: $this->makeDonor());
        $this->makeProjectCost($project, $usd);
        $account = $this->makeAccount('حساب', $usd, 500);
        $tx = $this->makeTransaction($this->makeTransactionType());
        $this->makeLine($tx, $account, $usd, 100, 0);
        $this->makeMuwakhaFamily($account);

        $before = [
            'projects'     => Project::withTrashed()->count(),
            'transactions' => Transaction::withTrashed()->count(),
            'lines'        => TransactionLine::withTrashed()->count(),
            'accounts'     => Account::withTrashed()->count(),
            'partners'     => Partner::withTrashed()->count(),
            'families'     => MuwakhaFamily::withTrashed()->count(),
        ];

        Artisan::call('oms:clean-operational-data', ['--dry-run' => true]);

        $this->assertSame($before['projects'], Project::withTrashed()->count());
        $this->assertSame($before['transactions'], Transaction::withTrashed()->count());
        $this->assertSame($before['lines'], TransactionLine::withTrashed()->count());
        $this->assertSame($before['accounts'], Account::withTrashed()->count());
        $this->assertSame($before['partners'], Partner::withTrashed()->count());
        $this->assertSame($before['families'], MuwakhaFamily::withTrashed()->count());
        $this->assertStringContainsString('Dry run performed zero database writes', Artisan::output());
    }

    public function test_refuses_production_environment(): void
    {
        config(['app.env' => 'production']);

        $exit = Artisan::call('oms:clean-operational-data', ['--dry-run' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("environment 'production'", Artisan::output());
    }

    public function test_refuses_apply_in_production_even_with_token_and_backup(): void
    {
        config(['app.env' => 'production']);

        $exit = Artisan::call('oms:clean-operational-data', [
            '--apply'        => true,
            '--confirmation' => OperationalDataCleanupService::CONFIRMATION_TOKEN,
            '--backup-file'  => $this->backupFile(),
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("environment 'production'", Artisan::output());
    }

    public function test_refuses_apply_without_confirmation_token(): void
    {
        $exit = Artisan::call('oms:clean-operational-data', [
            '--apply'        => true,
            '--confirmation' => 'WRONG-TOKEN',
            '--backup-file'  => $this->backupFile(),
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('confirmation token does not match', Artisan::output());
    }

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
        $this->assertSame(0, Account::count());
    }

    // ---- 1. operational project data is deleted ---------------------------

    public function test_projects_and_project_children_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $cost = $this->makeProjectCost($project, $usd);
        $budget = ProjectCostBudget::create(['project_cost_id' => $cost->id, 'original_amount' => 100, 'source_currency_id' => $usd->id, 'disbursement_currency_id' => $usd->id, 'final_amount' => 100]);
        $payment = ProjectCostBudgetsPayment::create(['project_cost_budget_id' => $budget->id, 'amount' => 50, 'currency_id' => $usd->id, 'date' => now()]);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);

        $this->apply();

        $this->assertNull(Project::withTrashed()->find($project->id));
        $this->assertNull(ProjectCost::withTrashed()->find($cost->id));
        $this->assertNull(ProjectCostBudget::withTrashed()->find($budget->id));
        $this->assertNull(ProjectCostBudgetsPayment::withTrashed()->find($payment->id));
        $this->assertNull(ProjectCostReceipt::withTrashed()->find($receipt->id));
        $this->assertSame(0, Project::withTrashed()->count());
        $this->assertSame(0, ProjectCost::withTrashed()->count());
    }

    // ---- 2. financial transactions and transaction_lines are deleted ------

    public function test_transactions_and_lines_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $account = $this->makeAccount('a', $usd);
        $tx = $this->makeTransaction($this->makeTransactionType());
        $line = $this->makeLine($tx, $account, $usd, 100, 0);

        $this->apply();

        $this->assertNull(Transaction::withTrashed()->find($tx->id));
        $this->assertNull(TransactionLine::withTrashed()->find($line->id));
        $this->assertSame(0, Transaction::withTrashed()->count());
        $this->assertSame(0, TransactionLine::withTrashed()->count());
    }

    public function test_general_expenses_and_exchanges_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $tx = $this->makeTransaction($this->makeTransactionType());
        $expense = GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);
        $exchange = GeneralExchange::create(['transaction_id' => $tx->id, 'original_amount' => 100, 'final_amount' => 100, 'source_currency_id' => $usd->id, 'disbursement_currency_id' => $usd->id, 'date' => now()]);

        $this->apply();

        $this->assertNull(GeneralExpense::withTrashed()->find($expense->id));
        $this->assertNull(GeneralExchange::withTrashed()->find($exchange->id));
    }

    // ---- 3. partners are deleted (CONTRACT CHANGE: donors too) ------------

    public function test_partners_including_donors_are_removed(): void
    {
        $donor = $this->makeDonor('جمعية الخير');
        $vendor = $this->makePartner('مورد', false);
        $project = $this->makeProject(donor: $donor);
        $this->makeProjectCost($project, $this->makeCurrency());

        $this->apply();

        $this->assertNull(Partner::withTrashed()->find($donor->id));
        $this->assertNull(Partner::withTrashed()->find($vendor->id));
        $this->assertSame(0, Partner::withTrashed()->count());
    }

    public function test_partner_types_lookup_survives_partner_deletion(): void
    {
        $partner = $this->makePartner();
        $typeId = $partner->partner_type_id;

        $this->apply();

        $this->assertSame(0, Partner::withTrashed()->count());
        $this->assertNotNull(PartnerType::withTrashed()->find($typeId));
    }

    // ---- 4. accounts are deleted (CONTRACT CHANGE) ------------------------

    public function test_accounts_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $account = $this->makeAccount('حساب البنك', $usd, 750);
        $second = $this->makeAccount('حساب ثاني', $usd, -250);

        $this->apply();

        $this->assertNull(Account::withTrashed()->find($account->id));
        $this->assertNull(Account::withTrashed()->find($second->id));
        $this->assertSame(0, Account::withTrashed()->count());
    }

    public function test_account_lookups_survive_account_deletion(): void
    {
        $usd = $this->makeCurrency();
        $bankType = BankType::create(['name' => 'بنك تجريبي']);
        $account = $this->makeAccount('حساب', $usd);
        $accountTypeId = $account->account_type_id;

        $this->apply();

        $this->assertSame(0, Account::withTrashed()->count());
        $this->assertNotNull(AccountType::withTrashed()->find($accountTypeId));
        $this->assertNotNull(BankType::withTrashed()->find($bankType->id));
        $this->assertNotNull(Currency::withTrashed()->find($usd->id));
    }

    // ---- 5. Muwakha families / accounts / project links are deleted -------

    public function test_muwakha_families_accounts_and_project_links_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $account = $this->makeAccount('حساب الأسرة', $usd);
        $family = $this->makeMuwakhaFamily($account);
        $link = MuwakhaFamilyAccount::create([
            'muwakha_family_id'   => $family->id,
            'account_id'          => $account->id,
            'account_holder_name' => 'صاحب الحساب',
        ]);
        $project = $this->makeProject();
        $projectLink = MuwakhaFamilyProject::create([
            'muwakha_family_id' => $family->id,
            'project_id'        => $project->id,
            'card_code'         => 'CARD-1',
        ]);

        $this->apply();

        $this->assertNull(MuwakhaFamily::withTrashed()->find($family->id));
        $this->assertNull(MuwakhaFamilyAccount::find($link->id));
        $this->assertNull(MuwakhaFamilyProject::find($projectLink->id));
        $this->assertSame(0, MuwakhaFamily::withTrashed()->count());
        $this->assertSame(0, MuwakhaFamilyAccount::count());
        $this->assertSame(0, MuwakhaFamilyProject::count());
    }

    /**
     * The deletion order exists precisely because accounts is the target of
     * three RESTRICT foreign keys. With enforcement ON, a wrong order raises
     * a constraint violation instead of silently passing.
     */
    public function test_deletion_order_survives_foreign_key_enforcement(): void
    {
        $usd = $this->makeCurrency();
        $account = $this->makeAccount('حساب', $usd);
        $family = $this->makeMuwakhaFamily($account);
        MuwakhaFamilyAccount::create([
            'muwakha_family_id'   => $family->id,
            'account_id'          => $account->id,
            'account_holder_name' => 'صاحب الحساب',
        ]);
        $project = $this->makeProject();
        MuwakhaFamilyProject::create(['muwakha_family_id' => $family->id, 'project_id' => $project->id, 'card_code' => 'C1']);
        $tx = $this->makeTransaction($this->makeTransactionType());
        $this->makeLine($tx, $account, $usd, 100, 0);

        DB::statement('PRAGMA foreign_keys = ON');

        $report = $this->apply();

        $this->assertTrue($report->isClean(), implode(' | ', $report->verificationFailures));
        $this->assertSame(0, Account::withTrashed()->count());
        $this->assertSame(0, MuwakhaFamily::withTrashed()->count());
        $this->assertSame(0, TransactionLine::withTrashed()->count());
    }

    // ---- 6. report snapshots and alerts are deleted -----------------------

    public function test_report_snapshots_and_alerts_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        ProjectFinancialSnapshot::create(['project_id' => $project->id]);
        ProjectFinancialSnapshotCurrencyTotal::create(['project_id' => $project->id, 'currency_id' => $usd->id, 'currency_code' => 'USD']);
        ProjectFinancialAlert::create(['project_id' => $project->id, 'severity' => 'note', 'title' => 't']);

        $this->apply();

        $this->assertSame(0, ProjectFinancialSnapshot::count());
        $this->assertSame(0, ProjectFinancialSnapshotCurrencyTotal::count());
        $this->assertSame(0, ProjectFinancialAlert::count());
    }

    // ---- 7 + 13. settings / lookups remain, legitimate settings remain ----

    public function test_settings_and_lookup_tables_are_preserved(): void
    {
        $this->makeSetting('organization_name', 'جمعية غياث');
        $this->makeSetting('base_currency', 'USD');
        $usd = $this->makeCurrency();
        $fiscalYear = $this->makeFiscalYear();
        $accountType = $this->makeAccountType();
        $bankType = BankType::create(['name' => 'بنك']);
        $partnerType = $this->makePartnerType();
        $projectStatus = $this->makeProjectStatus();
        $projectSuper = ProjectSuper::create(['name' => 'مشروع رئيسي', 'code_prefix' => 'SUP']);
        $superType = TransactionSuperType::create(['name' => 'نوع عام']);
        $txType = $this->makeTransactionType();

        $before = [
            'settings'               => DB::table('settings')->count(),
            'currencies'             => Currency::withTrashed()->count(),
            'fiscal_years'           => FiscalYear::withTrashed()->count(),
            'accounts_type'          => AccountType::withTrashed()->count(),
            'bank_types'             => BankType::withTrashed()->count(),
            'partners_types'         => PartnerType::withTrashed()->count(),
            'projects_status'        => ProjectStatus::withTrashed()->count(),
            'projects_super'         => ProjectSuper::withTrashed()->count(),
            'transaction_super_types' => TransactionSuperType::withTrashed()->count(),
            'transactions_types'     => TransactionType::withTrashed()->count(),
        ];

        $this->apply();

        $this->assertSame($before['settings'], DB::table('settings')->count());
        $this->assertSame($before['currencies'], Currency::withTrashed()->count());
        $this->assertSame($before['fiscal_years'], FiscalYear::withTrashed()->count());
        $this->assertSame($before['accounts_type'], AccountType::withTrashed()->count());
        $this->assertSame($before['bank_types'], BankType::withTrashed()->count());
        $this->assertSame($before['partners_types'], PartnerType::withTrashed()->count());
        $this->assertSame($before['projects_status'], ProjectStatus::withTrashed()->count());
        $this->assertSame($before['projects_super'], ProjectSuper::withTrashed()->count());
        $this->assertSame($before['transaction_super_types'], TransactionSuperType::withTrashed()->count());
        $this->assertSame($before['transactions_types'], TransactionType::withTrashed()->count());

        $this->assertSame('جمعية غياث', DB::table('settings')->where('key', 'organization_name')->value('value'));
        $this->assertNotNull(Currency::withTrashed()->find($usd->id));
        $this->assertNotNull(FiscalYear::withTrashed()->find($fiscalYear->id));
        $this->assertNotNull(AccountType::withTrashed()->find($accountType->id));
        $this->assertNotNull(BankType::withTrashed()->find($bankType->id));
        $this->assertNotNull(PartnerType::withTrashed()->find($partnerType->id));
        $this->assertNotNull(ProjectStatus::withTrashed()->find($projectStatus->id));
        $this->assertNotNull(ProjectSuper::withTrashed()->find($projectSuper->id));
        $this->assertNotNull(TransactionSuperType::withTrashed()->find($superType->id));
        $this->assertNotNull(TransactionType::withTrashed()->find($txType->id));
    }

    public function test_exchange_rate_history_is_preserved(): void
    {
        $eur = $this->makeCurrency('EUR', false);
        $rate = ExchangeRateHistory::create(['currency_id' => $eur->id, 'rate' => 1.1234, 'date' => '2026-01-01']);

        $this->apply();

        $this->assertNotNull(ExchangeRateHistory::withTrashed()->find($rate->id));
        $this->assertEquals(1.1234, ExchangeRateHistory::withTrashed()->find($rate->id)->rate);
    }

    // ---- 8 + 9 + 16. users, roles, permissions, valid assignments remain --

    public function test_users_roles_and_permissions_are_preserved(): void
    {
        $user = $this->makeUser('admin@example.test');
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'manage-oms', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        $before = [
            'users'                 => User::withTrashed()->count(),
            'roles'                 => Role::count(),
            'permissions'           => Permission::count(),
            'role_has_permissions'  => DB::table('role_has_permissions')->count(),
            'model_has_roles'       => DB::table('model_has_roles')->count(),
            'model_has_permissions' => DB::table('model_has_permissions')->count(),
        ];

        $this->apply();

        $this->assertSame($before['users'], User::withTrashed()->count());
        $this->assertSame($before['roles'], Role::count());
        $this->assertSame($before['permissions'], Permission::count());
        $this->assertSame($before['role_has_permissions'], DB::table('role_has_permissions')->count());
        $this->assertSame($before['model_has_roles'], DB::table('model_has_roles')->count());
        $this->assertSame($before['model_has_permissions'], DB::table('model_has_permissions')->count());

        $fresh = User::find($user->id);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->hasRole('admin'));
        $this->assertTrue($fresh->hasPermissionTo('manage-oms'));
    }

    // ---- 10. audit_events remain -----------------------------------------

    public function test_audit_events_are_preserved(): void
    {
        $first = $this->insertAuditEvent();
        $second = $this->insertAuditEvent();

        $this->apply();

        $this->assertSame(2, DB::table('audit_events')->count());
        $this->assertNotNull(DB::table('audit_events')->find($first));
        $this->assertNotNull(DB::table('audit_events')->find($second));
    }

    // ---- 11. backup_operations remain -------------------------------------

    public function test_backup_operations_are_preserved(): void
    {
        $id = $this->insertBackupOperation();

        $this->apply();

        $this->assertSame(1, DB::table('backup_operations')->count());
        $this->assertNotNull(DB::table('backup_operations')->find($id));
    }

    public function test_backup_notifications_are_preserved(): void
    {
        $user = $this->makeUser('notify@example.test');
        $id = $this->insertNotification($user);

        $this->apply();

        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->first());
    }

    // ---- 12. migrations remain --------------------------------------------

    public function test_migration_history_is_preserved(): void
    {
        $before = DB::table('migrations')->count();
        $this->assertGreaterThan(0, $before);

        $this->apply();

        $this->assertSame($before, DB::table('migrations')->count());
    }

    // ---- 14. restore_e2e_20260727_* markers are removed -------------------

    public function test_restore_e2e_marker_settings_are_removed_and_legitimate_settings_remain(): void
    {
        $this->makeSetting('organization_name', 'جمعية غياث');
        $this->makeSetting('base_currency', 'USD');
        $this->makeSetting('fiscal_year_start', '01-01');
        $this->makeSetting('timezone', 'Asia/Gaza');
        $this->makeSetting('date_format', 'Y-m-d');
        $this->makeSetting('restore_e2e_20260727_7c9a_db_marker');
        $this->makeSetting('restore_e2e_20260727_7c9a_files_control_marker');
        $this->makeSetting('restore_e2e_20260727_7c9a_full_db_marker');

        $this->assertSame(8, DB::table('settings')->count());

        $report = $this->apply();

        $this->assertSame(5, DB::table('settings')->count());
        $this->assertCount(3, $report->specialCleanupResult['removed_test_settings']);

        foreach (['organization_name', 'base_currency', 'fiscal_year_start', 'timezone', 'date_format'] as $key) {
            $this->assertNotNull(DB::table('settings')->where('key', $key)->first(), "Legitimate setting '{$key}' was removed.");
        }

        $this->assertSame(0, DB::table('settings')->where('key', 'like', 'restore_e2e_20260727_%')->count());
    }

    public function test_settings_with_similar_but_non_matching_keys_are_not_removed(): void
    {
        $this->makeSetting('restore_e2e_20260728_other_marker');
        $this->makeSetting('restore_e2e_marker');
        $this->makeSetting('my_restore_e2e_20260727_marker');
        $this->makeSetting('restore_e2e_20260727_removed');

        $report = $this->apply();

        $this->assertSame(['restore_e2e_20260727_removed'], $report->specialCleanupResult['removed_test_settings']);
        $this->assertSame(3, DB::table('settings')->count());
        $this->assertNotNull(DB::table('settings')->where('key', 'restore_e2e_20260728_other_marker')->first());
        $this->assertNotNull(DB::table('settings')->where('key', 'restore_e2e_marker')->first());
        $this->assertNotNull(DB::table('settings')->where('key', 'my_restore_e2e_20260727_marker')->first());
    }

    public function test_soft_deleted_marker_settings_are_also_removed(): void
    {
        $marker = $this->makeSetting('restore_e2e_20260727_trashed_marker');
        $marker->delete();

        $this->assertSame(1, DB::table('settings')->count());

        $this->apply();

        $this->assertSame(0, DB::table('settings')->count());
    }

    // ---- 15 + 16. orphan assignments removed, valid ones kept -------------

    public function test_orphaned_model_has_roles_rows_are_removed(): void
    {
        $keptUser = $this->makeUser('kept@example.test');
        $doomedUser = $this->makeUser('doomed@example.test');
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $otherRole = Role::create(['name' => 'viewer', 'guard_name' => 'web']);

        $keptUser->assignRole($role);
        $doomedUser->assignRole($otherRole);

        // Hard-delete the user row (bypassing SoftDeletes) to create exactly
        // the orphan shape found in the live database.
        DB::table('users')->where('id', $doomedUser->id)->delete();

        $this->assertSame(2, DB::table('model_has_roles')->count());

        $report = $this->apply();

        $this->assertCount(1, $report->specialCleanupResult['removed_orphan_model_has_roles']);
        $this->assertSame(1, DB::table('model_has_roles')->count());

        $remaining = DB::table('model_has_roles')->first();
        $this->assertSame($keptUser->id, (int) $remaining->model_id);
        $this->assertSame($role->id, (int) $remaining->role_id);

        // Roles themselves are untouched.
        $this->assertNotNull(Role::find($role->id));
        $this->assertNotNull(Role::find($otherRole->id));
    }

    public function test_orphaned_model_has_permissions_rows_are_removed(): void
    {
        $keptUser = $this->makeUser('kept2@example.test');
        $doomedUser = $this->makeUser('doomed2@example.test');
        $permission = Permission::create(['name' => 'manage-oms', 'guard_name' => 'web']);

        $keptUser->givePermissionTo($permission);
        $doomedUser->givePermissionTo($permission);

        DB::table('users')->where('id', $doomedUser->id)->delete();

        $this->assertSame(2, DB::table('model_has_permissions')->count());

        $report = $this->apply();

        $this->assertCount(1, $report->specialCleanupResult['removed_orphan_model_has_permissions']);
        $this->assertSame(1, DB::table('model_has_permissions')->count());
        $this->assertSame($keptUser->id, (int) DB::table('model_has_permissions')->first()->model_id);
        $this->assertNotNull(Permission::find($permission->id));
    }

    public function test_soft_deleted_user_keeps_its_role_assignment(): void
    {
        $user = $this->makeUser('trashed@example.test');
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole($role);

        $user->delete(); // soft delete — the users row still exists

        $report = $this->apply();

        $this->assertSame([], $report->specialCleanupResult['removed_orphan_model_has_roles']);
        $this->assertSame(1, DB::table('model_has_roles')->count());
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    // ---- soft-deleted operational rows ------------------------------------

    public function test_active_and_soft_deleted_operational_records_are_removed(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $project->delete();

        $account = $this->makeAccount('a', $usd);
        $account->delete();

        $partner = $this->makePartner();
        $partner->delete();

        $family = $this->makeMuwakhaFamily($this->makeAccount('b', $usd));
        $family->delete();

        $tx = $this->makeTransaction($this->makeTransactionType());
        $line = $this->makeLine($tx, $account, $usd, 100, 0);
        $tx->delete();
        $line->delete();

        $this->assertSame(1, Project::onlyTrashed()->count());
        $this->assertSame(1, Account::onlyTrashed()->count());
        $this->assertSame(1, Partner::onlyTrashed()->count());
        $this->assertSame(1, MuwakhaFamily::onlyTrashed()->count());

        $this->apply();

        $this->assertSame(0, Project::withTrashed()->count());
        $this->assertSame(0, Account::withTrashed()->count());
        $this->assertSame(0, Partner::withTrashed()->count());
        $this->assertSame(0, MuwakhaFamily::withTrashed()->count());
        $this->assertSame(0, Transaction::withTrashed()->count());
        $this->assertSame(0, TransactionLine::withTrashed()->count());
    }

    // ---- attachments ------------------------------------------------------

    public function test_linked_attachment_on_backup_covered_disk_is_deleted(): void
    {
        Storage::fake('attachments');

        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $cost = $this->makeProjectCost($project, $usd);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);

        Storage::disk('attachments')->put('receipts/linked.pdf', 'linked file content');
        Attachment::create([
            'attachable_type' => ProjectCostReceipt::class,
            'attachable_id'   => $receipt->id,
            'file_name'       => 'linked.pdf',
            'file_path'       => 'receipts/linked.pdf',
            'disk'            => Attachment::DISK_ATTACHMENTS,
        ]);

        $this->apply();

        $this->assertSame(0, Attachment::withTrashed()->count());
        Storage::disk('attachments')->assertMissing('receipts/linked.pdf');
    }

    public function test_apply_refuses_when_linked_file_is_not_covered_by_backup(): void
    {
        Storage::fake('public');

        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $cost = $this->makeProjectCost($project, $usd);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);

        Storage::disk('public')->put('receipts/public.pdf', 'not in the backup archive');
        Attachment::create([
            'attachable_type' => ProjectCostReceipt::class,
            'attachable_id'   => $receipt->id,
            'file_name'       => 'public.pdf',
            'file_path'       => 'receipts/public.pdf',
            'disk'            => Attachment::DISK_PUBLIC,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not contain');

        try {
            $this->apply();
        } finally {
            // Nothing was deleted — the guard runs before the transaction.
            $this->assertSame(1, Project::withTrashed()->count());
            $this->assertSame(1, Attachment::withTrashed()->count());
            Storage::disk('public')->assertExists('receipts/public.pdf');
        }
    }

    public function test_skip_files_cleans_database_and_leaves_uncovered_files_on_disk(): void
    {
        Storage::fake('public');

        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $cost = $this->makeProjectCost($project, $usd);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);

        Storage::disk('public')->put('receipts/public.pdf', 'kept on disk');
        Attachment::create([
            'attachable_type' => ProjectCostReceipt::class,
            'attachable_id'   => $receipt->id,
            'file_name'       => 'public.pdf',
            'file_path'       => 'receipts/public.pdf',
            'disk'            => Attachment::DISK_PUBLIC,
        ]);

        $report = $this->apply(skipFiles: true);

        $this->assertTrue($report->isClean(), implode(' | ', $report->verificationFailures));
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame(0, Project::withTrashed()->count());
        $this->assertTrue($report->attachmentPlan['fileDeletion']['skipped']);
        Storage::disk('public')->assertExists('receipts/public.pdf');
    }

    public function test_unknown_files_are_never_deleted(): void
    {
        Storage::fake('public');
        Storage::fake('attachments');

        Storage::disk('public')->put('receipts/orphan.pdf', 'no db row for this file');
        Storage::disk('public')->put('logo.png', 'application asset, not in a known attachment dir');

        $report = app(OperationalDataCleanupService::class)->audit();
        $orphanPaths = array_column($report->attachmentPlan['orphan_files']['files'], 'path');
        $this->assertContains('receipts/orphan.pdf', $orphanPaths);
        $this->assertNotContains('logo.png', $orphanPaths);

        $this->apply();

        Storage::disk('public')->assertExists('receipts/orphan.pdf');
        Storage::disk('public')->assertExists('logo.png');
    }

    // ---- whole-contract assertions ----------------------------------------

    public function test_every_operational_table_ends_empty_and_every_preserved_table_survives(): void
    {
        $usd = $this->makeCurrency();
        $account = $this->makeAccount('a', $usd, 500);
        $donor = $this->makeDonor();
        $project = $this->makeProject(donor: $donor);
        $cost = $this->makeProjectCost($project, $usd);
        $budget = ProjectCostBudget::create(['project_cost_id' => $cost->id, 'original_amount' => 100, 'source_currency_id' => $usd->id, 'disbursement_currency_id' => $usd->id, 'final_amount' => 100]);
        ProjectCostBudgetsPayment::create(['project_cost_budget_id' => $budget->id, 'amount' => 50, 'currency_id' => $usd->id, 'date' => now()]);
        ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);
        $tx = $this->makeTransaction($this->makeTransactionType());
        $this->makeLine($tx, $account, $usd, 100, 0);
        GeneralExpense::create(['transaction_id' => $tx->id, 'amount' => 100, 'currency_id' => $usd->id, 'date' => now()]);
        GeneralExchange::create(['transaction_id' => $tx->id, 'original_amount' => 100, 'final_amount' => 100, 'source_currency_id' => $usd->id, 'disbursement_currency_id' => $usd->id, 'date' => now()]);
        $family = $this->makeMuwakhaFamily($account);
        MuwakhaFamilyAccount::create(['muwakha_family_id' => $family->id, 'account_id' => $account->id, 'account_holder_name' => 'x']);
        MuwakhaFamilyProject::create(['muwakha_family_id' => $family->id, 'project_id' => $project->id, 'card_code' => 'C1']);
        ProjectFinancialSnapshot::create(['project_id' => $project->id]);
        ProjectFinancialSnapshotCurrencyTotal::create(['project_id' => $project->id, 'currency_id' => $usd->id, 'currency_code' => 'USD']);
        ProjectFinancialAlert::create(['project_id' => $project->id, 'severity' => 'note', 'title' => 't']);

        $this->makeUser('contract@example.test');
        $this->insertAuditEvent();
        $this->insertBackupOperation();
        $this->makeSetting('organization_name');

        $report = $this->apply();

        $this->assertTrue($report->isClean(), implode(' | ', $report->verificationFailures));

        foreach ($report->operationalCounts as $table => $counts) {
            $this->assertSame(0, $counts['active'], "Operational table {$table} still has active rows.");
            $this->assertSame(0, $counts['trashed'], "Operational table {$table} still has trashed rows.");
        }

        foreach (['users', 'settings', 'currencies', 'migrations', 'audit_events', 'backup_operations'] as $table) {
            $total = $report->preservedCounts[$table]['active'] + $report->preservedCounts[$table]['trashed'];
            $this->assertGreaterThan(0, $total, "Preserved table {$table} unexpectedly ended empty.");
        }
    }

    public function test_apply_is_safe_when_operational_tables_are_already_empty(): void
    {
        $this->makeCurrency();
        $this->makeSetting('organization_name');

        $report = $this->apply();

        $this->assertTrue($report->isClean(), implode(' | ', $report->verificationFailures));
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Account::count());
        $this->assertSame(0, Partner::count());
    }

    public function test_running_cleanup_twice_is_idempotent(): void
    {
        $usd = $this->makeCurrency();
        $project = $this->makeProject();
        $this->makeProjectCost($project, $usd);
        $this->makeAccount('a', $usd, 500);
        $this->makePartner();
        $this->makeSetting('restore_e2e_20260727_marker');

        $first = $this->apply();
        $this->assertTrue($first->isClean(), implode(' | ', $first->verificationFailures));
        $this->assertCount(1, $first->specialCleanupResult['removed_test_settings']);

        $second = $this->apply();

        $this->assertTrue($second->isClean(), implode(' | ', $second->verificationFailures));
        $this->assertSame([], $second->specialCleanupResult['removed_test_settings']);
        $this->assertSame(0, Project::withTrashed()->count());
        $this->assertSame(0, Account::withTrashed()->count());
        $this->assertSame(0, Partner::withTrashed()->count());
    }
}
