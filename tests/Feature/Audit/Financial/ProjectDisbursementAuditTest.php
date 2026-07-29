<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable;
use App\Models\AuditEvent;
use App\Models\ProjectCostBudget;

/**
 * صرف مبلغ المشروع — ProjectCostBudgetsPaymentResource (slug
 * project-cost-budgets-disbursements) / App\Models\ProjectCostBudget.
 *
 * Note the real mapping: this resource is named "…Payment" but writes
 * ProjectCostBudget rows. The audit alias follows the WORKFLOW
 * (`project_disbursement`), not the class name — see
 * App\Services\Audit\Financial\FinancialAuditSubject.
 *
 * Four accounts (source/admin/transfer/destination), four transaction lines
 * and two currencies collapse into exactly one AuditEvent.
 */
class ProjectDisbursementAuditTest extends FinancialAuditTestCase
{
    private const ALIAS = 'project_disbursement';

    /**
     * @return array<string, mixed>
     */
    private function data(array $fx, array $overrides = []): array
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

    private function create(array $data): ProjectCostBudget
    {
        return $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$data]);
    }

    private function update(ProjectCostBudget $record, array $data): ProjectCostBudget
    {
        return $this->invoke(new EditProjectCostBudgetsPayment, 'handleRecordUpdate', [$record, $data]);
    }

    public function test_create_writes_exactly_one_correct_financial_event(): void
    {
        $fx = $this->fixture();

        $budget = $this->create($this->data($fx));

        $this->assertSame(1, AuditEvent::count(), 'Four lines and four balance changes are still one action.');

        $event = $this->onlyEventFor(self::ALIAS, 'created');
        $new = $event->new_values;

        $this->assertSame('financial', $event->event_category);
        $this->assertSame(self::ALIAS, $new['operation_type']);
        $this->assertSame((string) $budget->id, $event->subject_key);
        $this->assertNull($event->old_values);

        $this->assertSame($budget->transaction_id, $new['transaction_id']);
        $this->assertSame($budget->transaction->transaction_number, $new['transaction_number']);
        $this->assertStringStartsWith('BUD-', $new['transaction_number']);

        // Every money/percentage/rate value is a fixed-scale decimal string.
        $this->assertSame('1000.00', $new['original_amount']);
        $this->assertSame('10.00', $new['administrative_percentage']);
        $this->assertSame('10.00', $new['transfer_percentage']);
        $this->assertSame('800.00', $new['amount_after_deductions']);
        $this->assertSame('1.000000', $new['fx_rate']);
        $this->assertSame('800.00', $new['final_amount']);

        // All four account roles, each with its bounded label.
        foreach (['source' => 'مصدر', 'admin' => 'إداري', 'transfer' => 'تحويل', 'destination' => 'وجهة'] as $role => $name) {
            $this->assertSame($fx[$role.'Account']->id, $new[$role.'_account_id'], "role {$role}");
            $this->assertStringContainsString($name, $new[$role.'_account_label'], "role {$role}");
        }

        $this->assertSame($fx['currency']->id, $new['source_currency_id']);
        $this->assertSame('USD', $new['source_currency_code']);
        $this->assertSame('USD', $new['disbursement_currency_code']);
        $this->assertSame($fx['project']->id, $new['project_id']);
        $this->assertSame($fx['projectCost']->id, $new['project_cost_id']);
        $this->assertSame('2026-07-18', $new['date']);
    }

    public function test_the_payload_never_dumps_transaction_lines(): void
    {
        $fx = $this->fixture();
        $this->create($this->data($fx));

        $new = $this->onlyEventFor(self::ALIAS, 'created')->new_values;

        foreach ($new as $key => $value) {
            $this->assertIsNotArray($value, "Payload key [{$key}] must be a scalar.");
        }

        $this->assertSame(0, AuditEvent::whereIn('subject_type', ['transaction', 'transaction_line'])->count());
    }

    public function test_edit_records_accurate_old_and_new_values(): void
    {
        $fx = $this->fixture();
        $budget = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        // Cross-currency edit: destination moves to the ILS account at 3.5.
        $this->update($budget->fresh(), $this->data($fx, [
            'original_amount' => 2000,
            'fx_rate' => 3.5,
            'disbursement_currency_id' => $fx['altCurrency']->id,
            'destination_account_id' => $fx['altDestinationAccount']->id,
            'destination_account_type_id' => $fx['altDestinationAccount']->account_type_id,
            'destination_bank_type_id' => $fx['altDestinationAccount']->bank_type_id,
        ]));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'updated');

        $this->assertEqualsCanonicalizing([
            'original_amount',
            'amount_after_deductions',
            'fx_rate',
            'final_amount',
            'disbursement_currency_id',
            'destination_account_id',
        ], $event->changed_fields);

        $this->assertSame('1000.00', $event->old_values['original_amount']);
        $this->assertSame('2000.00', $event->new_values['original_amount']);
        $this->assertSame('1.000000', $event->old_values['fx_rate']);
        $this->assertSame('3.500000', $event->new_values['fx_rate']);
        $this->assertSame('800.00', $event->old_values['final_amount']);
        $this->assertSame('5600.00', $event->new_values['final_amount']);

        // Currency and account satellites are preserved on BOTH sides.
        $this->assertSame('USD', $event->old_values['disbursement_currency_code']);
        $this->assertSame('ILS', $event->new_values['disbursement_currency_code']);
        $this->assertStringContainsString('وجهة', $event->old_values['destination_account_label']);
        $this->assertStringContainsString('وجهة بعملة أخرى', $event->new_values['destination_account_label']);

        // Percentages did not change and stay out of the payload.
        $this->assertArrayNotHasKey('administrative_percentage', $event->new_values);
        $this->assertNotContains('destination_account_label', $event->changed_fields);
    }

    public function test_a_no_op_edit_creates_no_event(): void
    {
        $fx = $this->fixture();
        $budget = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->update($budget->fresh(), $this->data($fx));

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_delete_preserves_the_full_pre_delete_snapshot(): void
    {
        $fx = $this->fixture();
        $budget = $this->create($this->data($fx));
        $transactionNumber = $budget->transaction->transaction_number;

        AuditEvent::query()->delete();

        ProjectCostBudgetsPaymentsTable::deletePayment($budget->fresh());

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'deleted');
        $old = $event->old_values;

        $this->assertNull($event->new_values);
        $this->assertSame($transactionNumber, $old['transaction_number']);
        $this->assertSame('1000.00', $old['original_amount']);
        $this->assertSame('800.00', $old['final_amount']);
        $this->assertStringContainsString('مصدر', $old['source_account_label']);
        $this->assertStringContainsString('وجهة', $old['destination_account_label']);
        $this->assertNotNull(ProjectCostBudget::withTrashed()->find($budget->id)->deleted_at);
    }
}
