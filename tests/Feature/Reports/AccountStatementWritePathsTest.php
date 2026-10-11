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
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementProjection;
use App\Services\Reports\AccountStatement\AccountStatementProjectionVerifier;
use App\Services\Reports\AccountStatement\AccountStatementProjector;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Audit\Financial\FinancialAuditTestCase;

/**
 * The "تقرير كشف الحساب" projection through the REAL financial write paths
 * (every create / edit / delete of the five workflows and the opening
 * balance, driven through their actual Filament page methods): after each
 * one the projection equals the ledger (independent verifier: zero
 * differences) and the statement shows it at once — no queue, no rebuild.
 * Backdating and moving a line to another account touch only the affected
 * checkpoints. Any failure rolls the statement projection back with the
 * business record, transaction, lines, balances, comprehensive projection
 * and audit event.
 */
class AccountStatementWritePathsTest extends FinancialAuditTestCase
{
    private function assertProjectionMatchesLedger(string $when): void
    {
        $result = app(AccountStatementProjectionVerifier::class)->verify(balanceSamples: 10);

        $this->assertSame(0, $result['differences'], $when.': '.implode(' | ', $result['samples']));
    }

    /**
     * The statement of one account as the page computes it.
     *
     * @return array{opening: string, debit: string, credit: string, closing: string, lines: int, rows: array<int, array<string, mixed>>}
     */
    private function statement(Account $account, string $from = '2026-01-01', string $to = '2026-12-31'): array
    {
        $report = app(AccountStatementReport::class);
        $scope = AccountStatementScope::resolve(AccountStatementFilters::make($from, $to, [$account->id]));
        $summary = $report->summarize($scope);

        return $report->accountSummaries($scope, 1)[0] + ['rows' => iterator_to_array($report->rows($scope, $summary['plan']), false)];
    }

    private function ledgerBalance(Account $account, string $before): string
    {
        $value = DB::table('transaction_lines as tl')->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->whereNull('tl.deleted_at')->whereNull('t.deleted_at')
            ->where('tl.account_id', $account->id)->where('t.transaction_time', '<', $before)
            ->selectRaw('SUM(CASE WHEN tl.debit_base > 0 THEN tl.amount_currency ELSE -tl.amount_currency END) as b')
            ->value('b');

        return number_format((float) $value, 2, '.', '');
    }

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

    public function test_receipt_create_backdate_move_account_delete_are_reflected_at_once(): void
    {
        $fx = $this->fixture();

        $receipt = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
        $this->assertProjectionMatchesLedger('receipt create');
        $statement = $this->statement($fx['debitAccount']);
        $this->assertSame('250.00', $statement['debit']);
        $this->assertSame('250.00', $statement['closing']);
        $this->assertCount(1, $statement['rows']);

        // A later receipt, then the first one is BACKDATED before it and
        // moved to another debit account with a new amount.
        $later = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx, ['amount' => 100, 'date' => '2026-08-01'])]);
        $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$receipt->fresh(), $this->receiptData($fx, [
            'amount' => 900,
            'date' => '2026-03-02',
            'debit_account_id' => $fx['altDebitAccount']->id,
        ])]);
        $this->assertProjectionMatchesLedger('receipt backdated + moved');

        $this->assertSame('100.00', $this->statement($fx['debitAccount'])['closing'], 'Gone from the old account.');
        $alt = $this->statement($fx['altDebitAccount']);
        $this->assertSame('900.00', $alt['closing']);
        $this->assertSame('2026-03-02', substr($alt['rows'][0]['date'], 0, 10));
        $this->assertSame('900.00', $this->statement($fx['altDebitAccount'], '2026-04-01', '2026-12-31')['opening'], 'Opening after the backdated date moves with it.');

        // The credit account: the backdated line now precedes the later one.
        $credit = $this->statement($fx['creditAccount']);
        $this->assertSame(['-900.00', '-1000.00'], array_column($credit['rows'], 'running_balance'));
        $this->assertSame($this->ledgerBalance($fx['creditAccount'], '2027-01-01 00:00:00'), $credit['closing']);

        ProjectCostReceiptsTable::deleteReceipt($receipt->fresh());
        $this->assertProjectionMatchesLedger('receipt delete');
        $this->assertSame('0.00', $this->statement($fx['altDebitAccount'])['closing']);
        $this->assertSame(['-100.00'], array_column($this->statement($fx['creditAccount'])['rows'], 'running_balance'));
        $this->assertNotNull($later);
    }

    public function test_general_expense_create_edit_delete(): void
    {
        $fx = $this->fixture();

        $expense = $this->invoke(new CreateGeneralExpense, 'handleRecordCreation', [$this->expenseData($fx)]);
        $this->assertProjectionMatchesLedger('expense create');

        // Lines are force-deleted and recreated by this edit path; moved
        // forward a month.
        $this->invoke(new EditGeneralExpense, 'handleRecordUpdate', [$expense->fresh(), $this->expenseData($fx, ['amount' => 410.55, 'date' => '2026-08-20'])]);
        $this->assertProjectionMatchesLedger('expense edit');
        $this->assertSame('410.55', $this->statement($fx['debitAccount'])['debit']);
        $this->assertSame(0, $this->statement($fx['debitAccount'], '2026-07-01', '2026-07-31')['lines']);

        GeneralExpensesTable::deleteExpense($expense->fresh());
        $this->assertProjectionMatchesLedger('expense delete');
        $this->assertSame(0, $this->statement($fx['debitAccount'])['lines']);
    }

    public function test_general_exchange_across_two_currencies(): void
    {
        $fx = $this->fixture();

        $exchange = $this->invoke(new CreateGeneralExchange, 'handleRecordCreation', [$this->exchangeData($fx)]);
        $this->assertProjectionMatchesLedger('exchange create');

        $report = app(AccountStatementReport::class);
        $scope = AccountStatementScope::resolve(AccountStatementFilters::make('2026-01-01', '2026-12-31', [$fx['sourceAccount']->id, $fx['altDestinationAccount']->id]));
        $currencies = collect($report->summarize($scope)['currencies'])->pluck('currency_code')->sort()->values()->all();
        $this->assertSame(['ILS', 'USD'], $currencies, 'One statement, totals kept per currency.');

        $this->invoke(new EditGeneralExchange, 'handleRecordUpdate', [$exchange->fresh(), $this->exchangeData($fx, ['original_amount' => 2000, 'fx_rate' => 3.5])]);
        $this->assertProjectionMatchesLedger('exchange edit');

        GeneralExchangesTable::deleteExchange($exchange->fresh());
        $this->assertProjectionMatchesLedger('exchange delete');
    }

    public function test_disbursement_and_execution_payment_create_edit_delete(): void
    {
        $fx = $this->fixture();

        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$this->disbursementData($fx, ['original_amount' => 5000, 'date' => '2026-07-01'])]);
        $this->assertProjectionMatchesLedger('disbursement create');

        $payment = $this->invoke(new CreateExecutionPayment, 'handleRecordCreation', [$this->executionData($fx, $budget)]);
        $this->assertProjectionMatchesLedger('execution create');

        $this->invoke(new EditExecutionPayment, 'handleRecordUpdate', [$payment->fresh(), $this->executionData($fx, $budget, ['amount' => 700])]);
        $this->assertProjectionMatchesLedger('execution edit');
        $this->assertSame('700.00', $this->statement($fx['beneficiaryAccount'])['debit']);

        ExecutionPaymentsTable::deletePayment($payment->fresh());
        $this->assertProjectionMatchesLedger('execution delete');
        $this->assertSame(0, $this->statement($fx['beneficiaryAccount'])['lines']);

        $this->invoke(new EditProjectCostBudgetsPayment, 'handleRecordUpdate', [$budget->fresh(), $this->disbursementData($fx, ['original_amount' => 3000, 'date' => '2026-06-05'])]);
        $this->assertProjectionMatchesLedger('disbursement backdated edit');

        ProjectCostBudgetsPaymentsTable::deletePayment($budget->fresh());
        $this->assertProjectionMatchesLedger('disbursement delete');
        $this->assertSame(0, DB::table(AccountStatementProjection::LINES)->count());
        $this->assertSame(0, DB::table(AccountStatementProjection::DAILY_TOTALS)->count(), 'Empty checkpoints are removed, not left at zero.');
        $this->assertSame(0, DB::table(AccountStatementProjection::ACCOUNT_TOTALS)->count());
    }

    public function test_an_opening_balance_is_in_the_statement_with_the_new_account(): void
    {
        $fx = $this->fixture();

        $account = $this->invoke(new CreateAccount, 'handleRecordCreation', [[
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

        $this->assertProjectionMatchesLedger('opening balance');
        $this->assertSame('2500.00', $this->statement(Account::where('account_code', 'ACC-OPEN')->firstOrFail())['closing']);
        $this->assertNotNull($account);
    }

    // ---------------------------------------------------------------------
    // Atomicity.
    // ---------------------------------------------------------------------

    public function test_a_statement_projection_failure_rolls_back_the_whole_financial_operation(): void
    {
        $fx = $this->fixture();
        $balancesBefore = $this->balances($fx['debitAccount'], $fx['creditAccount']);

        Schema::drop(AccountStatementProjection::ACCOUNT_TOTALS);

        try {
            $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
            $this->fail('Expected the statement projection write to fail.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, ProjectCostReceipt::withTrashed()->count(), 'The receipt must roll back.');
        $this->assertSame(0, Transaction::withTrashed()->count(), 'The transaction must roll back.');
        $this->assertSame(0, TransactionLine::withTrashed()->count(), 'Every line must roll back.');
        $this->assertSame(0, AuditEvent::count(), 'The audit event must roll back.');
        $this->assertSame($balancesBefore, $this->balances($fx['debitAccount'], $fx['creditAccount']), 'Balances restored exactly.');
        $this->assertSame(0, DB::table(ComprehensiveReportProjection::LINES)->count(), 'The comprehensive projection rolls back too.');
        $this->assertSame(0, DB::table(AccountStatementProjection::LINES)->count(), 'The statement lines roll back.');
        $this->assertSame(0, DB::table(AccountStatementProjection::DAILY_TOTALS)->count(), 'The statement checkpoints roll back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_later_failure_in_the_same_operation_rolls_the_statement_projection_back(): void
    {
        $fx = $this->fixture();
        $receipt = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);

        $snapshot = fn () => collect(AccountStatementProjection::TABLES)
            ->reject(fn ($t) => $t === AccountStatementProjection::STATE)
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])
            ->all();
        $before = $snapshot();

        // The audit insert runs AFTER the projection sync inside the edit.
        Schema::drop('audit_events');

        try {
            $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$receipt->fresh(), $this->receiptData($fx, ['amount' => 999, 'date' => '2026-02-01'])]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertEquals($before, $snapshot());
        $this->assertProjectionMatchesLedger('after rolled-back edit');
    }

    public function test_the_projector_refuses_to_write_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        app(AccountStatementProjector::class)->syncTransactions([1]);
    }
}
