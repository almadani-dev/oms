<?php

namespace Tests\Feature\Reports;

use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Tables\ExecutionPaymentsTable;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable;
use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Services\Audit\Crud\AuditedCrudService;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportParityChecker;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjection;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionRebuilder;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionVerifier;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjector;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportReader;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Audit\Financial\FinancialAuditTestCase;

/**
 * The real-time projection of "تقرير الجهات المانحة" through the REAL write
 * paths: every create / edit / delete of receipts, disbursements and
 * execution payments, driven through their actual Filament page methods,
 * plus the project / cost-item edits the report reads live. After each one
 * the projection must equal the authoritative tables (independent verifier:
 * zero differences), the projection report must equal the legacy service's
 * report (parity: zero differences) and the change must be visible at once —
 * no queue, no rebuild. A projection failure rolls the whole financial
 * operation back.
 */
class DonorFinancialReportProjectionWritePathsTest extends FinancialAuditTestCase
{
    private const NO_FILTERS = ['date_from' => null, 'date_to' => null, 'project_status_id' => null, 'project_super_id' => null, 'currency_id' => null, 'project_id' => null];

    /**
     * The audit fixture, its project given to a donor, plus a second
     * project of the same donor with a cost item in the other currency.
     *
     * @return array<string, mixed>
     */
    private function donorFixture(): array
    {
        $fx = $this->fixture();
        $fx['partner']->forceFill(['is_donor' => true])->save();
        $fx['project']->forceFill(['donor_id' => $fx['partner']->id, 'approval_date' => '2026-01-15'])->save();

        $fx['otherProject'] = Project::create([
            'name' => 'مشروع ثان', 'code' => 'EDU-002', 'donor_id' => $fx['partner']->id, 'approval_date' => '2026-03-01',
            'project_super_id' => $fx['project']->project_super_id, 'project_status_id' => $fx['project']->project_status_id,
        ]);
        $fx['otherCost'] = ProjectCost::create([
            'project_id' => $fx['otherProject']->id, 'account_type_id' => $fx['accountType']->id, 'amount' => 5000, 'currency_id' => $fx['currency']->id,
        ]);

        return $fx;
    }

    /** Independent verifier + legacy-vs-projection parity, both at zero. */
    private function assertExact(array $fx, string $when, array $filters = []): void
    {
        $verify = app(DonorReportProjectionVerifier::class)->verify(markState: false);
        $this->assertSame(0, $verify['differences'], $when.' (verifier): '.implode(' | ', $verify['samples']));

        $parity = app(DonorReportParityChecker::class)->compare($fx['partner']->id, $filters);
        $this->assertSame(0, $parity['differences'], $when.' (parity): '.implode(' | ', $parity['samples']));
    }

    /** @return array<string, mixed> the report as the page reads it at «عرض» */
    private function summary(array $fx, array $filters = []): array
    {
        return app(DonorReportReader::class)->summary(DonorReportScope::resolve($fx['partner']->id, $filters + self::NO_FILTERS));
    }

    /** @return array<int, array<string, mixed>> */
    private function movements(array $fx, array $filters = []): array
    {
        return iterator_to_array(app(DonorReportReader::class)->movements(DonorReportScope::resolve($fx['partner']->id, $filters + self::NO_FILTERS)), false);
    }

    private function projected(int $kind, int $sourceId): int
    {
        return DB::table(DonorReportProjection::MOVEMENTS)->where('kind', $kind)->where('source_id', $sourceId)->count();
    }

    // ---------------------------------------------------------------------
    // Workflow payloads (what each form really produces).
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

    private function disbursementData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'project_cost_id' => $fx['projectCost']->id,
            'original_amount' => 1000,
            'administrative_percentage' => 10,
            'transfer_percentage' => 5,
            'disbursement_currency_id' => $fx['altCurrency']->id,
            'fx_rate' => 3.5,
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
            'payment_image' => null,
        ], $overrides);
    }

    /** A disbursement that stays in the cost currency (execution payments are paid in it). */
    private function sameCurrencyDisbursement(array $fx, array $overrides = []): array
    {
        return $this->disbursementData($fx, array_merge([
            'original_amount' => 5000,
            'disbursement_currency_id' => $fx['currency']->id,
            'fx_rate' => 1,
            'destination_account_id' => $fx['destinationAccount']->id,
            'destination_account_type_id' => $fx['destinationAccount']->account_type_id,
            'destination_bank_type_id' => $fx['destinationAccount']->bank_type_id,
        ], $overrides));
    }

    private function executionData(array $fx, ProjectCostBudget $budget, array $overrides = []): array
    {
        return array_merge([
            'project_cost_budget_id' => $budget->id,
            'amount' => 700,
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
    // Receipts.
    // ---------------------------------------------------------------------

    public function test_receipt_create_backdate_move_and_delete_are_exact_and_immediate(): void
    {
        $fx = $this->donorFixture();

        $receipt = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx, ['notes' => 'دفعة أولى'])]);
        $this->assertSame(1, $this->projected(DonorReportProjection::RECEIPT, $receipt->id));
        $this->assertSame(2, DB::table(DonorReportProjection::LINES)->where('source_id', $receipt->id)->count());
        $this->assertExact($fx, 'receipt create');
        $this->assertSame(250.0, $this->summary($fx)['cost_summary'][0]['received'], 'Visible at once, no rebuild.');
        $this->assertSame(1, $this->summary($fx)['movements_count']);
        $this->assertSame('دفعة أولى', $this->movements($fx)[0]['notes']);

        // Backdated, new amount.
        $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$receipt->fresh(), $this->receiptData($fx, ['amount' => 900, 'date' => '2026-02-02'])]);
        $this->assertExact($fx, 'receipt backdate');
        $this->assertSame('2026-02-02', $this->movements($fx)[0]['date']);
        $this->assertSame(900.0, $this->summary($fx)['cost_summary'][0]['received']);

        // Moved to the donor's other project (another cost item).
        $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$receipt->fresh(), $this->receiptData($fx, ['amount' => 900, 'date' => '2026-02-02', 'project_cost_id' => $fx['otherCost']->id])]);
        $this->assertExact($fx, 'receipt moved to another cost item');
        $this->assertSame($fx['otherProject']->fresh()->code, $this->movements($fx)[0]['project_code']);
        $this->assertSame(0, $this->summary($fx, ['project_id' => $fx['project']->id])['movements_count']);
        $this->assertSame(1, $this->summary($fx, ['project_id' => $fx['otherProject']->id])['movements_count']);

        ProjectCostReceiptsTable::deleteReceipt($receipt->fresh());
        $this->assertSame(0, $this->projected(DonorReportProjection::RECEIPT, $receipt->id));
        $this->assertSame(0, DB::table(DonorReportProjection::LINES)->count());
        $this->assertExact($fx, 'receipt delete');
        $this->assertSame(0, $this->summary($fx)['movements_count']);
    }

    // ---------------------------------------------------------------------
    // Disbursements (with deductions) and execution payments.
    // ---------------------------------------------------------------------

    public function test_disbursement_with_deductions_create_edit_delete(): void
    {
        $fx = $this->donorFixture();

        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$this->disbursementData($fx)]);
        $this->assertSame(1, $this->projected(DonorReportProjection::BUDGET, $budget->id));
        $this->assertSame(
            [DonorReportProjection::DEDUCTION_ADMIN, DonorReportProjection::DEDUCTION_TRANSFER],
            DB::table(DonorReportProjection::LINES)->where('source_id', $budget->id)->where('deduction', '>', 0)->orderBy('deduction')->pluck('deduction')->map(fn ($v) => (int) $v)->all(),
        );
        $this->assertExact($fx, 'disbursement create');

        $source = $this->summary($fx)['disb_source_summary'][0];
        $this->assertSame(['USD', 1000.0, 100.0, 50.0, 850.0], [$source['currency_code'], $source['original'], $source['admin_deduction'], $source['transfer_deduction'], $source['after_deductions']]);
        $this->assertSame(['ILS', 2975.0], [$this->summary($fx)['disb_final_summary'][0]['currency_code'], $this->summary($fx)['disb_final_summary'][0]['final']]);

        // New amount, percentages (transfer down to 0% — its line disappears) and date.
        $this->invoke(new EditProjectCostBudgetsPayment, 'handleRecordUpdate', [$budget->fresh(), $this->disbursementData($fx, ['original_amount' => 2000, 'administrative_percentage' => 5, 'transfer_percentage' => 0, 'date' => '2026-05-05'])]);
        $this->assertExact($fx, 'disbursement edit');
        $source = $this->summary($fx)['disb_source_summary'][0];
        $this->assertSame([2000.0, 100.0, 0.0, 1900.0], [$source['original'], $source['admin_deduction'], $source['transfer_deduction'], $source['after_deductions']]);
        $this->assertSame('2026-05-05 00:00:00', $this->movements($fx)[0]['date']);

        ProjectCostBudgetsPaymentsTable::deletePayment($budget->fresh());
        $this->assertSame(0, $this->projected(DonorReportProjection::BUDGET, $budget->id));
        $this->assertExact($fx, 'disbursement delete');
        $this->assertSame([], $this->summary($fx)['disb_source_summary']);
    }

    public function test_execution_payment_create_edit_delete_and_its_budget_deletion(): void
    {
        $fx = $this->donorFixture();
        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$this->sameCurrencyDisbursement($fx)]);

        $payment = $this->invoke(new CreateExecutionPayment, 'handleRecordCreation', [$this->executionData($fx, $budget)]);
        $this->assertSame(1, $this->projected(DonorReportProjection::PAYMENT, $payment->id));
        $this->assertExact($fx, 'execution create');
        $this->assertSame(700.0, $this->summary($fx)['disb_final_summary'][0]['execution_paid']);

        $this->invoke(new EditExecutionPayment, 'handleRecordUpdate', [$payment->fresh(), $this->executionData($fx, $budget, ['amount' => 1200, 'date' => '2026-07-01'])]);
        $this->assertExact($fx, 'execution edit');
        $this->assertSame(1200.0, $this->summary($fx)['disb_final_summary'][0]['execution_paid']);

        ExecutionPaymentsTable::deletePayment($payment->fresh());
        $this->assertSame(0, $this->projected(DonorReportProjection::PAYMENT, $payment->id));
        $this->assertExact($fx, 'execution delete');

        // A disbursement deleted while it still has a payment: the report
        // drops the payment with it (its budget is gone) — re-derived with
        // the budget, in the same transaction.
        $second = $this->invoke(new CreateExecutionPayment, 'handleRecordCreation', [$this->executionData($fx, $budget, ['amount' => 300])]);
        $this->assertSame(1, $this->projected(DonorReportProjection::PAYMENT, $second->id));
        ProjectCostBudgetsPaymentsTable::deletePayment($budget->fresh());
        $this->assertSame(0, $this->projected(DonorReportProjection::PAYMENT, $second->id));
        $this->assertExact($fx, 'budget deleted with a live payment');
        $this->assertSame(0, $this->summary($fx)['movements_count']);
    }

    public function test_a_disbursement_moved_to_another_cost_item_takes_its_payments_along(): void
    {
        $fx = $this->donorFixture();
        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$this->sameCurrencyDisbursement($fx)]);
        $payment = $this->invoke(new CreateExecutionPayment, 'handleRecordCreation', [$this->executionData($fx, $budget)]);

        $this->invoke(new EditProjectCostBudgetsPayment, 'handleRecordUpdate', [$budget->fresh(), $this->sameCurrencyDisbursement($fx, ['project_cost_id' => $fx['otherCost']->id])]);

        $this->assertSame($fx['otherCost']->id, (int) DB::table(DonorReportProjection::MOVEMENTS)->where('kind', DonorReportProjection::PAYMENT)->where('source_id', $payment->id)->value('project_cost_id'));
        $this->assertExact($fx, 'disbursement moved');
        $this->assertSame(2, $this->summary($fx, ['project_id' => $fx['otherProject']->id])['movements_count']);
    }

    // ---------------------------------------------------------------------
    // Project / cost-item data is read live: no projection maintenance.
    // ---------------------------------------------------------------------

    public function test_project_donor_status_type_date_and_cost_changes_are_read_live(): void
    {
        $fx = $this->donorFixture();
        $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
        $before = DB::table(DonorReportProjection::MOVEMENTS)->get()->map(fn ($r) => (array) $r)->all();

        $closed = ProjectStatus::create(['name' => 'مغلق']);
        $otherType = ProjectSuper::create(['name' => 'برنامج آخر', 'code_prefix' => 'OTH']);
        $crud = app(AuditedCrudService::class);

        $crud->update($fx['project']->fresh(), ['project_status_id' => $closed->id, 'project_super_id' => $otherType->id, 'approval_date' => '2026-09-09']);
        $this->assertExact($fx, 'project status / type / date change');
        $this->assertSame(1, $this->summary($fx, ['project_status_id' => $closed->id])['movements_count']);
        $this->assertSame(1, $this->summary($fx, ['project_super_id' => $otherType->id])['projects_count']);
        $this->assertSame(0, $this->summary($fx, ['date_to' => '2026-08-31'])['movements_count'], 'The new approval date is outside the period.');
        $this->assertSame(1, $this->summary($fx, ['date_to' => '2026-08-31'])['projects_count']);

        // A cost item's amount / currency (planned side) and its deletion.
        $crud->update($fx['projectCost']->fresh(), ['amount' => 1234, 'currency_id' => $fx['altCurrency']->id]);
        $this->assertExact($fx, 'cost item amount / currency');
        $crud->delete($fx['projectCost']->fresh());
        $this->assertExact($fx, 'cost item soft-deleted');
        $this->assertSame(0, $this->summary($fx)['movements_count'], 'A deleted cost item hides its movements, as before.');

        // The project moves to another donor.
        $other = $fx['altPartner'];
        $other->forceFill(['is_donor' => true])->save();
        DB::table('projects_costs')->where('id', $fx['projectCost']->id)->update(['deleted_at' => null]);
        $crud->update($fx['project']->fresh(), ['donor_id' => $other->id]);
        $this->assertExact($fx, 'project moved away (old donor)');
        $this->assertSame(1, app(DonorReportReader::class)->summary(DonorReportScope::resolve($other->id, self::NO_FILTERS))['movements_count']);

        $this->assertEquals($before, DB::table(DonorReportProjection::MOVEMENTS)->get()->map(fn ($r) => (array) $r)->all(), 'None of these edits touched the projection.');
    }

    // ---------------------------------------------------------------------
    // Current SoftDelete semantics, reproduced as they are.
    // ---------------------------------------------------------------------

    public function test_a_budget_whose_transaction_is_soft_deleted_stays_in_the_summary_but_not_in_the_movements(): void
    {
        $fx = $this->donorFixture();
        $budget = $this->invoke(new CreateProjectCostBudgetsPayment, 'handleRecordCreation', [$this->disbursementData($fx)]);

        // Not a write path of the application — a pre-existing data state.
        Transaction::whereKey($budget->transaction_id)->update(['deleted_at' => now()]);
        app(DonorReportProjectionRebuilder::class)->rebuild();

        $this->assertSame(0, (int) DB::table(DonorReportProjection::MOVEMENTS)->where('source_id', $budget->id)->value('listed'));
        $this->assertExact($fx, 'known inconsistency reproduced');
        $this->assertSame(1000.0, $this->summary($fx)['disb_source_summary'][0]['original'], 'Still counted by the disbursement summary.');
        $this->assertSame(0, $this->summary($fx)['movements_count'], 'Not listed among the movements.');
    }

    // ---------------------------------------------------------------------
    // Atomicity.
    // ---------------------------------------------------------------------

    public function test_a_donor_projection_failure_rolls_back_the_whole_financial_operation(): void
    {
        $fx = $this->donorFixture();
        $balancesBefore = $this->balances($fx['debitAccount'], $fx['creditAccount']);

        Schema::drop(DonorReportProjection::LINES);

        try {
            $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
            $this->fail('Expected the donor projection write to fail.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, ProjectCostReceipt::withTrashed()->count(), 'The receipt must roll back.');
        $this->assertSame(0, Transaction::withTrashed()->count(), 'The transaction must roll back.');
        $this->assertSame(0, TransactionLine::withTrashed()->count(), 'Every line must roll back.');
        $this->assertSame(0, DB::table(ComprehensiveReportProjection::LINES)->count(), 'The ledger projections must roll back.');
        $this->assertSame(0, DB::table(DonorReportProjection::MOVEMENTS)->count(), 'The donor movement row must roll back.');
        $this->assertSame(0, AuditEvent::count(), 'No audit event of a write that did not happen.');
        $this->assertSame($balancesBefore, $this->balances($fx['debitAccount'], $fx['creditAccount']), 'Balances must be restored exactly.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_later_failure_in_the_same_operation_rolls_the_donor_projection_back_too(): void
    {
        $fx = $this->donorFixture();
        $receipt = $this->invoke(new CreateProjectCostReceipt, 'handleRecordCreation', [$this->receiptData($fx)]);
        $snapshot = fn () => [
            DB::table(DonorReportProjection::MOVEMENTS)->get()->map(fn ($r) => (array) $r)->all(),
            DB::table(DonorReportProjection::LINES)->orderBy('line_id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $snapshot();

        // The audit insert runs AFTER the donor projection sync inside the edit.
        Schema::drop('audit_events');

        try {
            $this->invoke(new EditProjectCostReceipt, 'handleRecordUpdate', [$receipt->fresh(), $this->receiptData($fx, ['amount' => 999, 'date' => '2026-10-01'])]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertEquals($before, $snapshot());
        $verify = app(DonorReportProjectionVerifier::class)->verify(markState: false);
        $this->assertSame(0, $verify['differences'], implode(' | ', $verify['samples']));
    }

    public function test_the_projector_refuses_to_write_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        app(DonorReportProjector::class)->syncReceipts([1]);
    }

    /**
     * Static guard: every application file that writes a receipt, a
     * disbursement or an execution payment together with its ledger (it
     * syncs the ledger projection) must also reach the donor projector.
     */
    public function test_every_donor_report_write_site_syncs_the_projection(): void
    {
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, '.php') || str_contains($path, '/Services/Stress/') || str_contains($path, '/Services/Reports/')) {
                continue;
            }

            $source = file_get_contents($path);

            $writesLedger = preg_match('/ComprehensiveReportProjector::class\)->(syncTransaction|removeTransaction)\(/', $source) === 1;
            $touchesSource = preg_match('/ProjectCostReceipt|ProjectCostBudget|deleteReceipt|deletePayment/', $source) === 1;

            if ($writesLedger && $touchesSource && substr_count($source, 'DonorReportProjector::class)->sync') < substr_count($source, 'ComprehensiveReportProjector::class)->')) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'Receipt / disbursement / execution-payment write sites without a donor projection sync.');
    }
}
