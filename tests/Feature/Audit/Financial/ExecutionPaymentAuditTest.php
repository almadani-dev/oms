<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Tables\ExecutionPaymentsTable;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Models\AuditEvent;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;

/**
 * صرف مبالغ التنفيذ — ExecutionPaymentResource (slug execution-payments) /
 * App\Models\ProjectCostBudgetsPayment.
 *
 * The mirror image of the disbursement mapping: this resource is named
 * "ExecutionPayment" but writes ProjectCostBudgetsPayment rows, while the
 * disbursement resource is named "…Payment" and writes ProjectCostBudget
 * rows. Two workflows, two models, names crossed — which is exactly why the
 * audit alias is workflow-based.
 */
class ExecutionPaymentAuditTest extends FinancialAuditTestCase
{
    private const ALIAS = 'execution_payment';

    /**
     * An execution payment draws down a real disbursement, so the fixture
     * builds one through its own real Create page first.
     */
    private function budget(array $fx): ProjectCostBudget
    {
        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [[
            'project_cost_id' => $fx['projectCost']->id,
            // Non-zero deductions: FinancialTransactionBalanceGuard rejects a
            // line that is zero on both sides, so a 0% admin/transfer split
            // is not a valid disbursement to begin with.
            'original_amount' => 5000,
            'administrative_percentage' => 10,
            'transfer_percentage' => 10,
            'disbursement_currency_id' => $fx['currency']->id,
            'fx_rate' => 1,
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'partner_id' => $fx['partner']->id,
            'date' => '2026-07-01',
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
        ]]);

        AuditEvent::query()->delete();

        return $budget;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(array $fx, ProjectCostBudget $budget, array $overrides = []): array
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

    private function create(array $data): ProjectCostBudgetsPayment
    {
        return $this->invoke(new CreateExecutionPayment, 'handleRecordCreation', [$data]);
    }

    private function update(ProjectCostBudgetsPayment $record, array $data): ProjectCostBudgetsPayment
    {
        return $this->invoke(new EditExecutionPayment, 'handleRecordUpdate', [$record, $data]);
    }

    public function test_create_writes_exactly_one_correct_financial_event(): void
    {
        $fx = $this->fixture();
        $budget = $this->budget($fx);

        $payment = $this->create($this->data($fx, $budget));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'created');
        $new = $event->new_values;

        $this->assertSame('financial', $event->event_category);
        $this->assertSame(self::ALIAS, $new['operation_type']);
        $this->assertSame((string) $payment->id, $event->subject_key);

        $this->assertSame($payment->transaction_id, $new['transaction_id']);
        $this->assertStringStartsWith('PAY-', $new['transaction_number']);

        $this->assertSame('1200.00', $new['amount']);
        $this->assertSame('USD', $new['currency_code']);

        // The budget being drawn down, labelled by its own transaction number.
        $this->assertSame($budget->id, $new['project_cost_budget_id']);
        $this->assertStringContainsString('BUD-', $new['project_cost_budget_label']);
        $this->assertSame($fx['project']->id, $new['project_id']);
        $this->assertSame($fx['projectCost']->id, $new['project_cost_id']);

        $this->assertSame($fx['beneficiaryAccount']->id, $new['beneficiary_account_id']);
        $this->assertStringContainsString('مستفيد', $new['beneficiary_account_label']);
        $this->assertSame($fx['destinationAccount']->id, $new['credit_account_id']);
        $this->assertStringContainsString('وجهة', $new['credit_account_label']);
        $this->assertSame('2026-07-20', $new['date']);

        foreach ($new as $key => $value) {
            $this->assertIsNotArray($value, "Payload key [{$key}] must be a scalar.");
        }

        $this->assertSame(0, AuditEvent::whereIn('subject_type', ['transaction', 'transaction_line'])->count());
    }

    public function test_edit_records_accurate_old_and_new_values(): void
    {
        $fx = $this->fixture();
        $budget = $this->budget($fx);
        $payment = $this->create($this->data($fx, $budget));

        AuditEvent::query()->delete();

        $this->update($payment->fresh(), $this->data($fx, $budget, [
            'amount' => 1500,
            'beneficiary_account_id' => $fx['altDebitAccount']->id,
            'beneficiary_account_type_id' => $fx['altDebitAccount']->account_type_id,
            'beneficiary_bank_type_id' => $fx['altDebitAccount']->bank_type_id,
        ]));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'updated');

        $this->assertEqualsCanonicalizing(['amount', 'beneficiary_account_id'], $event->changed_fields);
        $this->assertSame('1200.00', $event->old_values['amount']);
        $this->assertSame('1500.00', $event->new_values['amount']);
        $this->assertStringContainsString('مستفيد', $event->old_values['beneficiary_account_label']);
        $this->assertStringContainsString('مدين بديل', $event->new_values['beneficiary_account_label']);
        $this->assertArrayNotHasKey('credit_account_id', $event->new_values);
    }

    public function test_a_no_op_edit_creates_no_event(): void
    {
        $fx = $this->fixture();
        $budget = $this->budget($fx);
        $payment = $this->create($this->data($fx, $budget));

        AuditEvent::query()->delete();

        $this->update($payment->fresh(), $this->data($fx, $budget));

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_delete_preserves_the_full_pre_delete_snapshot(): void
    {
        $fx = $this->fixture();
        $budget = $this->budget($fx);
        $payment = $this->create($this->data($fx, $budget));
        $transactionNumber = $payment->transaction->transaction_number;

        AuditEvent::query()->delete();

        ExecutionPaymentsTable::deletePayment($payment->fresh());

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'deleted');
        $old = $event->old_values;

        $this->assertNull($event->new_values);
        $this->assertSame($transactionNumber, $old['transaction_number']);
        $this->assertSame('1200.00', $old['amount']);
        $this->assertStringContainsString('مستفيد', $old['beneficiary_account_label']);
        $this->assertStringContainsString('وجهة', $old['credit_account_label']);
        $this->assertNotNull(ProjectCostBudgetsPayment::withTrashed()->find($payment->id)->deleted_at);
    }
}
