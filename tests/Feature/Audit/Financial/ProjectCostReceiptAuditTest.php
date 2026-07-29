<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
use App\Models\AuditEvent;
use App\Models\ProjectCostReceipt;

/**
 * المبالغ المستلمة — ProjectCostReceiptResource / App\Models\ProjectCostReceipt.
 *
 * One receipt create/edit/delete = exactly one `financial` AuditEvent under
 * the stable alias `project_cost_receipt`, carrying the resulting transaction
 * identifiers and never a TransactionLine dump.
 */
class ProjectCostReceiptAuditTest extends FinancialAuditTestCase
{
    private const ALIAS = 'project_cost_receipt';

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
            'notes' => 'ملاحظة الاستلام',
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

    private function update(ProjectCostReceipt $record, array $data): ProjectCostReceipt
    {
        return $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$record, $data]);
    }

    public function test_create_writes_exactly_one_correct_financial_event(): void
    {
        $fx = $this->fixture();

        $receipt = $this->create($this->data($fx));

        $this->assertSame(1, AuditEvent::count(), 'One logical action must produce exactly one event.');

        $event = $this->onlyEventFor(self::ALIAS, 'created');

        $this->assertSame('financial', $event->event_category);
        $this->assertSame((string) $receipt->id, $event->subject_key);
        $this->assertNull($event->old_values);

        $new = $event->new_values;

        $this->assertSame(self::ALIAS, $new['operation_type']);
        $this->assertSame($receipt->transaction_id, $new['transaction_id']);
        $this->assertSame($receipt->transaction->transaction_number, $new['transaction_number']);
        $this->assertSame($event->subject_label, $receipt->transaction->transaction_number);

        // Money as a decimal string, never a float.
        $this->assertSame('250.00', $new['amount']);
        $this->assertIsString($new['amount']);

        // Currency, project and account identity plus bounded labels.
        $this->assertSame($fx['currency']->id, $new['currency_id']);
        $this->assertSame('USD', $new['currency_code']);
        $this->assertSame($fx['project']->id, $new['project_id']);
        $this->assertStringContainsString('مشروع تجريبي', $new['project_label']);
        $this->assertSame($fx['projectCost']->id, $new['project_cost_id']);
        $this->assertSame($fx['debitAccount']->id, $new['debit_account_id']);
        $this->assertStringContainsString('مدين', $new['debit_account_label']);
        $this->assertSame($fx['creditAccount']->id, $new['credit_account_id']);
        $this->assertStringContainsString('دائن', $new['credit_account_label']);
        $this->assertSame($fx['partner']->id, $new['partner_id']);
        $this->assertSame('شريك تجريبي', $new['partner_label']);
        $this->assertSame('2026-07-18', $new['date']);
    }

    public function test_the_payload_never_dumps_transaction_lines(): void
    {
        $fx = $this->fixture();
        $this->create($this->data($fx));

        $new = $this->onlyEventFor(self::ALIAS, 'created')->new_values;

        foreach ($new as $key => $value) {
            $this->assertIsNotArray($value, "Payload key [{$key}] must be a scalar, not a nested structure.");
        }

        foreach (['lines', 'transaction_lines', 'debit_base', 'credit_base', 'amount_currency', 'line_role'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $new);
        }
    }

    public function test_no_transaction_or_transaction_line_event_is_ever_written(): void
    {
        $fx = $this->fixture();
        $this->create($this->data($fx));

        $this->assertSame(0, AuditEvent::whereIn('subject_type', ['transaction', 'transaction_line'])->count());
    }

    public function test_edit_records_only_the_changed_fields_with_both_labels(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->update($receipt->fresh(), $this->data($fx, [
            'amount' => 400,
            'debit_account_id' => $fx['altDebitAccount']->id,
            'debit_account_type_id' => $fx['altDebitAccount']->account_type_id,
            'debit_bank_type_id' => $fx['altDebitAccount']->bank_type_id,
        ]));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'updated');

        $this->assertEqualsCanonicalizing(['amount', 'debit_account_id'], $event->changed_fields);

        $this->assertSame('250.00', $event->old_values['amount']);
        $this->assertSame('400.00', $event->new_values['amount']);

        // Both sides of a reassigned account keep their own label.
        $this->assertSame($fx['debitAccount']->id, $event->old_values['debit_account_id']);
        $this->assertStringContainsString('مدين', $event->old_values['debit_account_label']);
        $this->assertSame($fx['altDebitAccount']->id, $event->new_values['debit_account_id']);
        $this->assertStringContainsString('مدين بديل', $event->new_values['debit_account_label']);
        $this->assertNotSame($event->old_values['debit_account_label'], $event->new_values['debit_account_label']);

        // Untouched business fields stay out of both sides entirely.
        $this->assertArrayNotHasKey('credit_account_id', $event->old_values);
        $this->assertArrayNotHasKey('currency_id', $event->new_values);

        // Context is always carried, and never reported as a change.
        $this->assertSame($receipt->transaction->transaction_number, $event->new_values['transaction_number']);
        $this->assertNotContains('transaction_number', $event->changed_fields);
        $this->assertNotContains('debit_account_label', $event->changed_fields);
    }

    public function test_a_no_op_edit_creates_no_event(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->update($receipt->fresh(), $this->data($fx));

        $this->assertSame(0, AuditEvent::count(), 'A save that changed nothing is not an auditable state change.');
    }

    public function test_delete_preserves_the_full_pre_delete_snapshot(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));
        $transactionNumber = $receipt->transaction->transaction_number;

        AuditEvent::query()->delete();

        ProjectCostReceiptsTable::deleteReceipt($receipt->fresh());

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'deleted');
        $old = $event->old_values;

        $this->assertNull($event->new_values);
        $this->assertSame($transactionNumber, $old['transaction_number']);
        $this->assertSame($transactionNumber, $event->subject_label);
        $this->assertSame('250.00', $old['amount']);
        $this->assertStringContainsString('مدين', $old['debit_account_label']);
        $this->assertStringContainsString('دائن', $old['credit_account_label']);
        $this->assertSame('USD', $old['currency_code']);
        $this->assertNotNull(ProjectCostReceipt::withTrashed()->find($receipt->id)->deleted_at);
    }
}
