<?php

namespace Tests\Feature\Integrity;

use App\Services\Integrity\AccountCurrencyIntegrityChecker;
use App\Services\Integrity\IntegrityCheckReport;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

class AccountCurrencyIntegrityCheckerTest extends TestCase
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
        (new AccountCurrencyIntegrityChecker())->check($report);

        return $report;
    }

    public function test_valid_historical_account_and_matching_currency_pass(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
    }

    public function test_currency_mismatch_between_line_and_its_own_account_is_detected(): void
    {
        $accountCurrency = $this->makeCurrency();
        $lineCurrency = $this->makeCurrency();
        $account = $this->makeAccount($accountCurrency);
        $transaction = $this->makeTransaction();
        $line = $this->makeLine($transaction, $account, $lineCurrency);

        $report = $this->check();

        $this->assertTrue($report->hasViolations());
        $violation = collect($report->violations())->firstWhere('category', 'account_currency_mismatch');
        $this->assertNotNull($violation);
        $this->assertSame(1, $violation->count);
        $this->assertContains($line->id, $violation->sampleIds);
    }

    public function test_inactive_historical_account_is_not_flagged(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency, ['is_active' => false]);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
    }

    public function test_matching_persisted_balance_passes(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency, ['current_balance' => 500]);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency, [
            'debit_base' => 500, 'credit_base' => 0,
        ]);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
    }

    public function test_persisted_balance_discrepancy_is_detected(): void
    {
        $currency = $this->makeCurrency();
        // Stored balance says 999, but the ledger only supports 500.
        $account = $this->makeAccount($currency, ['current_balance' => 999]);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency, [
            'debit_base' => 500, 'credit_base' => 0,
        ]);

        $report = $this->check();

        $this->assertTrue($report->hasViolations());
        $violation = collect($report->violations())->firstWhere('category', 'persisted_balance_mismatch');
        $this->assertNotNull($violation);
        $this->assertContains($account->id, $violation->sampleIds);
    }

    public function test_soft_deleted_lines_are_excluded_from_persisted_balance_reconciliation(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency, ['current_balance' => 0]);
        $transaction = $this->makeTransaction();
        $line = $this->makeLine($transaction, $account, $currency, [
            'debit_base' => 500, 'credit_base' => 0,
        ]);
        $line->delete();

        // With the line soft-deleted, the ledger balance is 0, which now
        // correctly matches the account's own current_balance of 0.
        $report = $this->check();

        $this->assertFalse($report->hasViolations());
    }
}
