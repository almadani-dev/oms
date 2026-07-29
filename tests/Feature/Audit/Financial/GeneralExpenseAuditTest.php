<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Tables\GeneralExpensesTable;
use App\Models\AuditEvent;
use App\Models\GeneralExpense;

/**
 * المصروفات العامة — GeneralExpenseResource / App\Models\GeneralExpense.
 *
 * The only workflow with a short business `description` of its own, which is
 * carried (bounded) so a purpose-only edit is still auditable.
 */
class GeneralExpenseAuditTest extends FinancialAuditTestCase
{
    private const ALIAS = 'general_expense';

    /**
     * @return array<string, mixed>
     */
    private function data(array $fx, array $overrides = []): array
    {
        return array_merge([
            'amount' => 320,
            'currency_id' => $fx['currency']->id,
            'partner_id' => $fx['partner']->id,
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

    private function create(array $data): GeneralExpense
    {
        return $this->invoke(new CreateGeneralExpense, 'handleRecordCreation', [$data]);
    }

    private function update(GeneralExpense $record, array $data): GeneralExpense
    {
        return $this->invoke(new EditGeneralExpense, 'handleRecordUpdate', [$record, $data]);
    }

    public function test_create_writes_exactly_one_correct_financial_event(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'created');
        $new = $event->new_values;

        $this->assertSame('financial', $event->event_category);
        $this->assertSame(self::ALIAS, $new['operation_type']);
        $this->assertSame((string) $expense->id, $event->subject_key);

        $this->assertSame($expense->transaction_id, $new['transaction_id']);
        $this->assertStringStartsWith('GEN-', $new['transaction_number']);

        $this->assertSame('320.00', $new['amount']);
        $this->assertSame('USD', $new['currency_code']);
        $this->assertSame('فاتورة كهرباء', $new['description']);
        $this->assertSame($fx['debitAccount']->id, $new['debit_account_id']);
        $this->assertStringContainsString('مدين', $new['debit_account_label']);
        $this->assertSame($fx['creditAccount']->id, $new['credit_account_id']);
        $this->assertStringContainsString('دائن', $new['credit_account_label']);
        $this->assertSame('شريك تجريبي', $new['partner_label']);
        $this->assertSame('2026-07-18', $new['date']);

        foreach ($new as $key => $value) {
            $this->assertIsNotArray($value, "Payload key [{$key}] must be a scalar.");
        }

        $this->assertSame(0, AuditEvent::whereIn('subject_type', ['transaction', 'transaction_line'])->count());
    }

    public function test_edit_records_accurate_old_and_new_values(): void
    {
        $fx = $this->fixture();
        $expense = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->update($expense->fresh(), $this->data($fx, [
            'amount' => 999.5,
            'description' => 'فاتورة مياه',
            'partner_id' => $fx['altPartner']->id,
        ]));

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'updated');

        $this->assertEqualsCanonicalizing(['amount', 'description', 'partner_id'], $event->changed_fields);
        $this->assertSame('320.00', $event->old_values['amount']);
        $this->assertSame('999.50', $event->new_values['amount']);
        $this->assertSame('فاتورة كهرباء', $event->old_values['description']);
        $this->assertSame('فاتورة مياه', $event->new_values['description']);
        $this->assertSame('شريك تجريبي', $event->old_values['partner_label']);
        $this->assertSame('شريك آخر', $event->new_values['partner_label']);
        $this->assertArrayNotHasKey('debit_account_id', $event->new_values);
    }

    public function test_a_no_op_edit_creates_no_event(): void
    {
        $fx = $this->fixture();
        $expense = $this->create($this->data($fx));

        AuditEvent::query()->delete();

        $this->update($expense->fresh(), $this->data($fx));

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_delete_preserves_the_full_pre_delete_snapshot(): void
    {
        $fx = $this->fixture();
        $expense = $this->create($this->data($fx));
        $transactionNumber = $expense->transaction->transaction_number;

        AuditEvent::query()->delete();

        GeneralExpensesTable::deleteExpense($expense->fresh());

        $this->assertSame(1, AuditEvent::count());

        $event = $this->onlyEventFor(self::ALIAS, 'deleted');
        $old = $event->old_values;

        $this->assertNull($event->new_values);
        $this->assertSame($transactionNumber, $old['transaction_number']);
        $this->assertSame('320.00', $old['amount']);
        $this->assertSame('فاتورة كهرباء', $old['description']);
        $this->assertStringContainsString('مدين', $old['debit_account_label']);
        $this->assertStringContainsString('دائن', $old['credit_account_label']);
        $this->assertNotNull(GeneralExpense::withTrashed()->find($expense->id)->deleted_at);
    }
}
