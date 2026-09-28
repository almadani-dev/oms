<?php

namespace Tests\Feature\Performance;

use App\Filament\Resources\ExecutionPayments\Pages\ListExecutionPayments;
use App\Filament\Resources\GeneralExchanges\Pages\ListGeneralExchanges;
use App\Filament\Resources\GeneralExpenses\Pages\ListGeneralExpenses;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\ListProjectCostBudgetsPayments;
use App\Filament\Resources\ProjectCostReceipts\Pages\ListProjectCostReceipts;
use App\Filament\Resources\ProjectCosts\Pages\ViewProjectCost;
use App\Filament\Resources\ProjectCosts\RelationManagers\ReceiptsRelationManager;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Batch C2: rendering the five financial lists (and the receipts relation
 * manager under a project cost) must not issue queries per displayed row.
 *
 * The scaling tests render each list with 1 and with 10 rows and compare the
 * query counts: the difference must stay a small constant, never grow by one
 * (has_attachment) or more (receipt account columns) per row. Framework
 * queries (auth, permissions, filters) are identical on both renders, so no
 * fragile absolute totals are asserted. Set OMS_QUERY_REPORT=<file> to append
 * the measured counts for the batch report.
 *
 * The correctness tests pin the displayed values the optimization must keep:
 * نعم / لا for the attachment badge and the debit/credit account names,
 * including a missing line and a soft-deleted account (both render empty).
 */
class FinancialListsQueryCountTest extends TestCase
{
    use IntegrityTestFixtures;

    /** Growth allowed between 1 and 10 rows: no per-row query survives. */
    private const MAX_GROWTH = 2;

    private Currency $currency;

    private FiscalYear $fiscalYear;

    private TransactionType $type;

    private ProjectCost $cost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($user);

        $this->currency = $this->makeCurrency(['name' => 'عملة', 'code' => 'TST']);
        $this->fiscalYear = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $this->type = TransactionType::create(['name' => 'نوع']);

        $project = Project::create([
            'name' => 'مشروع',
            'project_super_id' => ProjectSuper::create(['name' => 'رئيسي', 'code_prefix' => 'QRY'])->id,
            'project_status_id' => ProjectStatus::create(['name' => 'نشط'])->id,
        ]);
        $this->cost = ProjectCost::create(['project_id' => $project->id, 'amount' => 100000, 'currency_id' => $this->currency->id]);
    }

    // =====================================================================
    // FIXTURES: one row = one operation + its transaction + debit/credit lines
    // =====================================================================

    /**
     * @return array{Transaction, Account, Account}
     */
    private function transaction(string $number, bool $withCreditLine = true): array
    {
        $transaction = Transaction::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'transaction_type_id' => $this->type->id,
            'transaction_number' => $number,
            'transaction_time' => now(),
        ]);

        $debit = $this->makeAccount($this->currency, ['account_code' => "D-{$number}", 'name' => "مدين {$number}"]);
        $credit = $this->makeAccount($this->currency, ['account_code' => "C-{$number}", 'name' => "دائن {$number}"]);

        $this->makeLine($transaction, $debit, $this->currency, ['amount_currency' => 10, 'debit_base' => 10]);

        if ($withCreditLine) {
            $this->makeLine($transaction, $credit, $this->currency, ['amount_currency' => 10, 'credit_base' => 10]);
        }

        return [$transaction, $debit, $credit];
    }

    private function row(string $key, int $i): Model
    {
        [$transaction] = $this->transaction(strtoupper($key)."-{$i}");

        return match ($key) {
            'execution' => ProjectCostBudgetsPayment::create([
                'project_cost_budget_id' => ProjectCostBudget::create([
                    'project_cost_id' => $this->cost->id,
                    'original_amount' => 100, 'final_amount' => 100,
                    'source_currency_id' => $this->currency->id, 'disbursement_currency_id' => $this->currency->id,
                ])->id,
                'amount' => 10, 'currency_id' => $this->currency->id, 'date' => '2026-07-01',
                'transaction_id' => $transaction->id,
            ]),
            'budget' => ProjectCostBudget::create([
                'project_cost_id' => $this->cost->id, 'transaction_id' => $transaction->id,
                'original_amount' => 10, 'final_amount' => 10,
                'source_currency_id' => $this->currency->id, 'disbursement_currency_id' => $this->currency->id,
            ]),
            'receipt' => ProjectCostReceipt::create([
                'project_cost_id' => $this->cost->id, 'amount' => 10, 'currency_id' => $this->currency->id,
                'date' => '2026-07-01', 'transaction_id' => $transaction->id,
            ]),
            'exchange' => GeneralExchange::create([
                'original_amount' => 10, 'final_amount' => 10,
                'source_currency_id' => $this->currency->id, 'disbursement_currency_id' => $this->currency->id,
                'date' => '2026-07-01', 'transaction_id' => $transaction->id,
            ]),
            'expense' => GeneralExpense::create([
                'amount' => 10, 'currency_id' => $this->currency->id, 'date' => '2026-07-01',
                'transaction_id' => $transaction->id,
            ]),
        };
    }

    private function attach(Model $parent): Attachment
    {
        return Attachment::create([
            'attachable_type' => get_class($parent),
            'attachable_id' => $parent->id,
            'file_name' => 'receipt.jpg',
            'file_path' => 'receipts/stored_'.uniqid().'.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'disk' => Attachment::DISK_ATTACHMENTS,
        ]);
    }

    /** @return array<string, class-string> */
    private function pages(): array
    {
        return [
            'execution' => ListExecutionPayments::class,
            'budget' => ListProjectCostBudgetsPayments::class,
            'receipt' => ListProjectCostReceipts::class,
            'exchange' => ListGeneralExchanges::class,
            'expense' => ListGeneralExpenses::class,
        ];
    }

    // =====================================================================
    // QUERY SCALING
    // =====================================================================

    /**
     * Queries issued while mounting and rendering the list with $rows rows on
     * screen. Every row gets an attachment on odd positions, so both badge
     * states are rendered.
     */
    private function renderQueries(callable $render): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function seedRows(string $key, int $from, int $to): void
    {
        foreach (range($from, $to) as $i) {
            $row = $this->row($key, $i);

            if ($i % 2 === 1) {
                $this->attach($row);
            }
        }
    }

    private function report(string $label, array $counts): void
    {
        if ($file = getenv('OMS_QUERY_REPORT')) {
            file_put_contents($file, $label.' '.json_encode($counts)."\n", FILE_APPEND);
        }
    }

    public function test_each_financial_list_renders_with_a_constant_query_count(): void
    {
        $measured = [];

        // Measure every list first, then assert, so one failure never hides the
        // numbers for the others.
        foreach ($this->pages() as $key => $page) {
            $render = fn () => Livewire::test($page)->assertOk();

            $this->seedRows($key, 1, 1);
            $one = $this->renderQueries($render);

            $this->seedRows($key, 2, 5);
            $five = $this->renderQueries($render);

            $this->seedRows($key, 6, 10);
            $ten = $this->renderQueries($render);

            $measured[$key] = ['1' => $one, '5' => $five, '10' => $ten];
            $this->report($key, $measured[$key]);
        }

        foreach ($measured as $key => $counts) {
            $this->assertLessThanOrEqual(
                self::MAX_GROWTH,
                $counts['10'] - $counts['1'],
                "{$key}: 1 row = {$counts['1']} queries, 5 rows = {$counts['5']}, 10 rows = {$counts['10']} — rendering must not query per row.",
            );
        }
    }

    public function test_receipts_relation_manager_renders_with_a_constant_query_count(): void
    {
        $render = fn () => Livewire::test(ReceiptsRelationManager::class, [
            'ownerRecord' => $this->cost,
            'pageClass' => ViewProjectCost::class,
        ])->assertOk();

        $this->seedRows('receipt', 1, 1);
        $one = $this->renderQueries($render);

        $this->seedRows('receipt', 2, 5);
        $five = $this->renderQueries($render);

        $this->seedRows('receipt', 6, 10);
        $ten = $this->renderQueries($render);

        $this->report('receipt-relation-manager', ['1' => $one, '5' => $five, '10' => $ten]);

        $this->assertLessThanOrEqual(
            self::MAX_GROWTH,
            $ten - $one,
            "receipts relation manager: 1 row = {$one} queries, 5 rows = {$five}, 10 rows = {$ten} — rendering must not query per row.",
        );
    }

    // =====================================================================
    // DISPLAYED VALUES STAY THE SAME
    // =====================================================================

    public function test_attachment_badge_still_shows_yes_and_no(): void
    {
        foreach ($this->pages() as $key => $page) {
            $with = $this->row($key, 101);
            $without = $this->row($key, 102);
            $this->attach($with);

            Livewire::test($page)
                ->assertTableColumnStateSet('has_attachment', 'نعم', $with)
                ->assertTableColumnStateSet('has_attachment', 'لا', $without);
        }
    }

    public function test_a_soft_deleted_attachment_no_longer_counts(): void
    {
        $row = $this->row('expense', 201);
        $this->attach($row)->delete();

        Livewire::test(ListGeneralExpenses::class)
            ->assertTableColumnStateSet('has_attachment', 'لا', $row);
    }

    public function test_receipt_account_columns_show_the_same_accounts(): void
    {
        [$transaction, $debit, $credit] = $this->transaction('RCP-ACC');
        $receipt = ProjectCostReceipt::create([
            'project_cost_id' => $this->cost->id, 'amount' => 10, 'currency_id' => $this->currency->id,
            'date' => '2026-07-01', 'transaction_id' => $transaction->id,
        ]);

        Livewire::test(ListProjectCostReceipts::class)
            ->assertTableColumnStateSet('debit_account', $debit->name, $receipt)
            ->assertTableColumnStateSet('credit_account', $credit->name, $receipt);

        Livewire::test(ReceiptsRelationManager::class, ['ownerRecord' => $this->cost, 'pageClass' => ViewProjectCost::class])
            ->assertTableColumnStateSet('debit_account', $debit->name, $receipt)
            ->assertTableColumnStateSet('credit_account', $credit->name, $receipt)
            ->assertTableColumnStateSet('has_attachment', 'لا', $receipt);
    }

    public function test_receipt_account_columns_keep_their_empty_fallbacks(): void
    {
        // No credit line at all.
        [$transaction, $debit] = $this->transaction('RCP-NOCREDIT', withCreditLine: false);
        $noCredit = ProjectCostReceipt::create([
            'project_cost_id' => $this->cost->id, 'amount' => 10, 'currency_id' => $this->currency->id,
            'date' => '2026-07-01', 'transaction_id' => $transaction->id,
        ]);

        // A soft-deleted debit account renders empty, as the relation's default
        // scope already excluded it before.
        [$transaction2, $deletedDebit, $credit2] = $this->transaction('RCP-DELACC');
        $deletedDebit->delete();
        $deletedAccount = ProjectCostReceipt::create([
            'project_cost_id' => $this->cost->id, 'amount' => 10, 'currency_id' => $this->currency->id,
            'date' => '2026-07-01', 'transaction_id' => $transaction2->id,
        ]);

        // A soft-deleted line is ignored, as the lines relation already did.
        [$transaction3, , $credit3] = $this->transaction('RCP-DELLINE');
        $transaction3->lines()->where('debit_base', '>', 0)->first()->delete();
        $deletedLine = ProjectCostReceipt::create([
            'project_cost_id' => $this->cost->id, 'amount' => 10, 'currency_id' => $this->currency->id,
            'date' => '2026-07-01', 'transaction_id' => $transaction3->id,
        ]);

        Livewire::test(ListProjectCostReceipts::class)
            ->assertTableColumnStateSet('debit_account', $debit->name, $noCredit)
            ->assertTableColumnStateSet('credit_account', null, $noCredit)
            ->assertTableColumnStateSet('debit_account', null, $deletedAccount)
            ->assertTableColumnStateSet('credit_account', $credit2->name, $deletedAccount)
            ->assertTableColumnStateSet('debit_account', null, $deletedLine)
            ->assertTableColumnStateSet('credit_account', $credit3->name, $deletedLine);
    }
}
