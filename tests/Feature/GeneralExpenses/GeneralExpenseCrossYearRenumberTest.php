<?php

namespace Tests\Feature\GeneralExpenses;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Models\AuditEvent;
use App\Models\GeneralExpense;
use App\Models\Transaction;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionVerifier;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\Financial\FinancialAuditTestCase;

/**
 * Editing a General Expense keeps its GEN-YYYY-NNNN number in step with the
 * year of its date (the Create rule): same year = same number; another year
 * = the next number of that year's GEN sequence, written in the same DB
 * transaction as the rest of the edit and audited as a changed field.
 */
class GeneralExpenseCrossYearRenumberTest extends FinancialAuditTestCase
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
            'description' => 'فاتورة كهرباء',
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'date' => '2026-02-01',
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

    private function update(GeneralExpense $record, array $data, ?EditGeneralExpense $page = null): GeneralExpense
    {
        return $this->invoke($page ?? new EditGeneralExpense, 'handleRecordUpdate', [$record, $data]);
    }

    private function number(GeneralExpense $expense): string
    {
        return (string) Transaction::withTrashed()->whereKey($expense->transaction_id)->value('transaction_number');
    }

    /**
     * Another transaction already holding $number (the sequence the generator
     * must continue), optionally soft-deleted.
     */
    private function holdNumber(array $fx, string $number, bool $softDeleted = false): Transaction
    {
        $transaction = Transaction::create([
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'transaction_type_id' => $fx['transactionType']->id,
            'transaction_number' => $number,
            'transaction_time' => substr($number, 4, 4).'-06-01 10:00:00',
        ]);

        if ($softDeleted) {
            $transaction->delete();
        }

        return $transaction;
    }

    /**
     * Everything an edit may change, for before/after comparisons.
     *
     * @return array<string, mixed>
     */
    private function state(GeneralExpense $expense, array $fx): array
    {
        $expense = GeneralExpense::withTrashed()->findOrFail($expense->id);
        $transaction = Transaction::withTrashed()->findOrFail($expense->transaction_id);

        return [
            'expense' => [$expense->amount, (string) $expense->date, $expense->description, $expense->updated_at?->toDateTimeString()],
            'transaction' => [$transaction->transaction_number, (string) $transaction->transaction_time, $transaction->fiscal_year_id, $transaction->description],
            'lines' => DB::table('transaction_lines')->where('transaction_id', $transaction->id)->orderBy('id')
                ->get(['id', 'account_id', 'currency_id', 'debit_base', 'credit_base', 'line_role'])->map(fn ($l) => (array) $l)->all(),
            'balances' => $this->balances($fx['debitAccount'], $fx['creditAccount']),
            'projection' => DB::table(ComprehensiveReportProjection::LINES)->where('transaction_id', $transaction->id)->orderBy('line_id')
                ->get(['line_id', 'transaction_time', 'debit', 'credit'])->map(fn ($l) => (array) $l)->all(),
            'audit_events' => AuditEvent::count(),
        ];
    }

    private function assertProjectionMatchesLedger(): void
    {
        $result = app(ComprehensiveReportProjectionVerifier::class)->verify();

        $this->assertSame(0, $result['differences'], implode(' | ', $result['samples']));
    }

    // =========================================================
    // same year
    // =========================================================

    public function test_a_same_year_date_edit_keeps_the_number_and_does_not_audit_it_as_changed(): void
    {
        $fx = $this->fixture();
        $this->holdNumber($fx, 'GEN-2026-0041');
        $expense = $this->create($this->data($fx));
        $this->assertSame('GEN-2026-0042', $this->number($expense));

        AuditEvent::query()->delete();
        $this->update($expense->fresh(), $this->data($fx, ['date' => '2026-10-01']));

        $this->assertSame('GEN-2026-0042', $this->number($expense));
        $this->assertSame('2026-10-01', substr((string) $expense->fresh()->transaction->transaction_time, 0, 10));

        $event = $this->onlyEventFor(self::ALIAS, 'updated');
        $this->assertSame(['date'], $event->changed_fields);
        $this->assertNotContains('transaction_number', $event->changed_fields);
        $this->assertSame('GEN-2026-0042', $event->old_values['transaction_number']);
        $this->assertSame('GEN-2026-0042', $event->new_values['transaction_number']);
    }

    // =========================================================
    // cross year
    // =========================================================

    public function test_moving_2026_to_2025_takes_the_next_number_of_the_2025_sequence(): void
    {
        $fx = $this->fixture();
        $this->holdNumber($fx, 'GEN-2026-0041');
        $this->holdNumber($fx, 'GEN-2025-0042');
        $expense = $this->create($this->data($fx));
        $this->assertSame('GEN-2026-0042', $this->number($expense));
        $before = $this->state($expense, $fx);

        AuditEvent::query()->delete();
        $this->update($expense->fresh(), $this->data($fx, ['date' => '2025-03-15']));

        // The real 2025 sequence (…0042 exists), not a year swap (GEN-2025-0042).
        $this->assertSame('GEN-2025-0043', $this->number($expense));
        $after = $this->state($expense, $fx);
        $this->assertSame('2025-03-15', substr($after['transaction'][1], 0, 10));

        // Same money: balances unchanged; the lines are re-created by the
        // existing edit logic with identical accounts, currency and amounts.
        $this->assertSame($before['balances'], $after['balances']);
        $strip = fn (array $lines) => array_map(fn (array $l) => array_diff_key($l, ['id' => true]), $lines);
        $this->assertSame($strip($before['lines']), $strip($after['lines']));

        // Audit: one event, transaction_number reported old -> new.
        $event = $this->onlyEventFor(self::ALIAS, 'updated');
        $this->assertEqualsCanonicalizing(['date', 'transaction_number'], $event->changed_fields);
        $this->assertSame('GEN-2026-0042', $event->old_values['transaction_number']);
        $this->assertSame('GEN-2025-0043', $event->new_values['transaction_number']);
        $this->assertSame('GEN-2025-0043', $event->subject_label);

        // Projection follows the ledger (moved to 2025, same amounts).
        $this->assertProjectionMatchesLedger();
        $this->assertSame(['2025'], array_values(array_unique(array_map(fn (array $l) => substr((string) $l['transaction_time'], 0, 4), $after['projection']))));
        $this->assertCount(2, $after['projection']);
    }

    public function test_moving_2025_to_2026_takes_the_next_number_of_the_2026_sequence(): void
    {
        $fx = $this->fixture();
        $expense = $this->create($this->data($fx, ['date' => '2025-12-31']));
        $this->assertSame('GEN-2025-0001', $this->number($expense));
        $this->create($this->data($fx, ['date' => '2026-01-05']));   // GEN-2026-0001
        $this->create($this->data($fx, ['date' => '2026-01-06']));   // GEN-2026-0002

        $this->update($expense->fresh(), $this->data($fx, ['date' => '2026-01-01']));

        $this->assertSame('GEN-2026-0003', $this->number($expense));
        // The vacated 2025 number is not reused by anything else.
        $this->assertSame(0, Transaction::withTrashed()->where('transaction_number', 'GEN-2025-0001')->count());
        $this->assertProjectionMatchesLedger();

        // And back to the original year: again the real sequence, never the old number.
        $this->update($expense->fresh(), $this->data($fx, ['date' => '2025-06-01']));
        $this->assertSame('GEN-2025-0001', $this->number($expense));
    }

    public function test_the_target_year_sequence_counts_soft_deleted_numbers(): void
    {
        $fx = $this->fixture();
        $this->holdNumber($fx, 'GEN-2025-0007', softDeleted: true);
        $expense = $this->create($this->data($fx));

        $this->update($expense->fresh(), $this->data($fx, ['date' => '2025-05-05']));

        $this->assertSame('GEN-2025-0008', $this->number($expense));
    }

    public function test_a_number_not_in_gen_year_form_is_never_rewritten(): void
    {
        $fx = $this->fixture();
        $expense = $this->create($this->data($fx));
        DB::table('transactions')->where('id', $expense->transaction_id)->update(['transaction_number' => 'LEGACY-77']);

        $this->update($expense->fresh(), $this->data($fx, ['date' => '2025-05-05']));

        $this->assertSame('LEGACY-77', $this->number($expense));
    }

    // =========================================================
    // collision retry and atomicity
    // =========================================================

    public function test_a_number_collision_is_retried_and_the_edit_succeeds(): void
    {
        $fx = $this->fixture();
        $this->holdNumber($fx, 'GEN-2025-0005');
        $expense = $this->create($this->data($fx));
        $before = $this->state($expense, $fx);

        // First attempt: a stale candidate another writer already took (the
        // race the UNIQUE index guards). The retry recomputes from MAX.
        $page = new class extends EditGeneralExpense
        {
            public int $calls = 0;

            protected function generateTransactionNumber(string $prefix): string
            {
                return ++$this->calls === 1 ? $prefix.'0005' : parent::generateTransactionNumber($prefix);
            }
        };

        AuditEvent::query()->delete();
        $this->update($expense->fresh(), $this->data($fx, ['date' => '2025-05-05']), $page);

        $this->assertSame(2, $page->calls);
        $this->assertSame('GEN-2025-0006', $this->number($expense));
        $this->assertSame($before['balances'], $this->state($expense, $fx)['balances'], 'Balances applied exactly once.');
        $this->assertSame(2, DB::table('transaction_lines')->where('transaction_id', $expense->transaction_id)->whereNull('deleted_at')->count());
        $this->assertSame(1, AuditEvent::count());
        $this->assertProjectionMatchesLedger();
    }

    public function test_a_failure_after_every_write_rolls_back_the_whole_edit_including_the_renumber(): void
    {
        $fx = $this->fixture();
        $this->holdNumber($fx, 'GEN-2025-0009');
        $expense = $this->create($this->data($fx));
        $before = $this->state($expense, $fx);

        // Fails the moment the edit's audit event is inserted — the last
        // write, after the renumber, lines, balances, expense and projection.
        AuditEvent::created(function (): void {
            throw new \RuntimeException('forced failure');
        });

        try {
            $this->update($expense->fresh(), $this->data($fx, ['date' => '2025-05-05', 'amount' => 999, 'description' => 'تغيير']));
            $this->fail('The edit should have failed.');
        } catch (\Throwable $e) {
            // The audit logger (Required mode) reports it as its own failure.
            $messages = [];
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                $messages[] = $cause->getMessage();
            }
            $this->assertContains('forced failure', $messages, implode(' <- ', $messages));
        }

        $this->assertSame($before, $this->state($expense, $fx));
        $this->assertSame('GEN-2026-0001', $before['transaction'][0]);
        $this->assertSame(0, Transaction::withTrashed()->where('transaction_number', 'GEN-2025-0010')->count());
    }

    public function test_a_failing_renumber_rolls_back_before_anything_else_is_written(): void
    {
        $fx = $this->fixture();
        $expense = $this->create($this->data($fx));
        $before = $this->state($expense, $fx);

        $page = new class extends EditGeneralExpense
        {
            protected function renumberForDateYear(Transaction $transaction, ?string $originalNumber, mixed $date): ?string
            {
                $number = parent::renumberForDateYear($transaction, $originalNumber, $date);

                throw new \RuntimeException('renumber failed after writing '.$number);
            }
        };

        try {
            $this->update($expense->fresh(), $this->data($fx, ['date' => '2025-05-05']), $page);
            $this->fail('The edit should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('renumber failed after writing GEN-2025-0001', $e->getMessage());
        }

        $this->assertSame($before, $this->state($expense, $fx));
    }
}
