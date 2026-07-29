<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use App\Services\Audit\Financial\FinancialAuditRecorder;
use App\Services\Audit\Financial\FinancialAuditSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * OMS Task 9B.3 §ATOMICITY — the guarantee this whole phase exists for.
 *
 * A REQUIRED financial audit insert that cannot be persisted must take the
 * ENTIRE financial operation down with it: the source record, the Transaction,
 * every TransactionLine, and every account balance increment/decrement. No
 * partial financial operation may remain.
 *
 * The failure is forced the bluntest honest way — the `audit_events` table is
 * dropped, so the insert AuditLogger performs raises a real driver error,
 * which AuditFailureMode::Required turns into an AuditPersistenceException
 * inside the workflow's own already-open DB::transaction().
 *
 * The receipt workflow stands in for all five: every one of them records from
 * inside its own transaction through the same FinancialAuditRecorder, which
 * refuses to run at all when no transaction is open (proved separately below).
 */
class FinancialAuditAtomicityTest extends FinancialAuditTestCase
{
    private function breakAuditStorage(): void
    {
        Schema::drop('audit_events');
    }

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

    public function test_a_failed_required_audit_rolls_back_the_whole_create(): void
    {
        $fx = $this->fixture();

        $balancesBefore = $this->balances($fx['debitAccount'], $fx['creditAccount']);

        $this->breakAuditStorage();

        try {
            $this->create($this->data($fx));
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame(0, ProjectCostReceipt::withTrashed()->count(), 'The receipt must have rolled back.');
        $this->assertSame(0, Transaction::withTrashed()->count(), 'The transaction must have rolled back.');
        $this->assertSame(0, TransactionLine::withTrashed()->count(), 'Every line must have rolled back.');
        $this->assertSame(
            $balancesBefore,
            $this->balances($fx['debitAccount'], $fx['creditAccount']),
            'Both account balances must be restored exactly.',
        );
        $this->assertSame(0, DB::transactionLevel(), 'No transaction may be left open.');
    }

    public function test_a_failed_required_audit_rolls_back_the_whole_edit(): void
    {
        $fx = $this->fixture();

        $receipt = $this->create($this->data($fx));

        $balancesAfterCreate = $this->balances($fx['debitAccount'], $fx['creditAccount'], $fx['altDebitAccount']);
        $linesAfterCreate = TransactionLine::orderBy('id')
            ->get(['id', 'account_id', 'debit_base', 'credit_base'])
            ->map(fn ($line) => $line->only(['id', 'account_id', 'debit_base', 'credit_base']))
            ->all();

        $this->breakAuditStorage();

        try {
            $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [
                $receipt->fresh(),
                $this->data($fx, [
                    'amount' => 900,
                    'debit_account_id' => $fx['altDebitAccount']->id,
                    'debit_account_type_id' => $fx['altDebitAccount']->account_type_id,
                    'debit_bank_type_id' => $fx['altDebitAccount']->bank_type_id,
                ]),
            ]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame('250.00', ProjectCostReceipt::find($receipt->id)->amount, 'The receipt update must have rolled back.');

        $linesAfterRollback = TransactionLine::orderBy('id')
            ->get(['id', 'account_id', 'debit_base', 'credit_base'])
            ->map(fn ($line) => $line->only(['id', 'account_id', 'debit_base', 'credit_base']))
            ->all();

        $this->assertEquals($linesAfterCreate, $linesAfterRollback, 'The previous lines must be restored exactly.');
        $this->assertSame(
            $balancesAfterCreate,
            $this->balances($fx['debitAccount'], $fx['creditAccount'], $fx['altDebitAccount']),
            'Balances must be restored exactly, and the replacement account must be untouched.',
        );
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_required_audit_rolls_back_the_whole_delete(): void
    {
        $fx = $this->fixture();

        $receipt = $this->create($this->data($fx));

        $balancesAfterCreate = $this->balances($fx['debitAccount'], $fx['creditAccount']);
        $lineCount = TransactionLine::count();

        $this->breakAuditStorage();

        try {
            ProjectCostReceiptsTable::deleteReceipt($receipt->fresh());
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertNotNull(ProjectCostReceipt::find($receipt->id), 'The receipt must still exist.');
        $this->assertNull(ProjectCostReceipt::withTrashed()->find($receipt->id)->deleted_at);
        $this->assertNotNull(Transaction::find($receipt->transaction_id), 'The transaction must still exist.');
        $this->assertSame($lineCount, TransactionLine::count(), 'The lines must still exist.');
        $this->assertSame(
            $balancesAfterCreate,
            $this->balances($fx['debitAccount'], $fx['creditAccount']),
            'Balances must be left exactly as they were.',
        );
        $this->assertSame(0, DB::transactionLevel());
    }

    /**
     * The mirror-image guarantee: a financial AuditEvent never survives a
     * business transaction its caller rolls back.
     */
    public function test_the_event_rolls_back_with_a_surrounding_business_failure(): void
    {
        $fx = $this->fixture();

        $balancesBefore = $this->balances($fx['debitAccount'], $fx['creditAccount']);

        try {
            DB::transaction(function () use ($fx): void {
                $this->create($this->data($fx));

                throw new LogicException('business failure after the audited write');
            });
            $this->fail('Expected the business exception to propagate.');
        } catch (LogicException $e) {
            $this->assertSame('business failure after the audited write', $e->getMessage());
        }

        $this->assertSame(0, \App\Models\AuditEvent::count(), 'The audit row must roll back with its business transaction.');
        $this->assertSame(0, ProjectCostReceipt::withTrashed()->count());
        $this->assertSame(0, Transaction::withTrashed()->count());
        $this->assertSame($balancesBefore, $this->balances($fx['debitAccount'], $fx['creditAccount']));
    }

    /**
     * The structural rule that makes every other guarantee here hold: the
     * recorder never opens its own transaction, and refuses to write at all
     * outside the caller's.
     */
    public function test_the_recorder_refuses_to_write_outside_an_open_transaction(): void
    {
        $fx = $this->fixture();
        $receipt = $this->create($this->data($fx));

        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);

        app(FinancialAuditRecorder::class)->deleted(
            FinancialAuditSubject::ProjectCostReceipt,
            $receipt,
            ['operation_type' => 'project_cost_receipt'],
        );
    }

    public function test_a_successful_audited_workflow_leaves_no_transaction_open(): void
    {
        $fx = $this->fixture();

        $this->assertSame(0, DB::transactionLevel());

        $this->create($this->data($fx));

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(1, \App\Models\AuditEvent::count());
    }
}
