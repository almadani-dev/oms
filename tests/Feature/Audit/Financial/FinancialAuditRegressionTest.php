<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Audit\Crud\AuditSubjectRegistry;
use App\Services\Audit\Financial\FinancialAccountRole;
use App\Services\Audit\Financial\FinancialAuditSubject;
use Illuminate\Validation\ValidationException;

/**
 * OMS Task 9B.3 — duplicate prevention, alias stability, and the regression
 * guards that must survive the audit integration untouched.
 */
class FinancialAuditRegressionTest extends FinancialAuditTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function data(array $fx, array $overrides = []): array
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

    private function create(array $data): ProjectCostReceipt
    {
        return $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$data]);
    }

    // ---- Duplicate prevention -------------------------------------------

    public function test_transaction_and_transaction_line_are_never_registered_as_crud_subjects(): void
    {
        $registry = app(AuditSubjectRegistry::class);

        $this->assertFalse($registry->isRegistered(Transaction::class));
        $this->assertFalse($registry->isRegistered(TransactionLine::class));

        // …and no registered alias could be mistaken for one.
        $aliases = array_values($registry->aliases());

        $this->assertNotContains('transaction', $aliases);
        $this->assertNotContains('transaction_line', $aliases);
    }

    public function test_the_five_workflow_aliases_are_stable_and_distinct_from_their_model_names(): void
    {
        $this->assertSame('project_cost_receipt', FinancialAuditSubject::ProjectCostReceipt->value);
        $this->assertSame('project_disbursement', FinancialAuditSubject::ProjectDisbursement->value);
        $this->assertSame('execution_payment', FinancialAuditSubject::ExecutionPayment->value);
        $this->assertSame('general_expense', FinancialAuditSubject::GeneralExpense->value);
        $this->assertSame('general_exchange', FinancialAuditSubject::GeneralExchange->value);

        // The two crossed workflows really do share neither model nor name.
        $this->assertSame(
            \App\Models\ProjectCostBudget::class,
            \App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource::getModel(),
        );
        $this->assertSame(
            \App\Models\ProjectCostBudgetsPayment::class,
            \App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource::getModel(),
        );
    }

    public function test_every_financial_account_role_is_from_the_closed_vocabulary(): void
    {
        $this->assertSame(
            ['debit', 'credit', 'source', 'destination', 'admin', 'transfer', 'beneficiary'],
            FinancialAccountRole::ALL,
        );
    }

    public function test_an_unknown_account_role_fails_closed(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));

        $this->expectException(\InvalidArgumentException::class);

        app(\App\Services\Audit\Financial\FinancialAuditSnapshotter::class)
            ->projectCostReceipt($receipt, ['made_up_role' => 1]);
    }

    public function test_balance_updates_and_user_tracking_do_not_add_events(): void
    {
        $fx = $this->fixture();

        $this->create($this->data($fx));

        $this->assertSame(1, AuditEvent::count());

        // A bare balance movement (the shape every workflow's STEP "update
        // balances" takes) is never an event of its own.
        Account::find($fx['debitAccount']->id)->increment('current_balance', 10);
        Account::find($fx['creditAccount']->id)->decrement('current_balance', 10);

        $this->assertSame(1, AuditEvent::count());
    }

    public function test_edit_time_line_replacement_still_produces_one_event(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [
            $receipt->fresh(),
            $this->data($fx, ['amount' => 500]),
        ]);

        $this->assertSame(1, AuditEvent::count(), 'Replacing the lines is still one logical action.');
    }

    // ---- Regression: existing behavior is untouched ----------------------

    public function test_the_validation_guards_still_run_before_anything_is_written(): void
    {
        $fx = $this->fixture();

        try {
            // A negative amount is rejected by FinancialAmountGuard, the FIRST
            // guard in the sequence, before the account guard, the balance
            // guard or DB::transaction() are ever reached.
            $this->create($this->data($fx, ['amount' => -5]));
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, ProjectCostReceipt::withTrashed()->count());
        $this->assertSame(0, Transaction::withTrashed()->count());
        $this->assertSame(0, TransactionLine::withTrashed()->count());
        $this->assertSame(0, AuditEvent::count(), 'A rejected action produces no audit event.');
        $this->assertEquals(0, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(50000, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    public function test_a_mismatched_account_is_still_rejected_before_any_write(): void
    {
        $fx = $this->fixture();

        try {
            $this->create($this->data($fx, ['debit_account_id' => $fx['altDestinationAccount']->id]));
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, AuditEvent::count());
        $this->assertSame(0, Transaction::withTrashed()->count());
    }

    public function test_transaction_numbering_still_increments_across_creates(): void
    {
        $fx = $this->fixture();

        $first = $this->create($this->data($fx));
        $second = $this->create($this->data($fx));

        $this->assertSame('REC-2026-0001', $first->transaction->transaction_number);
        $this->assertSame('REC-2026-0002', $second->transaction->transaction_number);
        $this->assertSame(2, AuditEvent::count(), 'Two actions, two events.');
    }

    public function test_the_delete_path_still_reverses_balances_and_removes_the_ledger_rows(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));

        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);

        ProjectCostReceiptsTable::deleteReceipt($receipt->fresh());

        $this->assertEquals(0, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(50000, (float) $fx['creditAccount']->fresh()->current_balance);
        $this->assertNull(Transaction::find($receipt->transaction_id));
        $this->assertSame(0, TransactionLine::count());
        $this->assertNotNull(Transaction::withTrashed()->find($receipt->transaction_id)->deleted_at);
    }

    public function test_the_edit_page_still_redirects_to_the_view_page(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));

        $page = new EditProjectCostReceipt;
        $page->record = $receipt;

        $this->assertStringContainsString(
            (string) $receipt->id,
            $this->invoke($page, 'getRedirectUrl', []),
        );
    }
}
