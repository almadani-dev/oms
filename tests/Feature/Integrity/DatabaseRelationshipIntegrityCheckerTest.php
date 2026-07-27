<?php

namespace Tests\Feature\Integrity;

use App\Services\Integrity\DatabaseRelationshipIntegrityChecker;
use App\Services\Integrity\IntegrityCheckReport;
use Illuminate\Support\Facades\DB;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

class DatabaseRelationshipIntegrityCheckerTest extends TestCase
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
        (new DatabaseRelationshipIntegrityChecker())->check($report);

        return $report;
    }

    public function test_valid_rows_produce_no_violations(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertSame(12, $report->stat('relationships_checked'));
    }

    public function test_physical_orphan_is_detected(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();
        $line = $this->makeLine($transaction, $account, $currency);

        // Physically remove the referenced account row (FK checks are off in
        // this SQLite test schema, matching the project's own test convention).
        DB::table('accounts')->where('id', $account->id)->delete();

        $report = $this->check();

        $this->assertTrue($report->hasViolations());
        $orphan = collect($report->violations())->firstWhere('category', 'orphan_relationship');
        $this->assertNotNull($orphan);
        $this->assertSame(1, $orphan->count);
        $this->assertContains($line->id, $orphan->sampleIds);
    }

    public function test_soft_deleted_reference_is_not_an_orphan(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency);

        // Soft-delete only — the physical row still exists.
        $account->delete();

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
    }

    public function test_nullable_relation_left_null_is_not_flagged(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['partner_id' => null, 'bank_account_id' => null]);
        $this->makeLine($transaction, $account, $currency, ['project_cost_id' => null]);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
    }
}
