<?php

namespace Tests\Feature\Integrity;

use App\Services\Integrity\IntegrityCheckReport;
use App\Services\Integrity\TransactionNumberIntegrityChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

class TransactionNumberIntegrityCheckerTest extends TestCase
{
    use IntegrityTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
    }

    private function check(): IntegrityCheckReport
    {
        $report = new IntegrityCheckReport();
        (new TransactionNumberIntegrityChecker())->check($report);

        return $report;
    }

    public function test_unique_transaction_numbers_produce_no_violations(): void
    {
        $this->makeTransaction(['transaction_number' => 'REC-2026-0001']);
        $this->makeTransaction(['transaction_number' => 'REC-2026-0002']);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertSame(2, $report->stat('transactions_checked'));
    }

    /**
     * The real transactions.transaction_number UNIQUE constraint (confirmed
     * live in the OMS Task 8 audit) is exactly what this checker exists as a
     * defense-in-depth safety net against ever being silently bypassed (e.g.
     * a raw restore/import). To exercise that "if it were ever bypassed"
     * scenario at all, the constraint must be dropped first — this never
     * touches the real constraint definition, only this test's own isolated
     * SQLite schema.
     */
    private function dropTransactionNumberUniqueConstraint(): void
    {
        Schema::table('transactions', function ($table) {
            $table->dropUnique(['transaction_number']);
        });
    }

    public function test_real_duplicate_is_detected(): void
    {
        $this->dropTransactionNumberUniqueConstraint();

        $fiscalYearId = $this->makeFiscalYear()->id;
        $transactionTypeId = $this->makeTransactionType()->id;

        foreach (range(1, 2) as $i) {
            DB::table('transactions')->insert([
                'fiscal_year_id' => $fiscalYearId,
                'transaction_type_id' => $transactionTypeId,
                'transaction_number' => 'REC-2026-0001',
                'transaction_time' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $report = $this->check();

        $this->assertTrue($report->hasViolations());
        $violation = collect($report->violations())->firstWhere('category', 'duplicate_transaction_number');
        $this->assertNotNull($violation);
        $this->assertSame(2, $violation->count);
        $this->assertContains('REC-2026-0001', $violation->sampleIds);
    }

    public function test_soft_deleted_transaction_still_counts_toward_duplicate_detection(): void
    {
        $t1 = $this->makeTransaction(['transaction_number' => 'REC-2026-0001']);
        $t1->delete();

        $this->dropTransactionNumberUniqueConstraint();

        DB::table('transactions')->insert([
            'fiscal_year_id' => $this->makeFiscalYear()->id,
            'transaction_type_id' => $this->makeTransactionType()->id,
            'transaction_number' => 'REC-2026-0001',
            'transaction_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->check();

        $violation = collect($report->violations())->firstWhere('category', 'duplicate_transaction_number');
        $this->assertNotNull($violation);
        $this->assertSame(2, $violation->count);
    }
}
