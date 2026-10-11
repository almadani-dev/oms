<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\AccountStatementPage;
use App\Jobs\BuildAccountStatementExcelExportJob;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditEvent;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatement\AccountStatementExportPlan;
use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementProjection;
use App\Services\Reports\AccountStatement\AccountStatementProjectionRebuilder;
use App\Services\Reports\AccountStatement\AccountStatementProjectionState;
use App\Services\Reports\AccountStatement\AccountStatementProjectionVerifier;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementRows;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use App\Services\Reports\AccountStatementExcelExportService;
use App\Services\Reports\AccountStatementReportService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * "تقرير كشف الحساب" over its real-time projection.
 *
 * Every figure is checked against an ORACLE computed here in plain PHP from
 * the raw ledger rows (never from the projection): rows, canonical order,
 * per-account running balances (the account's ACTUAL balance, dimension
 * filters only hiding rows), opening/closing balances, per-account and
 * per-currency totals. Single-account statements are also checked against
 * the original AccountStatementReportService (left unchanged as the legacy
 * reference), and multi-account statements against single-account ones.
 */
class AccountStatementReportTest extends TestCase
{
    use IntegrityTestFixtures;

    /** @var array<string, mixed> */
    private array $fx = [];

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('report_snapshots');

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'as-test-'.uniqid();
        config(['oms.reports.comprehensive_financial_transactions.export_temp_dir' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // =========================================================
    // fixture ledger
    // =========================================================

    /**
     * Five accounts in two currencies and two account types, three types
     * (two classifications), two projects (one cost item soft-deleted),
     * ~260 transactions over Nov 2025 – Mar 2026 with deliberate timestamp
     * ties (several transactions on exactly the same second, inside and
     * across page boundaries), a soft-deleted transaction, a soft-deleted
     * line and one line in a foreign currency (mixed-currency warning).
     */
    private function seedLedger(): array
    {
        $usd = Currency::create(['name' => 'دولار', 'code' => 'USD', 'symbol' => '$']);
        $ils = Currency::create(['name' => 'شيكل', 'code' => 'ILS', 'symbol' => '₪']);
        $bankType = AccountType::create(['name' => 'بنوك']);
        $boxType = AccountType::create(['name' => 'صناديق']);
        $bank = BankType::create(['name' => 'بنك']);

        $account = fn (string $code, Currency $currency, AccountType $type) => Account::create([
            'account_code' => $code,
            'name' => 'حساب '.$code,
            'account_type_id' => $type->id,
            'bank_type_id' => $bank->id,
            'currency_id' => $currency->id,
            'current_balance' => 0,
            'is_active' => true,
            'iban' => 'PS00 '.$code,
        ]);

        $a = $account('A-100', $usd, $bankType);
        $b = $account('B-200', $usd, $bankType);
        $c = $account('C-300', $ils, $boxType);
        $d = $account('D-400', $usd, $boxType);
        $e = $account('E-500', $usd, $bankType);

        $income = TransactionSuperType::create(['name' => 'إيرادات']);
        $spend = TransactionSuperType::create(['name' => 'مصروفات']);
        $receipt = TransactionType::create(['name' => 'استلام', 'transaction_super_type_id' => $income->id]);
        $expense = TransactionType::create(['name' => 'صرف', 'transaction_super_type_id' => $spend->id]);
        $transfer = TransactionType::create(['name' => 'تحويل']);

        $status = ProjectStatus::create(['name' => 'نشط']);
        $super = ProjectSuper::create(['name' => 'برامج']);
        $p1 = Project::create(['name' => 'مشروع أول', 'code' => 'P-1', 'project_status_id' => $status->id, 'project_super_id' => $super->id]);
        $p2 = Project::create(['name' => 'مشروع ثان', 'code' => 'P-2', 'project_status_id' => $status->id, 'project_super_id' => $super->id]);
        $c1 = ProjectCost::create(['project_id' => $p1->id, 'amount' => 1000, 'currency_id' => $usd->id]);
        $c2 = ProjectCost::create(['project_id' => $p1->id, 'amount' => 1000, 'currency_id' => $usd->id]);
        $c3 = ProjectCost::create(['project_id' => $p2->id, 'amount' => 1000, 'currency_id' => $usd->id]);

        $fy = FiscalYear::create(['name' => 'سنة', 'start_date' => '2025-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);

        $accounts = [$a, $b, $c, $d, $e];
        $types = [$receipt, $expense, $transfer];
        $costs = [null, $c1, $c2, $c3];
        $seed = 7;
        $next = function (int $mod) use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;

            return intdiv($seed, 65536) % $mod;
        };

        for ($i = 0; $i < 260; $i++) {
            // Every 9th transaction shares the previous one's timestamp.
            $time = $i % 9 === 0 && isset($time)
                ? $time
                : sprintf('%s %02d:%02d:00', date('Y-m-d', strtotime('2025-11-01 +'.$next(150).' days')), $next(24), $next(4) * 15);

            // A burst of 12 transactions on one exact second, straddling
            // the first page boundary of the A+B statement.
            if ($i >= 60 && $i < 72) {
                $time = '2025-11-20 10:00:00';
            }

            $tx = Transaction::create([
                'fiscal_year_id' => $fy->id,
                'transaction_type_id' => $types[$next(3)]->id,
                'transaction_number' => sprintf('TX-%04d', $i),
                'transaction_time' => $time,
                'description' => 'عملية '.$i,
            ]);

            $debitAccount = $accounts[$next(5)];
            $creditAccount = $accounts[($next(4) + 1 + array_search($debitAccount, $accounts, true)) % 5];
            $amount = ($next(90000) + 100) / 100;
            $cost = $costs[$next(4)];

            TransactionLine::create([
                'transaction_id' => $tx->id, 'account_id' => $debitAccount->id, 'currency_id' => $debitAccount->currency_id,
                'project_cost_id' => $cost?->id, 'amount_currency' => $amount, 'fx_rate' => 1,
                'debit_base' => $amount, 'credit_base' => 0, 'notes' => 'ملاحظة '.$i, 'description' => 'مدين '.$i, 'line_role' => null,
            ]);
            TransactionLine::create([
                'transaction_id' => $tx->id, 'account_id' => $creditAccount->id, 'currency_id' => $creditAccount->currency_id,
                'project_cost_id' => $cost?->id, 'amount_currency' => $amount, 'fx_rate' => 1,
                'debit_base' => 0, 'credit_base' => $amount, 'notes' => null, 'description' => null, 'line_role' => null,
            ]);
        }

        // Soft-deleted transaction and soft-deleted line: never shown.
        Transaction::query()->where('transaction_number', 'TX-0005')->first()->delete();
        TransactionLine::query()->where('description', 'مدين 7')->first()->delete();

        // One line of E in a foreign currency (the original mixed warning).
        $tx = Transaction::create(['fiscal_year_id' => $fy->id, 'transaction_type_id' => $transfer->id, 'transaction_number' => 'TX-MIX', 'transaction_time' => '2026-01-10 12:00:00']);
        TransactionLine::create(['transaction_id' => $tx->id, 'account_id' => $e->id, 'currency_id' => $ils->id, 'amount_currency' => 55.55, 'fx_rate' => 1, 'debit_base' => 55.55, 'credit_base' => 0]);
        TransactionLine::create(['transaction_id' => $tx->id, 'account_id' => $c->id, 'currency_id' => $ils->id, 'amount_currency' => 55.55, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 55.55]);

        // A soft-deleted cost item still carries real history.
        $c2->delete();
        // A soft-deleted transaction type still labels its rows.
        $transfer->delete();

        app(AccountStatementProjectionRebuilder::class)->rebuild();

        return $this->fx = compact('usd', 'ils', 'bankType', 'boxType', 'bank', 'a', 'b', 'c', 'd', 'e', 'income', 'spend', 'receipt', 'expense', 'transfer', 'p1', 'p2', 'c1', 'c2', 'c3');
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    // =========================================================
    // the oracle — plain PHP over raw ledger rows
    // =========================================================

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>|null  $typeIds
     * @param  array<int, int>|null  $costIds
     * @return array{rows: array<int, array<string, mixed>>, accounts: array<int, array<string, mixed>>}
     */
    private function oracle(array $accountIds, string $from, string $to, ?array $typeIds = null, ?array $costIds = null): array
    {
        $lines = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->whereNull('tl.deleted_at')->whereNull('t.deleted_at')
            ->whereIn('tl.account_id', $accountIds)
            ->get(['tl.id', 'tl.account_id', 't.transaction_time', 't.transaction_type_id', 'tl.project_cost_id', 'tl.amount_currency', 'tl.debit_base'])
            ->map(fn ($l) => (array) $l)->all();

        usort($lines, fn ($x, $y) => [$x['transaction_time'], $x['id']] <=> [$y['transaction_time'], $y['id']]);

        $balance = array_fill_keys($accountIds, '0.00');
        $accounts = [];

        foreach ($accountIds as $id) {
            $accounts[$id] = ['opening' => '0.00', 'closing' => '0.00', 'debit' => '0.00', 'credit' => '0.00', 'lines' => 0];
        }

        $rows = [];

        foreach ($lines as $line) {
            $time = substr((string) $line['transaction_time'], 0, 19);
            $amount = number_format((float) $line['amount_currency'], 2, '.', '');
            $isDebit = (float) $line['debit_base'] > 0;
            $id = (int) $line['account_id'];

            if ($time < $from.' 00:00:00') {
                $balance[$id] = bcadd($balance[$id], $isDebit ? $amount : '-'.$amount, 2);
                $accounts[$id]['opening'] = $balance[$id];

                continue;
            }

            if ($time > $to.' 23:59:59') {
                continue;
            }

            $balance[$id] = bcadd($balance[$id], $isDebit ? $amount : '-'.$amount, 2);

            $visible = ($typeIds === null || in_array((int) $line['transaction_type_id'], $typeIds, true))
                && ($costIds === null || in_array((int) $line['project_cost_id'], $costIds, true));

            if ($visible) {
                $rows[] = ['line_id' => (int) $line['id'], 'account_id' => $id, 'debit' => $isDebit ? $amount : '0.00', 'credit' => $isDebit ? '0.00' : $amount, 'running_balance' => $balance[$id]];
                $accounts[$id]['debit'] = bcadd($accounts[$id]['debit'], $isDebit ? $amount : '0.00', 2);
                $accounts[$id]['credit'] = bcadd($accounts[$id]['credit'], $isDebit ? '0.00' : $amount, 2);
                $accounts[$id]['lines']++;
            }
        }

        foreach ($accountIds as $id) {
            $accounts[$id]['closing'] = $balance[$id];
        }

        return ['rows' => $rows, 'accounts' => $accounts];
    }

    /**
     * @return array{scope: AccountStatementScope, summary: array<string, mixed>, rows: array<int, array<string, mixed>>, accounts: array<int, array<string, mixed>>}
     */
    private function engine(AccountStatementFilters $filters): array
    {
        $report = app(AccountStatementReport::class);
        $scope = AccountStatementScope::resolve($filters);
        $summary = $report->summarize($scope);
        $accounts = [];

        foreach ($report->accountSummaries($scope, 1, 1000) as $row) {
            $accounts[$row['account_id']] = $row;
        }

        return [
            'scope' => $scope,
            'summary' => $summary,
            'rows' => iterator_to_array($report->rows($scope, $summary['plan'], 7), false),
            'accounts' => $accounts,
        ];
    }

    private function assertMatchesOracle(array $oracle, array $engine, string $message = ''): void
    {
        $this->assertSame(
            array_map(fn ($r) => [$r['line_id'], $r['account_id'], $r['debit'], $r['credit'], $r['running_balance']], $oracle['rows']),
            array_map(fn ($r) => [$r['line_id'], $r['account_id'], $r['debit'], $r['credit'], $r['running_balance']], $engine['rows']),
            $message.' rows / order / running balances',
        );

        $this->assertSame(count($oracle['rows']), $engine['summary']['matched_lines'], $message.' matched lines');

        foreach ($oracle['accounts'] as $id => $want) {
            $have = $engine['accounts'][$id];
            $this->assertSame(
                [$want['opening'], $want['debit'], $want['credit'], $want['closing'], $want['lines']],
                [$have['opening'], $have['debit'], $have['credit'], $have['closing'], $have['lines']],
                $message.' account '.$id.' summary',
            );
        }
    }

    // =========================================================
    // exact figures against the oracle
    // =========================================================

    /**
     * @return array<string, array{0: array<int, string>, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        return [
            'one account, one month' => [['a'], '2026-01-01', '2026-01-31', []],
            'one account, whole history' => [['a'], '2025-01-01', '2026-12-31', []],
            'one account, partial months' => [['b'], '2025-11-17', '2026-02-11', []],
            'one account, single day of ties' => [['a'], '2025-11-20', '2025-11-20', []],
            'two accounts, one currency' => [['a', 'b'], '2025-12-01', '2026-02-28', []],
            'three accounts, two currencies' => [['a', 'b', 'c'], '2025-11-01', '2026-03-31', []],
            'all five accounts' => [['a', 'b', 'c', 'd', 'e'], '2025-12-15', '2026-03-10', []],
            'multi accounts + one type' => [['a', 'b', 'c'], '2025-11-01', '2026-03-31', ['types' => ['receipt']]],
            'multi accounts + two types' => [['a', 'c', 'e'], '2025-11-10', '2026-03-05', ['types' => ['receipt', 'transfer']]],
            'multi accounts + classification' => [['a', 'b', 'd'], '2025-11-01', '2026-03-31', ['classes' => ['spend']]],
            'classification ∩ types' => [['a', 'b'], '2025-11-01', '2026-03-31', ['classes' => ['income', 'spend'], 'types' => ['expense', 'transfer']]],
            'multi accounts + project (deleted cost included)' => [['a', 'b', 'c'], '2025-11-01', '2026-03-31', ['projects' => ['p1']]],
            'multi accounts + two projects' => [['a', 'b', 'c', 'd'], '2025-12-01', '2026-02-15', ['projects' => ['p1', 'p2']]],
            'several filters at once' => [['a', 'b', 'c', 'd', 'e'], '2025-11-15', '2026-03-15', ['types' => ['receipt', 'expense'], 'projects' => ['p1', 'p2']]],
            'empty period' => [['a', 'b'], '2027-01-01', '2027-01-31', []],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_the_statement_equals_the_ledger_oracle(array $accountKeys, string $from, string $to, array $spec): void
    {
        $fx = $this->seedLedger();
        $accountIds = array_map(fn (string $key) => $fx[$key]->id, $accountKeys);
        sort($accountIds);

        $typeIds = null;

        if (isset($spec['classes'])) {
            $typeIds = TransactionType::withTrashed()->whereIn('transaction_super_type_id', array_map(fn ($k) => $fx[$k]->id, $spec['classes']))->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        if (isset($spec['types'])) {
            $selected = array_map(fn ($k) => $fx[$k]->id, $spec['types']);
            $typeIds = $typeIds === null ? $selected : array_values(array_intersect($typeIds, $selected));
        }

        $costIds = isset($spec['projects'])
            ? ProjectCost::withTrashed()->whereIn('project_id', array_map(fn ($k) => $fx[$k]->id, $spec['projects']))->pluck('id')->map(fn ($v) => (int) $v)->all()
            : null;

        $filters = AccountStatementFilters::make(
            $from, $to, $accountIds, [], [], [],
            array_map(fn ($k) => $fx[$k]->id, $spec['classes'] ?? []),
            array_map(fn ($k) => $fx[$k]->id, $spec['types'] ?? []),
            array_map(fn ($k) => $fx[$k]->id, $spec['projects'] ?? []),
        );

        $this->assertMatchesOracle($this->oracle($accountIds, $from, $to, $typeIds, $costIds), $this->engine($filters));
    }

    public function test_every_plan_returns_the_same_rows(): void
    {
        $fx = $this->seedLedger();
        $filters = AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['b']->id, $fx['c']->id]);
        $scope = AccountStatementScope::resolve($filters);
        $report = app(AccountStatementReport::class);

        $ids = fn (string $plan) => array_map(fn ($r) => $r['line_id'], iterator_to_array($report->rows($scope, $plan, 11), false));

        $merge = $ids(AccountStatementRows::MERGE);
        $this->assertNotEmpty($merge);
        $this->assertSame($merge, $ids(AccountStatementRows::SCAN));

        // The project plan with every cost of every project = no narrowing
        // beyond lines that carry a project.
        $projects = AccountStatementScope::resolve(AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['b']->id], [], [], [], [], [], [$fx['p1']->id, $fx['p2']->id]));
        $this->assertSame(
            array_map(fn ($r) => $r['line_id'], iterator_to_array($report->rows($projects, AccountStatementRows::PROJECT, 5), false)),
            array_map(fn ($r) => $r['line_id'], iterator_to_array($report->rows($projects, AccountStatementRows::SCAN, 5), false)),
        );
    }

    public function test_account_level_filters_select_every_matching_non_deleted_account(): void
    {
        $fx = $this->seedLedger();
        $fx['d']->delete();

        // Bank-type accounts in USD: A, B, E (D is a box account and deleted).
        $byType = AccountStatementScope::resolve(AccountStatementFilters::make('2025-11-01', '2026-03-31', [], [$fx['bankType']->id], [], [$fx['usd']->id]));
        $this->assertSame([$fx['a']->id, $fx['b']->id, $fx['e']->id], $byType->accountIds);

        // A picked account outside the account-level filters is dropped (AND).
        $picked = AccountStatementScope::resolve(AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['c']->id], [$fx['bankType']->id]));
        $this->assertSame([$fx['a']->id], $picked->accountIds);

        // A soft-deleted account is never covered, even when picked.
        $deleted = AccountStatementScope::resolve(AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['d']->id]));
        $this->assertSame(0, $deleted->accountCount);

        $engine = $this->engine(AccountStatementFilters::make('2025-11-01', '2026-03-31', [], [$fx['bankType']->id], [], [$fx['usd']->id]));
        $this->assertMatchesOracle($this->oracle([$fx['a']->id, $fx['b']->id, $fx['e']->id], '2025-11-01', '2026-03-31'), $engine);
    }

    public function test_per_currency_totals_never_mix_currencies(): void
    {
        $fx = $this->seedLedger();
        $engine = $this->engine(AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['b']->id, $fx['c']->id]));
        $oracle = $this->oracle([$fx['a']->id, $fx['b']->id, $fx['c']->id], '2025-11-01', '2026-03-31');

        $byCode = collect($engine['summary']['currencies'])->keyBy('currency_code');
        $this->assertSame(['ILS', 'USD'], $byCode->keys()->sort()->values()->all());

        $sum = fn (array $ids, string $field) => array_reduce($ids, fn ($carry, $id) => bcadd($carry, $oracle['accounts'][$id][$field], 2), '0.00');

        foreach (['opening', 'debit', 'credit', 'closing'] as $field) {
            $this->assertSame($sum([$fx['a']->id, $fx['b']->id], $field), $byCode['USD'][$field], "USD {$field}");
            $this->assertSame($sum([$fx['c']->id], $field), $byCode['ILS'][$field], "ILS {$field}");
        }

        $this->assertSame(2, $byCode['USD']['accounts']);
        $this->assertSame(1, $byCode['ILS']['accounts']);
    }

    // =========================================================
    // parity with the original single-account report
    // =========================================================

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function legacyScenarios(): array
    {
        return [
            'A month' => ['a', '2026-01-01', '2026-01-31'],
            'A history' => ['a', '2025-01-01', '2026-12-31'],
            'B partial' => ['b', '2025-11-17', '2026-02-11'],
            'C ILS' => ['c', '2025-12-01', '2026-03-31'],
            'E mixed currency' => ['e', '2025-11-01', '2026-03-31'],
            'ties day' => ['a', '2025-11-20', '2025-11-20'],
        ];
    }

    #[DataProvider('legacyScenarios')]
    public function test_a_single_account_statement_equals_the_original_report(string $key, string $from, string $to): void
    {
        $fx = $this->seedLedger();
        $account = $fx[$key];

        $legacy = app(AccountStatementReportService::class)->generate($account->id, $from, $to);
        $engine = $this->engine(AccountStatementFilters::make($from, $to, [$account->id]));
        $summary = $engine['accounts'][$account->id];

        $this->assertSame(number_format($legacy['opening_balance'], 2, '.', ''), $summary['opening']);
        $this->assertSame(number_format($legacy['closing_balance'], 2, '.', ''), $summary['closing']);
        $this->assertSame(number_format($legacy['total_debit'], 2, '.', ''), $summary['debit']);
        $this->assertSame(number_format($legacy['total_credit'], 2, '.', ''), $summary['credit']);
        $this->assertSame($legacy['movements_count'], $summary['lines']);
        $this->assertSame($legacy['movements_count'], $engine['summary']['matched_lines']);
        $this->assertSame($legacy['has_mixed_currencies'], $summary['mixed_currencies']);
        $this->assertSame($legacy['has_mixed_currencies'], $engine['summary']['mixed_currency_accounts'] > 0);
        $this->assertSame($legacy['currency_code'], $summary['currency_code']);

        $project = fn (array $r) => [
            substr((string) $r['date'], 0, 19), $r['transaction_number'], $r['type_name'], $r['description'], $r['notes'],
            number_format((float) $r['debit'], 2, '.', ''), number_format((float) $r['credit'], 2, '.', ''),
            number_format((float) $r['running_balance'], 2, '.', ''), $r['currency_code'], $r['line_role_label'], $r['line_description'],
        ];

        $this->assertSame(array_map($project, $legacy['rows']), array_map($project, $engine['rows']));
    }

    // =========================================================
    // several accounts against each account alone
    // =========================================================

    public function test_a_multi_account_statement_equals_each_single_account_statement(): void
    {
        $fx = $this->seedLedger();
        $ids = [$fx['a']->id, $fx['b']->id, $fx['c']->id];
        $unified = $this->engine(AccountStatementFilters::make('2025-11-01', '2026-03-31', $ids));

        $seen = [];
        $previous = null;

        foreach ($unified['rows'] as $row) {
            $this->assertArrayNotHasKey($row['line_id'], $seen, 'Every line appears exactly once.');
            $seen[$row['line_id']] = $row;

            $key = [$row['date'], $row['line_id']];
            $this->assertTrue($previous === null || $previous < $key, 'Global canonical order.');
            $previous = $key;
        }

        $total = 0;

        foreach ($ids as $id) {
            $alone = $this->engine(AccountStatementFilters::make('2025-11-01', '2026-03-31', [$id]));
            $total += count($alone['rows']);

            foreach ($alone['rows'] as $row) {
                $this->assertArrayHasKey($row['line_id'], $seen);
                $this->assertSame($id, $seen[$row['line_id']]['account_id']);
                $this->assertSame($row['running_balance'], $seen[$row['line_id']]['running_balance'], 'Same balance as the account alone.');
            }

            $this->assertSame($alone['accounts'][$id], $unified['accounts'][$id]);
        }

        $this->assertSame($total, count($unified['rows']));
    }

    // =========================================================
    // the page: applied state, keyset pages, bounded state
    // =========================================================

    private function page(array $data)
    {
        $this->actingAs($this->superAdmin());

        $test = Livewire::test(AccountStatementPage::class);

        foreach ($data as $key => $value) {
            $test->set('data.'.$key, $value);
        }

        return $test->call('showReport');
    }

    public function test_first_next_previous_last_walk_every_row_exactly_once(): void
    {
        $fx = $this->seedLedger();
        $ids = [$fx['a']->id, $fx['b']->id, $fx['c']->id, $fx['e']->id];
        $oracle = array_map(fn ($r) => $r['line_id'], $this->oracle($ids, '2025-11-01', '2026-03-31')['rows']);
        $this->assertGreaterThan(150, count($oracle), 'Enough rows for 4+ pages.');

        $test = $this->page(['account_ids' => array_map('strval', $ids), 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $pages = (int) ceil(count($oracle) / 50);
        $test->assertSet('pageCount', $pages)->assertSet('pageNumber', 1);

        $lines = fn () => array_map(fn ($r) => $r['line_id'], $test->instance()->currentRows());

        $walked = $lines();
        $this->assertCount(50, $walked);

        for ($p = 2; $p <= $pages; $p++) {
            $test->call('nextPage')->assertSet('pageNumber', $p);
            $walked = array_merge($walked, $lines());
        }

        $this->assertSame($oracle, $walked, 'Next walks every row once, in order.');

        $test->call('nextPage')->assertSet('pageNumber', $pages);

        $test->call('previousPage');
        $this->assertSame(array_slice($oracle, ($pages - 2) * 50, 50), $lines());

        $test->call('firstPage')->assertSet('pageNumber', 1);
        $this->assertSame(array_slice($oracle, 0, 50), $lines());

        $test->call('nextPage')->call('nextPage')->call('previousPage')->assertSet('pageNumber', 2);
        $this->assertSame(array_slice($oracle, 50, 50), $lines());

        $test->call('lastPage')->assertSet('pageNumber', $pages);
        $this->assertSame(array_slice($oracle, ($pages - 1) * 50), $lines(), 'Last shows the remainder, aligned with Next.');

        $test->call('previousPage')->assertSet('pageNumber', $pages - 1);
        $this->assertSame(array_slice($oracle, ($pages - 2) * 50, 50), $lines());

        $test->call('previousPage');
        $test->call('previousPage')->assertSet('pageNumber', max(1, $pages - 3));
    }

    public function test_page_rows_carry_account_labels_and_their_own_balances(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['account_ids' => [(string) $fx['a']->id, (string) $fx['c']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $oracle = collect($this->oracle([$fx['a']->id, $fx['c']->id], '2025-11-01', '2026-03-31')['rows'])->keyBy('line_id');

        foreach ($test->instance()->currentRows() as $row) {
            $this->assertSame($row['account_id'] === $fx['a']->id ? 'A-100 - حساب A-100' : 'C-300 - حساب C-300', $row['account_label']);
            $this->assertSame($oracle[$row['line_id']]['running_balance'], $row['running_balance']);
        }

        $test->assertSee('A-100 - حساب A-100')->assertSee('C-300 - حساب C-300');
    }

    public function test_changing_any_filter_hides_the_result_until_show_is_pressed(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $test->assertSet('hasSubmitted', true);

        foreach ([
            ['data.date_to', '2026-02-28'],
            ['data.transaction_type_ids', [(string) $fx['receipt']->id]],
            ['data.project_ids', [(string) $fx['p1']->id]],
            ['data.account_type_ids', [(string) $fx['bankType']->id]],
        ] as [$field, $value]) {
            $test->call('showReport')->assertSet('hasSubmitted', true);
            $test->set($field, $value)->assertSet('hasSubmitted', false)->assertSet('appliedFilters', null)->assertSet('summary', []);
            $this->assertSame([], $test->instance()->currentRows());
        }
    }

    public function test_an_account_scope_is_required(): void
    {
        $this->seedLedger();

        $this->page(['date_from' => '2025-11-01', 'date_to' => '2026-03-31'])
            ->assertHasErrors(['data.account_ids'])
            ->assertSet('hasSubmitted', false);
    }

    public function test_a_projection_that_is_not_ready_is_never_shown(): void
    {
        $fx = $this->seedLedger();
        app(AccountStatementProjectionState::class)->markInvalid('test');

        $this->page(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31'])
            ->assertSet('hasSubmitted', false)
            ->assertNotified(AccountStatementPage::NOT_READY_MESSAGE);
    }

    public function test_livewire_state_stays_small_and_never_holds_rows(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['account_type_ids' => [(string) $fx['bankType']->id, (string) $fx['boxType']->id], 'date_from' => '2025-01-01', 'date_to' => '2026-12-31']);
        $test->call('nextPage');

        $public = [];

        foreach ((new \ReflectionObject($test->instance()))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic() && $property->getDeclaringClass()->getName() === AccountStatementPage::class) {
                $public[$property->getName()] = $property->getValue($test->instance());
            }
        }

        $this->assertArrayNotHasKey('rows', $public);
        $this->assertLessThan(8 * 1024, strlen(json_encode($public)), 'Public state is O(1) in the number of matching rows.');
        $this->assertStringNotContainsString('running_balance', json_encode($public), 'No row is ever stored in state.');
    }

    public function test_the_summary_is_not_recomputed_when_paging(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['account_ids' => [(string) $fx['a']->id, (string) $fx['b']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, AccountStatementProjection::ACCOUNT_TOTALS) || str_contains($query->sql, 'HAVING COUNT')) {
                $queries++;
            }
        });

        $test->call('nextPage')->call('previousPage')->call('lastPage');
        $this->assertSame(0, $queries, 'Paging never re-runs the filter-level summary.');
    }

    public function test_the_account_picker_searches_on_the_server_by_code_name_or_iban(): void
    {
        $fx = $this->seedLedger();
        $search = fn (string $term, array $types = []) => AccountStatementPage::accountOptions($term, $types);

        $this->assertArrayHasKey($fx['b']->id, $search('B-200'));
        $this->assertArrayHasKey($fx['c']->id, $search('حساب C-300'));
        $this->assertArrayHasKey($fx['e']->id, $search('PS00E-500'), 'IBAN, spaces ignored.');
        $this->assertArrayNotHasKey($fx['a']->id, $search('B-200'));
        $this->assertSame([$fx['c']->id, $fx['d']->id], array_keys($search('', [$fx['boxType']->id])), 'Within the chosen account types.');
        $this->assertSame('B-200 - حساب B-200 (USD)', $search('B-200')[$fx['b']->id]);

        for ($i = 0; $i < 60; $i++) {
            Account::create(['account_code' => sprintf('Z-%03d', $i), 'name' => 'إضافي', 'account_type_id' => $fx['bankType']->id, 'currency_id' => $fx['usd']->id, 'current_balance' => 0, 'is_active' => true]);
        }

        $this->assertCount(50, $search(''), 'Bounded initial options.');
    }

    // =========================================================
    // soft deletes
    // =========================================================

    public function test_soft_deleted_transactions_and_lines_are_never_listed(): void
    {
        $fx = $this->seedLedger();
        $engine = $this->engine(AccountStatementFilters::make('2025-01-01', '2026-12-31', [$fx['a']->id, $fx['b']->id, $fx['c']->id, $fx['d']->id, $fx['e']->id]));
        $numbers = array_column($engine['rows'], 'transaction_number');
        $descriptions = array_column($engine['rows'], 'line_description');

        $this->assertNotContains('TX-0005', $numbers);
        $this->assertNotContains('مدين 7', $descriptions);
    }

    public function test_deleted_lookups_still_label_their_history(): void
    {
        $fx = $this->seedLedger();
        $engine = $this->engine(AccountStatementFilters::make('2025-01-01', '2026-12-31', [$fx['a']->id, $fx['b']->id, $fx['c']->id, $fx['d']->id, $fx['e']->id], [], [], [], [], [], [$fx['p1']->id]));

        $this->assertContains('تحويل', array_column($engine['rows'], 'type_name'), 'A soft-deleted type still names its rows.');
        $costTwoLines = DB::table('transaction_lines')->where('project_cost_id', $fx['c2']->id)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertNotEmpty(array_intersect($costTwoLines, array_column($engine['rows'], 'line_id')), 'Lines of a soft-deleted cost item stay under their project.');
    }

    // =========================================================
    // verifier, rebuild
    // =========================================================

    public function test_the_verifier_finds_no_difference_after_a_rebuild_and_catches_drift(): void
    {
        $fx = $this->seedLedger();
        $verifier = app(AccountStatementProjectionVerifier::class);

        $clean = $verifier->verify(balanceSamples: 40);
        $this->assertSame(0, $clean['differences'], implode(' | ', $clean['samples']));
        $this->assertSame(40, $clean['balances_checked']);

        DB::table(AccountStatementProjection::LINES)->where('account_id', $fx['a']->id)->limit(1)->update(['debit' => DB::raw('debit + 1')]);
        DB::table(AccountStatementProjection::DAILY_TOTALS)->where('account_id', $fx['b']->id)->limit(1)->update(['credit_total' => DB::raw('credit_total + 1')]);
        DB::table(AccountStatementProjection::ACCOUNT_TOTALS)->where('account_id', $fx['c']->id)->update(['line_count' => DB::raw('line_count + 1')]);

        $drift = $verifier->verify();
        $this->assertSame(1, $drift['line_differences']);
        $this->assertSame(1, $drift['daily_differences']);
        $this->assertSame(1, $drift['account_total_differences']);

        app(AccountStatementProjectionRebuilder::class)->rebuild();
        $this->assertSame(0, $verifier->verify()['differences']);
    }

    public function test_a_rebuild_is_idempotent_and_the_commands_run(): void
    {
        $this->seedLedger();
        $before = DB::table(AccountStatementProjection::LINES)->orderBy('line_id')->get()->map(fn ($r) => (array) $r)->all();

        $this->artisan('oms:rebuild-account-statement-projection', ['--verify' => true])->assertExitCode(0);

        $this->assertEquals($before, DB::table(AccountStatementProjection::LINES)->orderBy('line_id')->get()->map(fn ($r) => (array) $r)->all());
        $this->artisan('oms:verify-account-statement-projection')->assertExitCode(0);
        $this->assertNotNull(app(AccountStatementProjectionState::class)->row()['verified_at']);
    }

    // =========================================================
    // exports
    // =========================================================

    public function test_the_excel_export_holds_every_matching_row_with_the_screen_balances(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['account_ids' => [(string) $fx['a']->id, (string) $fx['c']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $page = $test->instance();
        $oracle = $this->oracle([$fx['a']->id, $fx['c']->id], '2025-11-01', '2026-03-31')['rows'];
        $this->assertGreaterThan(50, count($oracle), 'More rows than one screen page.');

        $sheet = app(AccountStatementExcelExportService::class)
            ->build($page->exportContext(), app(AccountStatementReport::class)->rows(AccountStatementScope::resolve(AccountStatementFilters::fromArray($page->appliedFilters)), $page->plan))
            ->getActiveSheet();

        $header = null;

        foreach ($sheet->getRowIterator() as $row) {
            if ($sheet->getCell('A'.$row->getRowIndex())->getValue() === 'التاريخ' && $sheet->getCell('C'.$row->getRowIndex())->getValue() === 'الحساب') {
                $header = $row->getRowIndex();
            }
        }

        $this->assertNotNull($header);
        $exported = [];

        for ($r = $header + 1; $r <= $sheet->getHighestRow(); $r++) {
            $exported[] = [(string) $sheet->getCell('C'.$r)->getValue(), number_format((float) $sheet->getCell('K'.$r)->getValue(), 2, '.', '')];
        }

        $labels = [$fx['a']->id => 'A-100 - حساب A-100', $fx['c']->id => 'C-300 - حساب C-300'];
        $this->assertSame(array_map(fn ($o) => [$labels[$o['account_id']], $o['running_balance']], $oracle), $exported);

        $response = $page->exportExcel();
        $this->assertNotNull($response);
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_word_is_refused_above_its_limit_and_not_audited(): void
    {
        $fx = $this->seedLedger();
        config(['oms.reports.account_statement.word_max_units' => 10]);

        $test = $this->page(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2025-01-01', 'date_to' => '2026-12-31']);
        $this->assertNull($test->instance()->exportWord());
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());

        config(['oms.reports.account_statement.word_max_units' => 2000]);
        $this->assertNotNull($test->instance()->exportWord());
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_a_large_excel_is_prepared_in_the_background_and_downloaded_once(): void
    {
        $fx = $this->seedLedger();
        config([
            'oms.reports.account_statement.styled_excel_max_units' => 5,
            'oms.reports.account_statement.inline_streaming_excel_max_units' => 20,
        ]);
        Queue::fake();

        $user = $this->superAdmin();
        $this->actingAs($user);
        $test = Livewire::test(AccountStatementPage::class)
            ->set('data.account_ids', [(string) $fx['a']->id, (string) $fx['b']->id])
            ->set('data.date_from', '2025-01-01')
            ->set('data.date_to', '2026-12-31')
            ->call('showReport');

        $this->assertNull($test->instance()->exportExcel());
        $exportId = $test->get('excelExportId');
        $this->assertNotNull($exportId);
        Queue::assertPushed(BuildAccountStatementExcelExportJob::class);
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count(), 'Audited at download, not at queueing.');

        // The page polls the lightweight status endpoint: pending first…
        $statusUrl = route('reports.account-statement.export-status', ['export' => $exportId]);
        $this->assertSame($statusUrl, $test->instance()->excelExportStatusUrl());
        $this->getJson($statusUrl)->assertOk()->assertExactJson(['id' => $exportId, 'state' => 'pending']);

        app()->call([new BuildAccountStatementExcelExportJob($user->id, $exportId), 'handle']);
        $this->assertSame(AccountStatementExcelExports::COMPLETED, app(AccountStatementExcelExports::class)->status($user->id, $exportId)['state']);

        // …then ready, with a signed download URL — and nothing else.
        $ready = $this->getJson($statusUrl)->assertOk()->json();
        $this->assertSame(['download_url', 'id', 'state'], array_keys(collect($ready)->sortKeys()->all()));
        $this->assertSame('ready', $ready['state']);
        $this->assertLessThan(1024, strlen(json_encode($ready)), 'Bounded status payload.');

        $test->call('forgetExcelExport')->assertSet('excelExportId', null);

        $url = $ready['download_url'];
        $response = $this->get($url);
        $response->assertOk();
        $response->streamedContent();
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
        $this->assertNull(app(AccountStatementExcelExports::class)->status($user->id, $exportId), 'Removed once downloaded.');

        $this->get($url)->assertNotFound();
    }

    public function test_a_medium_excel_streams_inline_with_every_row(): void
    {
        $fx = $this->seedLedger();
        config(['oms.reports.account_statement.styled_excel_max_units' => 5]);

        $test = $this->page(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2025-01-01', 'date_to' => '2026-12-31']);
        $response = $test->instance()->exportExcel();
        $this->assertNotNull($response);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $path = $this->tempDir.DIRECTORY_SEPARATOR.'check.xlsx';
        File::ensureDirectoryExists($this->tempDir);
        file_put_contents($path, $content);

        $sheet = IOFactory::load($path)->getActiveSheet();
        $balances = [];
        $inTable = false;

        foreach ($sheet->toArray(null, true, false) as $row) {
            if ($row[0] === 'التاريخ' && $row[2] === 'الحساب') {
                $inTable = true;

                continue;
            }

            if ($inTable) {
                $balances[] = number_format((float) $row[10], 2, '.', '');
            }
        }

        $this->assertSame(array_column($this->oracle([$fx['a']->id], '2025-01-01', '2026-12-31')['rows'], 'running_balance'), $balances);
    }

    // =========================================================
    // pre-commit polish (2026-10-07)
    // =========================================================

    public function test_balance_colours_sit_on_an_inner_value_never_on_the_table_cell(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['account_ids' => [(string) $fx['a']->id, (string) $fx['c']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $html = $test->html();

        $this->assertStringNotContainsString('<td class="acct-stmt-number', $html, 'A table cell never carries the balance classes.');
        $this->assertMatchesRegularExpression('/<td><span class="acct-stmt-number acct-stmt-number-(success|danger)">/', $html);
        // Running balance (50 rows) + opening and closing of 2 accounts.
        $this->assertGreaterThanOrEqual(50, preg_match_all('/<td><span class="acct-stmt-number[^"]*">/', $html));
    }

    public function test_export_work_counts_movements_and_account_summaries(): void
    {
        $plan = fn (int $rows, int $accounts, int $currencies = 1) => [
            'units' => AccountStatementExportPlan::units(['matched_lines' => $rows, 'account_count' => $accounts, 'currencies' => array_fill(0, $currencies, [])]),
            'excel' => AccountStatementExportPlan::excelMode(['matched_lines' => $rows, 'account_count' => $accounts, 'currencies' => array_fill(0, $currencies, [])]),
            'word' => AccountStatementExportPlan::wordAllowed(['matched_lines' => $rows, 'account_count' => $accounts, 'currencies' => array_fill(0, $currencies, [])]),
        ];

        // The browser case: 276 movements, 1,767 accounts, 3 currencies.
        $this->assertSame(['units' => 1163, 'excel' => 'styled', 'word' => true], $plan(276, 1767, 3));
        // Few movements, many accounts: no longer "small".
        $this->assertSame(['units' => 2503, 'excel' => 'streaming', 'word' => false], $plan(0, 5000, 3));
        $this->assertSame(['units' => 2101, 'excel' => 'streaming', 'word' => false], $plan(1900, 400));
        $this->assertSame('background', $plan(9000, 10)['excel']);
        $this->assertSame('background', $plan(10, 16000)['excel'], 'A huge summary alone goes to the background.');
        $this->assertSame(['units' => 1462, 'excel' => 'styled', 'word' => true], $plan(1460, 1));
    }

    public function test_a_broad_summary_scope_goes_to_the_background_even_with_few_movements(): void
    {
        $fx = $this->seedLedger();

        for ($i = 0; $i < 40; $i++) {
            Account::create(['account_code' => sprintf('Q-%03d', $i), 'name' => 'بدون حركة', 'account_type_id' => $fx['bankType']->id, 'currency_id' => $fx['usd']->id, 'current_balance' => 0, 'is_active' => true]);
        }

        // 43 bank-type USD accounts, a period with no movements at all.
        config([
            'oms.reports.account_statement.styled_excel_max_units' => 10,
            'oms.reports.account_statement.inline_streaming_excel_max_units' => 20,
            'oms.reports.account_statement.word_max_units' => 10,
        ]);
        Queue::fake();

        $test = $this->page(['account_type_ids' => [(string) $fx['bankType']->id], 'currency_ids' => [(string) $fx['usd']->id], 'date_from' => '2027-01-01', 'date_to' => '2027-01-31']);
        $page = $test->instance();
        $this->assertSame(0, $page->summary['matched_lines']);
        $this->assertSame(43, $page->summary['account_count']);

        $this->assertNull($page->exportWord(), 'Word refused on work, not on movement count.');
        $test->assertNotified(AccountStatementPage::wordRefusalMessage($page->summary));
        $this->assertStringContainsString('43 حساب', AccountStatementPage::wordRefusalMessage($page->summary));

        $this->assertNull($page->exportExcel());
        Queue::assertPushed(BuildAccountStatementExcelExportJob::class);

        // The job receives exactly the applied filters and summary.
        $payload = app(AccountStatementExcelExports::class)->status(auth()->id(), $test->get('excelExportId'))['payload'];
        $this->assertSame($page->appliedFilters, $payload['filters']);
        $this->assertSame($page->summary, $payload['summary']);
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_a_small_export_stays_synchronous(): void
    {
        $fx = $this->seedLedger();
        Queue::fake();

        $test = $this->page(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $this->assertSame(AccountStatementExportPlan::STYLED, AccountStatementExportPlan::excelMode($test->instance()->summary));

        $this->assertNotNull($test->instance()->exportExcel());
        $this->assertNotNull($test->instance()->exportWord());
        Queue::assertNothingPushed();
        $this->assertNull($test->get('excelExportId'));
    }

    public function test_the_export_status_endpoint_is_owner_scoped_and_permission_checked(): void
    {
        $owner = $this->superAdmin();
        $exportId = app(AccountStatementExcelExports::class)->create($owner->id, ['filters' => [], 'summary' => [], 'plan' => 'scan', 'audit' => []]);
        $url = route('reports.account-statement.export-status', ['export' => $exportId]);

        $this->actingAs($owner)->getJson($url)->assertOk()->assertExactJson(['id' => $exportId, 'state' => 'pending']);

        // Another user — even a super admin — cannot see it.
        $this->actingAs($this->superAdmin())->getJson($url)->assertNotFound();

        // View permission without export permission: forbidden.
        Permission::firstOrCreate(['name' => 'reports.account_statement.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.account_statement.view');
        $this->actingAs($viewer)->getJson($url)->assertForbidden();

        // Malformed id never reaches the controller.
        $this->actingAs($owner)->getJson('/reports/account-statement/exports/not-an-id/status')->assertNotFound();

        // Failed exports report "failed" and expose no URL.
        app(AccountStatementExcelExports::class)->markFailed($owner->id, $exportId);
        $this->actingAs($owner)->getJson($url)->assertOk()->assertExactJson(['id' => $exportId, 'state' => 'failed']);
    }

    public function test_polling_never_touches_the_report_and_navigation_keeps_working_meanwhile(): void
    {
        $fx = $this->seedLedger();
        config(['oms.reports.account_statement.styled_excel_max_units' => 5, 'oms.reports.account_statement.inline_streaming_excel_max_units' => 10]);
        Queue::fake();

        $ids = [$fx['a']->id, $fx['b']->id, $fx['c']->id, $fx['e']->id];
        $oracle = array_map(fn ($r) => $r['line_id'], $this->oracle($ids, '2025-11-01', '2026-03-31')['rows']);
        $test = $this->page(['account_ids' => array_map('strval', $ids), 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $test->instance()->exportExcel();
        $statusUrl = $test->instance()->excelExportStatusUrl();
        $summary = $test->get('summary');
        $pages = $test->get('pageCount');

        $reportQueries = 0;
        DB::listen(function ($query) use (&$reportQueries) {
            if (str_contains($query->sql, 'account_statement_') || str_contains($query->sql, 'transaction_lines')) {
                $reportQueries++;
            }
        });

        $lines = fn () => array_map(fn ($r) => $r['line_id'], $test->instance()->currentRows());
        $expect = [
            'nextPage' => fn () => array_slice($oracle, 50, 50),
            'previousPage' => fn () => array_slice($oracle, 0, 50),
            'lastPage' => fn () => array_slice($oracle, ($pages - 1) * 50),
            'firstPage' => fn () => array_slice($oracle, 0, 50),
        ];

        for ($cycle = 0; $cycle < 3; $cycle++) {
            foreach ($expect as $method => $rows) {
                $before = $reportQueries;
                $this->getJson($statusUrl)->assertOk()->assertJson(['state' => 'pending']);
                $this->assertSame($before, $reportQueries, 'A status poll runs no report query.');

                $test->call($method);
                $this->assertSame($rows(), $lines(), "{$method} while polling (cycle {$cycle})");
            }
        }

        $this->assertSame($summary, $test->get('summary'), 'Polling never recomputes or mutates the summary.');
        $test->call('forgetExcelExport')->assertSet('excelExportId', null)->assertSet('summary', $summary)->assertSet('hasSubmitted', true);
    }

    public function test_account_labels_never_repeat_the_currency(): void
    {
        $label = fn (?string $code, ?string $name, ?string $currency) => AccountStatementReport::accountDisplayLabel($code, $name, $currency);

        $this->assertSame('1001 - حساب الجمعية الرئيسي - بنك فلسطين - USD', $label('1001', 'حساب الجمعية الرئيسي - بنك فلسطين - USD', 'USD'));
        $this->assertSame('1001 - Bank Account (USD)', $label('1001', 'Bank Account', 'USD'));
        $this->assertSame('1002 - صندوق (USD)', $label('1002', 'صندوق (USD)', 'USD'));
        $this->assertSame('1003 - Wallet USD', $label('1003', 'Wallet USD', 'USD'));
        $this->assertSame('1004 - محفظة USDT (USD)', $label('1004', 'محفظة USDT', 'USD'), 'Another code that merely starts the same is not the currency.');
        $this->assertSame('1005 - حساب - ILS (USD)', $label('1005', 'حساب - ILS', 'USD'), 'A different currency in the name keeps the real one visible.');
        $this->assertSame('الصندوق', $label(null, 'الصندوق', null));

        $fx = $this->seedLedger();
        $fx['a']->update(['name' => 'حساب رئيسي - USD']);
        $this->assertSame('A-100 - حساب رئيسي - USD', AccountStatementPage::accountOptions('A-100')[$fx['a']->id]);
    }
}
