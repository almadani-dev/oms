<?php

namespace Tests\Feature\Reports;

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Tables\ExecutionPaymentsTable;
use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Pages\EditGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Tables\GeneralExchangesTable;
use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Tables\GeneralExpensesTable;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable;
use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
use App\Models\AccountType;
use App\Models\AuditEvent;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Audit\Crud\AuditedCrudService;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionRebuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionVerifier;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjector;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportSummaries;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Audit\Financial\FinancialAuditTestCase;

/**
 * The projection of "تقرير الحركات المالية الشامل" through the REAL write
 * paths: every create / edit / delete of the five financial workflows and
 * the opening balance, driven through their actual Filament page methods.
 * After each one the projection must equal the ledger exactly (independent
 * verifier: zero differences) and the report must reflect it at once — no
 * queue, no rebuild. A failure anywhere rolls the projection back with the
 * ledger, balances and audit event.
 */
class ComprehensiveReportProjectionWritePathsTest extends FinancialAuditTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // An empty, READY projection: from here on only the workflows write it.
        app(ComprehensiveReportProjectionRebuilder::class)->rebuild();
    }

    private function assertProjectionMatchesLedger(string $when): void
    {
        $result = app(ComprehensiveReportProjectionVerifier::class)->verify();

        $this->assertSame(0, $result['differences'], $when.': '.implode(' | ', $result['samples']));
    }

    private function projectedLines(int $transactionId): int
    {
        return DB::table(ComprehensiveReportProjection::LINES)->where('transaction_id', $transactionId)->count();
    }

    /**
     * The live report, as the page computes it on "عرض".
     *
     * @return array<string, mixed>
     */
    private function report(string $from = '2026-01-01', string $to = '2026-12-31'): array
    {
        return app(ComprehensiveReportSummaries::class)->compute(ComprehensiveFinancialTransactionsFilters::make($from, $to));
    }

    // ---------------------------------------------------------------------
    // Data of each workflow (the payloads its form really produces).
    // ---------------------------------------------------------------------

    private function receiptData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'project_cost_id' => $fx['projectCost']->id,
            'amount' => 250,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-18',
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'notes' => null,
            'debit_account_id' => $fx['debitAccount']->id,
            'debit_account_type_id' => $fx['debitAccount']->account_type_id,
            'debit_bank_type_id' => $fx['debitAccount']->bank_type_id,
            'credit_account_id' => $fx['creditAccount']->id,
            'credit_account_type_id' => $fx['creditAccount']->account_type_id,
            'credit_bank_type_id' => $fx['creditAccount']->bank_type_id,
            'receipt_image' => null,
        ], $overrides);
    }

    private function expenseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'amount' => 320,
            'currency_id' => $fx['currency']->id,
            'description' => 'فاتورة كهرباء',
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'date' => '2026-07-18',
            'notes' => null,
            'debit_account_id' => $fx['debitAccount']->id,
            'debit_account_type_id' => $fx['debitAccount']->account_type_id,
            'debit_bank_type_id' => $fx['debitAccount']->bank_type_id,
            'credit_account_id' => $fx['creditAccount']->id,
            'credit_account_type_id' => $fx['creditAccount']->account_type_id,
            'credit_bank_type_id' => $fx['creditAccount']->bank_type_id,
            'expense_image' => null,
        ], $overrides);
    }

    private function exchangeData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'original_amount' => 1000,
            'source_currency_id' => $fx['currency']->id,
            'administrative_percentage' => 10,
            'transfer_percentage' => 10,
            'disbursement_currency_id' => $fx['altCurrency']->id,
            'fx_rate' => 3.75,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-18',
            'notes' => null,
            'source_account_id' => $fx['sourceAccount']->id,
            'source_account_type_id' => $fx['sourceAccount']->account_type_id,
            'source_bank_type_id' => $fx['sourceAccount']->bank_type_id,
            'admin_account_id' => $fx['adminAccount']->id,
            'admin_account_type_id' => $fx['adminAccount']->account_type_id,
            'admin_bank_type_id' => $fx['adminAccount']->bank_type_id,
            'transfer_account_id' => $fx['transferAccount']->id,
            'transfer_account_type_id' => $fx['transferAccount']->account_type_id,
            'transfer_bank_type_id' => $fx['transferAccount']->bank_type_id,
            'destination_account_id' => $fx['altDestinationAccount']->id,
            'destination_account_type_id' => $fx['altDestinationAccount']->account_type_id,
            'destination_bank_type_id' => $fx['altDestinationAccount']->bank_type_id,
            'exchange_image' => null,
        ], $overrides);
    }

    private function disbursementData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'project_cost_id' => $fx['projectCost']->id,
            'original_amount' => 1000,
            'administrative_percentage' => 10,
            'transfer_percentage' => 10,
            'disbursement_currency_id' => $fx['currency']->id,
            'fx_rate' => 1,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-18',
            'notes' => null,
            'source_account_id' => $fx['sourceAccount']->id,
            'source_account_type_id' => $fx['sourceAccount']->account_type_id,
            'source_bank_type_id' => $fx['sourceAccount']->bank_type_id,
            'admin_account_id' => $fx['adminAccount']->id,
            'admin_account_type_id' => $fx['adminAccount']->account_type_id,
            'admin_bank_type_id' => $fx['adminAccount']->bank_type_id,
            'transfer_account_id' => $fx['transferAccount']->id,
            'transfer_account_type_id' => $fx['transferAccount']->account_type_id,
            'transfer_bank_type_id' => $fx['transferAccount']->bank_type_id,
            'destination_account_id' => $fx['destinationAccount']->id,
            'destination_account_type_id' => $fx['destinationAccount']->account_type_id,
            'destination_bank_type_id' => $fx['destinationAccount']->bank_type_id,
            'payment_image' => null,
        ], $overrides);
    }

    private function executionData(array $fx, ProjectCostBudget $budget, array $overrides = []): array
    {
        return array_merge([
            'project_cost_budget_id' => $budget->id,
            'amount' => 1200,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-20',
            'notes' => null,
            'beneficiary_account_id' => $fx['beneficiaryAccount']->id,
            'beneficiary_account_type_id' => $fx['beneficiaryAccount']->account_type_id,
            'beneficiary_bank_type_id' => $fx['beneficiaryAccount']->bank_type_id,
            'credit_account_id' => $fx['destinationAccount']->id,
            'credit_account_type_id' => $fx['destinationAccount']->account_type_id,
            'credit_bank_type_id' => $fx['destinationAccount']->bank_type_id,
            'payment_image' => null,
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // Create / edit / delete of every workflow.
    // ---------------------------------------------------------------------

    public function test_receipt_create_edit_delete_keep_the_projection_exact_and_immediate(): void
    {
        $fx = $this->fixture();

        $receipt = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
        $this->assertSame(2, $this->projectedLines($receipt->transaction_id));
        $this->assertProjectionMatchesLedger('receipt create');
        $this->assertSame(2, $this->report()['line_count'], 'Visible in the report immediately.');
        $this->assertSame(250.0, $this->report()['currency_summaries'][0]['total_debit']);

        // In-place line update (this edit path does not recreate lines),
        // with a new date and a new debit account.
        $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$receipt->fresh(), $this->receiptData($fx, [
            'amount' => 900,
            'date' => '2026-09-02',
            'debit_account_id' => $fx['altDebitAccount']->id,
        ])]);
        $this->assertProjectionMatchesLedger('receipt edit');
        $this->assertSame(900.0, $this->report()['currency_summaries'][0]['total_debit']);
        $this->assertSame(0, $this->report('2026-07-01', '2026-07-31')['line_count'], 'The old month no longer holds it.');
        $this->assertSame(2, $this->report('2026-09-01', '2026-09-30')['line_count']);

        ProjectCostReceiptsTable::deleteReceipt($receipt->fresh());
        $this->assertSame(0, $this->projectedLines($receipt->transaction_id));
        $this->assertProjectionMatchesLedger('receipt delete');
        $this->assertSame(0, $this->report()['line_count']);
    }

    public function test_general_expense_create_edit_delete(): void
    {
        $fx = $this->fixture();

        $expense = $this->invoke(new CreateGeneralExpense, 'handleRecordCreation', [$this->expenseData($fx)]);
        $this->assertSame(2, $this->projectedLines($expense->transaction_id));
        $this->assertProjectionMatchesLedger('expense create');

        // Lines are force-deleted and recreated by this edit path.
        $this->invoke(new EditGeneralExpense, 'handleRecordUpdate', [$expense->fresh(), $this->expenseData($fx, ['amount' => 410.55])]);
        $this->assertSame(2, $this->projectedLines($expense->transaction_id));
        $this->assertProjectionMatchesLedger('expense edit');
        $this->assertSame(410.55, $this->report()['currency_summaries'][0]['total_debit']);

        GeneralExpensesTable::deleteExpense($expense->fresh());
        $this->assertSame(0, $this->projectedLines($expense->transaction_id));
        $this->assertProjectionMatchesLedger('expense delete');
    }

    public function test_general_exchange_create_edit_delete_across_two_currencies(): void
    {
        $fx = $this->fixture();

        $exchange = $this->invoke(new CreateGeneralExchange, 'handleRecordCreation', [$this->exchangeData($fx)]);
        $created = $this->projectedLines($exchange->transaction_id);
        $this->assertGreaterThanOrEqual(2, $created);
        $this->assertProjectionMatchesLedger('exchange create');
        $this->assertSame(2, $this->report()['currencies_count']);
        $this->assertSame(1, $this->report()['transaction_count'], 'A two-currency transaction is still one transaction.');

        $this->invoke(new EditGeneralExchange, 'handleRecordUpdate', [$exchange->fresh(), $this->exchangeData($fx, ['original_amount' => 2000, 'fx_rate' => 3.5])]);
        $this->assertProjectionMatchesLedger('exchange edit');

        GeneralExchangesTable::deleteExchange($exchange->fresh());
        $this->assertSame(0, $this->projectedLines($exchange->transaction_id));
        $this->assertProjectionMatchesLedger('exchange delete');
    }

    public function test_project_disbursement_create_edit_delete(): void
    {
        $fx = $this->fixture();

        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$this->disbursementData($fx)]);
        $this->assertSame(4, $this->projectedLines($budget->transaction_id));
        $this->assertProjectionMatchesLedger('disbursement create');

        $this->invoke(new EditProjectCostBudgetsPayment, 'handleRecordUpdate', [$budget->fresh(), $this->disbursementData($fx, ['original_amount' => 3000, 'date' => '2026-08-05'])]);
        $this->assertProjectionMatchesLedger('disbursement edit');
        $this->assertSame(4, $this->report('2026-08-01', '2026-08-31')['line_count']);

        ProjectCostBudgetsPaymentsTable::deletePayment($budget->fresh());
        $this->assertSame(0, $this->projectedLines($budget->transaction_id));
        $this->assertProjectionMatchesLedger('disbursement delete');
    }

    public function test_execution_payment_create_edit_delete(): void
    {
        $fx = $this->fixture();
        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$this->disbursementData($fx, ['original_amount' => 5000, 'date' => '2026-07-01'])]);

        $payment = $this->invoke(new CreateExecutionPayment, 'handleRecordCreation', [$this->executionData($fx, $budget)]);
        $this->assertSame(2, $this->projectedLines($payment->transaction_id));
        $this->assertProjectionMatchesLedger('execution create');

        $this->invoke(new EditExecutionPayment, 'handleRecordUpdate', [$payment->fresh(), $this->executionData($fx, $budget, ['amount' => 700])]);
        $this->assertProjectionMatchesLedger('execution edit');

        ExecutionPaymentsTable::deletePayment($payment->fresh());
        $this->assertSame(0, $this->projectedLines($payment->transaction_id));
        $this->assertProjectionMatchesLedger('execution delete');
        $this->assertSame(4, $this->report()['line_count'], 'Only the disbursement remains.');
    }

    public function test_an_opening_balance_is_projected_with_the_new_account(): void
    {
        $fx = $this->fixture();

        $this->invoke(new CreateAccount, 'handleRecordCreation', [[
            'account_code' => 'ACC-OPEN',
            'name' => 'برصيد افتتاحي',
            'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $fx['currency']->id,
            'is_active' => true,
            'opening_balance' => 2500,
            'opening_balance_date' => '2026-07-18',
            'opening_balance_fx_rate' => 1,
        ]]);

        $this->assertSame(2, $this->projectedLines((int) Transaction::firstOrFail()->id));
        $this->assertProjectionMatchesLedger('opening balance');
        $this->assertSame(2500.0, $this->report()['currency_summaries'][0]['total_debit']);
    }

    public function test_an_account_type_change_re_derives_that_accounts_lines(): void
    {
        $fx = $this->fixture();
        $receipt = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);

        $newType = AccountType::create(['name' => 'نوع جديد']);
        app(AuditedCrudService::class)->update($fx['debitAccount']->fresh(), ['account_type_id' => $newType->id]);

        $this->assertProjectionMatchesLedger('account type change');
        $this->assertSame(
            [$newType->id],
            DB::table(ComprehensiveReportProjection::LINES)->where('account_id', $fx['debitAccount']->id)->pluck('account_type_id')->map(fn ($v) => (int) $v)->all(),
        );

        $byNewType = app(ComprehensiveReportSummaries::class)->compute(ComprehensiveFinancialTransactionsFilters::make('2026-01-01', '2026-12-31', [], null, null, [], $newType->id));
        $this->assertSame(1, $byNewType['line_count']);
        $this->assertSame(1, $byNewType['transaction_count']);
        $this->assertNotNull($receipt);
    }

    // ---------------------------------------------------------------------
    // Atomicity.
    // ---------------------------------------------------------------------

    public function test_a_projection_failure_rolls_back_the_whole_financial_operation(): void
    {
        $fx = $this->fixture();
        $balancesBefore = $this->balances($fx['debitAccount'], $fx['creditAccount']);

        Schema::drop(ComprehensiveReportProjection::LINES);

        try {
            $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
            $this->fail('Expected the projection write to fail.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, ProjectCostReceipt::withTrashed()->count(), 'The receipt must roll back.');
        $this->assertSame(0, Transaction::withTrashed()->count(), 'The transaction must roll back.');
        $this->assertSame(0, TransactionLine::withTrashed()->count(), 'Every line must roll back.');
        $this->assertSame(0, AuditEvent::count(), 'The audit event must roll back.');
        $this->assertSame($balancesBefore, $this->balances($fx['debitAccount'], $fx['creditAccount']), 'Balances must be restored exactly.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_later_failure_in_the_same_operation_rolls_the_projection_back_too(): void
    {
        $fx = $this->fixture();
        $receipt = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
        $before = DB::table(ComprehensiveReportProjection::LINES)->orderBy('line_id')->get()->map(fn ($r) => (array) $r)->all();
        $totalsBefore = DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->get()->map(fn ($r) => (array) $r)->all();

        // The audit insert runs AFTER the projection sync inside the edit.
        Schema::drop('audit_events');

        try {
            $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$receipt->fresh(), $this->receiptData($fx, ['amount' => 999, 'date' => '2026-10-01'])]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertEquals($before, DB::table(ComprehensiveReportProjection::LINES)->orderBy('line_id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertEquals($totalsBefore, DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->get()->map(fn ($r) => (array) $r)->all());
        $this->assertProjectionMatchesLedger('after rolled-back edit');
    }

    public function test_the_projector_refuses_to_write_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        app(ComprehensiveReportProjector::class)->syncTransaction(1);
    }

    /**
     * Static guard: every application file that writes ledger rows must also
     * reach the projector. A new write path that forgets it fails here.
     */
    public function test_every_ledger_write_site_syncs_the_projection(): void
    {
        $missing = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            // The stress generator bulk-loads its own isolated database and is
            // followed by a projection rebuild (docs/STRESS_ENVIRONMENT.md).
            if (! str_ends_with($path, '.php') || str_contains($path, '/Services/Stress/')) {
                continue;
            }

            $source = file_get_contents($path);

            if (preg_match('/TransactionLine::create\(|Transaction::create\(|->lines\(\)->(force)?[dD]elete\(|\$transaction->delete\(\)/', $source) === 1
                && ! str_contains($source, 'ComprehensiveReportProjector')) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'Ledger write sites without a projection sync.');
    }
}
