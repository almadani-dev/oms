<?php

namespace Tests\Feature\Audit\Financial;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditEvent;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared setup for the OMS Task 9B.3 financial audit integration tests.
 *
 * Same schema-only in-memory SQLite approach the existing financial
 * integration suites already use (Tests\Feature\ProjectCostReceipts\
 * BalanceGuardIntegrationTest and friends) — every real migration except the
 * two pre-existing MySQL-only ones, foreign key enforcement off, and the real
 * Filament page methods driven directly through reflection, exactly as those
 * suites do. Driving the real handleRecordCreation()/handleRecordUpdate() and
 * the real static delete methods is the point: these tests must prove the
 * audit wiring on the ACTUAL write paths, not on a re-implementation of them.
 */
abstract class FinancialAuditTestCase extends TestCase
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

        $this->actingAs($this->superAdmin());
    }

    protected function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    /**
     * Every fixture the five workflows need, including a second currency so
     * the two multi-currency workflows can be exercised with a real FX rate.
     *
     * @return array<string, mixed>
     */
    protected function fixture(): array
    {
        $currency = Currency::create(['name' => 'دولار', 'code' => 'USD', 'symbol' => '$']);
        $altCurrency = Currency::create(['name' => 'شيكل', 'code' => 'ILS', 'symbol' => '₪']);
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType = BankType::create(['name' => 'نوع بنك']);

        $makeAccount = fn (string $name, float $balance = 0, ?Currency $on = null) => Account::create([
            'account_code' => $name,
            'name' => $name,
            'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id,
            'currency_id' => ($on ?? $currency)->id,
            'current_balance' => $balance,
            'is_active' => true,
        ]);

        $fiscalYear = FiscalYear::create([
            'name' => 'سنة 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);

        $partnerType = PartnerType::create(['name' => 'نوع شريك']);

        $super = ProjectSuper::create(['name' => 'برنامج التعليم', 'code_prefix' => 'EDU']);
        $status = ProjectStatus::create(['name' => 'قيد التنفيذ']);
        $project = Project::create([
            'name' => 'مشروع تجريبي',
            'code' => 'EDU-001',
            'project_super_id' => $super->id,
            'project_status_id' => $status->id,
        ]);

        return [
            'currency' => $currency,
            'altCurrency' => $altCurrency,
            'accountType' => $accountType,
            'bankType' => $bankType,
            'debitAccount' => $makeAccount('مدين'),
            'creditAccount' => $makeAccount('دائن', 50000),
            'altDebitAccount' => $makeAccount('مدين بديل'),
            'sourceAccount' => $makeAccount('مصدر', 50000),
            'adminAccount' => $makeAccount('إداري'),
            'transferAccount' => $makeAccount('تحويل'),
            'destinationAccount' => $makeAccount('وجهة'),
            'altDestinationAccount' => $makeAccount('وجهة بعملة أخرى', 0, $altCurrency),
            'beneficiaryAccount' => $makeAccount('مستفيد'),
            'fiscalYear' => $fiscalYear,
            'transactionType' => TransactionType::create(['name' => 'نوع معاملة']),
            'partner' => Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]),
            'altPartner' => Partner::create(['name' => 'شريك آخر', 'partner_type_id' => $partnerType->id]),
            'project' => $project,
            'projectCost' => ProjectCost::create([
                'project_id' => $project->id,
                'account_type_id' => $accountType->id,
                'amount' => 100000,
                'currency_id' => $currency->id,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function invoke(object $page, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($page, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($page, array_values($arguments));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditEvent>
     */
    protected function eventsFor(string $subjectType)
    {
        return AuditEvent::where('subject_type', $subjectType)->orderBy('id')->get();
    }

    protected function onlyEventFor(string $subjectType, string $action): AuditEvent
    {
        $events = AuditEvent::where('subject_type', $subjectType)
            ->where('event_action', $action)
            ->get();

        $this->assertCount(
            1,
            $events,
            sprintf('Expected exactly one %s.%s event, found %d.', $subjectType, $action, $events->count()),
        );

        return $events->first();
    }

    /**
     * @return array<string, float>
     */
    protected function balances(Account ...$accounts): array
    {
        $balances = [];

        foreach ($accounts as $account) {
            $balances[(string) $account->id] = (float) $account->fresh()->current_balance;
        }

        return $balances;
    }
}
