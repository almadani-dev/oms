<?php

namespace Tests\Feature\Integrity;

use App\Services\Integrity\IntegrityCheckReport;
use App\Services\Integrity\JournalBalanceIntegrityChecker;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Proves the checker replays FinancialTransactionBalanceGuard's own real
 * invariant, not a naive SUM(debit_base)=SUM(credit_base) that would
 * silently blend different currencies together.
 */
class JournalBalanceIntegrityCheckerTest extends TestCase
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
        (new JournalBalanceIntegrityChecker())->check($report);

        return $report;
    }

    public function test_valid_same_currency_transaction_passes(): void
    {
        $currency = $this->makeCurrency();
        $debit = $this->makeAccount($currency);
        $credit = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $debit, $currency, [
            'amount_currency' => 500, 'debit_base' => 500, 'credit_base' => 0, 'line_role' => 'expense',
        ]);
        $this->makeLine($transaction, $credit, $currency, [
            'amount_currency' => 500, 'debit_base' => 0, 'credit_base' => 500, 'line_role' => 'source',
        ]);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertFalse($report->hasWarnings());
        $this->assertSame(1, $report->stat('journal_transactions_checked'));
    }

    public function test_deliberately_unbalanced_same_currency_transaction_is_detected(): void
    {
        $currency = $this->makeCurrency();
        $debit = $this->makeAccount($currency);
        $credit = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $debit, $currency, [
            'amount_currency' => 500, 'debit_base' => 500, 'credit_base' => 0, 'line_role' => 'expense',
        ]);
        // Genuinely unbalanced: credit side is 400, not 500.
        $this->makeLine($transaction, $credit, $currency, [
            'amount_currency' => 400, 'debit_base' => 0, 'credit_base' => 400, 'line_role' => 'source',
        ]);

        $report = $this->check();

        $this->assertTrue($report->hasViolations());
        $violation = collect($report->violations())->firstWhere('category', 'unbalanced_transaction');
        $this->assertNotNull($violation);
        $this->assertSame(1, $violation->count);
        $this->assertContains($transaction->id, $violation->sampleIds);
    }

    /**
     * The canonical valid multi-currency exchange: 1000 (source currency)
     * with a 3x FX rate converts to 3000 in the destination currency. This
     * must NOT be flagged merely because 1000 != 3000 — those are different
     * currencies, not an imbalance.
     */
    public function test_valid_multi_currency_exchange_passes_and_is_not_flagged_for_1000_ne_3000(): void
    {
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);

        $sourceAccount = $this->makeAccount($sourceCurrency);
        $adminAccount = $this->makeAccount($sourceCurrency);
        $transferAccount = $this->makeAccount($sourceCurrency);
        $destinationAccount = $this->makeAccount($destinationCurrency);

        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $sourceAccount, $sourceCurrency, [
            'amount_currency' => 1000, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 1000, 'line_role' => 'source',
        ]);
        $this->makeLine($transaction, $adminAccount, $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'administrative_deduction',
        ]);
        $this->makeLine($transaction, $transferAccount, $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'transfer_fee',
        ]);
        // afterDeductions = 1000 - 0.01 - 0.01 = 999.98; final = 999.98 * 3 = 2999.94
        $this->makeLine($transaction, $destinationAccount, $destinationCurrency, [
            'amount_currency' => 2999.94, 'fx_rate' => 3, 'debit_base' => 2999.94, 'credit_base' => 0, 'line_role' => 'destination',
        ]);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertFalse($report->hasWarnings());
    }

    /* =====================================================================
     | Optional deduction roles: the 2- and 3-line BUD/EXT shapes
     |
     | Since 2026-08-19 a 0% administrative or transfer percentage writes no
     | line at all in both the disbursement and general-exchange workflows, so
     | the same logical transaction is legitimately 4, 3 or 2 lines. None of
     | those shapes may be reported as an `unclassified_transaction_structure`
     | warning — that warning exists for rows the checker genuinely cannot
     | recognise, and burying real shapes in it would defeat the whole check.
     ===================================================================== */

    /**
     * Build one multi-currency transaction, omitting whichever deduction
     * roles are not requested. Amounts always satisfy the real FX equation:
     * (1000 - admin - transfer) * 3 = destination.
     */
    private function makeMultiCurrencyTransaction(float $admin, float $transfer): int
    {
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);

        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
            'amount_currency' => 1000, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 1000, 'line_role' => 'source',
        ]);

        if ($admin > 0) {
            $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
                'amount_currency' => $admin, 'fx_rate' => 1, 'debit_base' => $admin, 'credit_base' => 0, 'line_role' => 'administrative_deduction',
            ]);
        }

        if ($transfer > 0) {
            $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
                'amount_currency' => $transfer, 'fx_rate' => 1, 'debit_base' => $transfer, 'credit_base' => 0, 'line_role' => 'transfer_fee',
            ]);
        }

        $final = round((1000 - $admin - $transfer) * 3, 2);

        $this->makeLine($transaction, $this->makeAccount($destinationCurrency), $destinationCurrency, [
            'amount_currency' => $final, 'fx_rate' => 3, 'debit_base' => $final, 'credit_base' => 0, 'line_role' => 'destination',
        ]);

        return $transaction->id;
    }

    public function test_a_three_line_transaction_without_an_administrative_deduction_passes(): void
    {
        $this->makeMultiCurrencyTransaction(admin: 0, transfer: 20);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertFalse($report->hasWarnings(), 'a 0% admin deduction is a real shape, not an unclassified one');
    }

    public function test_a_three_line_transaction_without_a_transfer_fee_passes(): void
    {
        $this->makeMultiCurrencyTransaction(admin: 50, transfer: 0);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertFalse($report->hasWarnings());
    }

    public function test_a_two_line_transaction_without_either_deduction_passes(): void
    {
        $this->makeMultiCurrencyTransaction(admin: 0, transfer: 0);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertFalse($report->hasWarnings());
    }

    public function test_all_four_shapes_pass_together(): void
    {
        $this->makeMultiCurrencyTransaction(admin: 50, transfer: 20);
        $this->makeMultiCurrencyTransaction(admin: 0, transfer: 20);
        $this->makeMultiCurrencyTransaction(admin: 50, transfer: 0);
        $this->makeMultiCurrencyTransaction(admin: 0, transfer: 0);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertFalse($report->hasWarnings());
        $this->assertSame(4, $report->stat('journal_transactions_checked'));
    }

    /**
     * A two-line source+destination transaction is a MULTI-currency shape,
     * not an ordinary two-line single-currency one. Routing it through the
     * single-currency check (which demands fx_rate = 1 and one shared
     * currency) would flag this perfectly correct row as unbalanced.
     */
    public function test_a_two_line_transaction_is_not_mistaken_for_a_single_currency_pair(): void
    {
        $this->makeMultiCurrencyTransaction(admin: 0, transfer: 0);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertEmpty($report->violations());
    }

    public function test_a_three_line_transaction_with_a_wrong_fx_conversion_is_still_detected(): void
    {
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);
        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
            'amount_currency' => 1000, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 1000, 'line_role' => 'source',
        ]);
        $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
            'amount_currency' => 20, 'fx_rate' => 1, 'debit_base' => 20, 'credit_base' => 0, 'line_role' => 'transfer_fee',
        ]);
        // Should be (1000 - 20) * 3 = 2940. Dropping the admin line must not
        // make the remaining equation any less strict.
        $this->makeLine($transaction, $this->makeAccount($destinationCurrency), $destinationCurrency, [
            'amount_currency' => 3000, 'fx_rate' => 3, 'debit_base' => 3000, 'credit_base' => 0, 'line_role' => 'destination',
        ]);

        $report = $this->check();

        $this->assertTrue($report->hasViolations());
        $this->assertNotNull(collect($report->violations())->firstWhere('category', 'invalid_fx_base_conversion'));
    }

    public function test_a_transaction_missing_its_source_role_stays_unclassified(): void
    {
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);
        $transaction = $this->makeTransaction();

        // admin + destination only: no source. This is not a shape the
        // checker may guess at.
        $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
            'amount_currency' => 20, 'fx_rate' => 1, 'debit_base' => 20, 'credit_base' => 0, 'line_role' => 'administrative_deduction',
        ]);
        $this->makeLine($transaction, $this->makeAccount($destinationCurrency), $destinationCurrency, [
            'amount_currency' => 60, 'fx_rate' => 3, 'debit_base' => 60, 'credit_base' => 0, 'line_role' => 'destination',
        ]);

        $report = $this->check();

        $this->assertTrue($report->hasWarnings());
        $this->assertNotNull(collect($report->warnings())->firstWhere('category', 'unclassified_transaction_structure'));
    }

    public function test_a_transaction_mixing_a_known_role_set_with_a_null_role_line_stays_unclassified(): void
    {
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);
        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
            'amount_currency' => 1000, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 1000, 'line_role' => 'source',
        ]);
        $this->makeLine($transaction, $this->makeAccount($destinationCurrency), $destinationCurrency, [
            'amount_currency' => 3000, 'fx_rate' => 3, 'debit_base' => 3000, 'credit_base' => 0, 'line_role' => 'destination',
        ]);
        // A stray unclassified line: source+destination alone would match, but
        // the transaction genuinely holds three lines, so it must not be
        // validated as if it held two.
        $this->makeLine($transaction, $this->makeAccount($sourceCurrency), $sourceCurrency, [
            'amount_currency' => 5, 'fx_rate' => 1, 'debit_base' => 5, 'credit_base' => 0, 'line_role' => null,
        ]);

        $report = $this->check();

        $this->assertTrue($report->hasWarnings());
        $this->assertNotNull(collect($report->warnings())->firstWhere('category', 'unclassified_transaction_structure'));
    }

    public function test_multi_currency_exchange_with_wrong_fx_conversion_is_detected_as_invalid_fx_base(): void
    {
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);

        $sourceAccount = $this->makeAccount($sourceCurrency);
        $adminAccount = $this->makeAccount($sourceCurrency);
        $transferAccount = $this->makeAccount($sourceCurrency);
        $destinationAccount = $this->makeAccount($destinationCurrency);

        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $sourceAccount, $sourceCurrency, [
            'amount_currency' => 1000, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 1000, 'line_role' => 'source',
        ]);
        $this->makeLine($transaction, $adminAccount, $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'administrative_deduction',
        ]);
        $this->makeLine($transaction, $transferAccount, $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'transfer_fee',
        ]);
        // Wrong: should be 999.98 * 3 = 2999.94, not 3000.
        $this->makeLine($transaction, $destinationAccount, $destinationCurrency, [
            'amount_currency' => 3000, 'fx_rate' => 3, 'debit_base' => 3000, 'credit_base' => 0, 'line_role' => 'destination',
        ]);

        $report = $this->check();

        $this->assertTrue($report->hasViolations());
        $violation = collect($report->violations())->firstWhere('category', 'invalid_fx_base_conversion');
        $this->assertNotNull($violation);
        $this->assertContains($transaction->id, $violation->sampleIds);
        // Must not also be miscategorized as a plain unbalanced_transaction.
        $this->assertNull(collect($report->violations())->firstWhere('category', 'unbalanced_transaction'));
    }

    public function test_amount_currency_from_different_currencies_are_never_summed_directly(): void
    {
        // Same shape as the valid multi-currency test but with source/destination
        // amounts that would only "look balanced" if 1000 and 3000 were summed
        // as if they were the same unit. A checker that did SUM(debit_base) ==
        // SUM(credit_base) across the whole transaction would wrongly reject
        // this (1000 != 0.01+0.01+2999.94) even though it IS a valid, correctly
        // FX-converted transaction.
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);

        $sourceAccount = $this->makeAccount($sourceCurrency);
        $adminAccount = $this->makeAccount($sourceCurrency);
        $transferAccount = $this->makeAccount($sourceCurrency);
        $destinationAccount = $this->makeAccount($destinationCurrency);

        $transaction = $this->makeTransaction();

        $this->makeLine($transaction, $sourceAccount, $sourceCurrency, [
            'amount_currency' => 1000, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 1000, 'line_role' => 'source',
        ]);
        $this->makeLine($transaction, $adminAccount, $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'administrative_deduction',
        ]);
        $this->makeLine($transaction, $transferAccount, $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'transfer_fee',
        ]);
        $this->makeLine($transaction, $destinationAccount, $destinationCurrency, [
            'amount_currency' => 2999.94, 'fx_rate' => 3, 'debit_base' => 2999.94, 'credit_base' => 0, 'line_role' => 'destination',
        ]);

        $report = $this->check();

        // Raw SUM(debit_base) across all 4 lines = 0.01+0.01+2999.94 = 3000.00,
        // SUM(credit_base) = 1000.00 — deliberately NOT equal, proving this
        // checker does not use (and correctly does not fail on) that naive sum.
        $this->assertFalse($report->hasViolations());
    }

    public function test_transaction_with_null_line_role_is_reported_as_unclassified_warning_not_a_violation(): void
    {
        $currency = $this->makeCurrency();
        $debit = $this->makeAccount($currency);
        $credit = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();

        // Historical pre-2026-07-14 shape: line_role is NULL on both lines.
        $this->makeLine($transaction, $debit, $currency, [
            'amount_currency' => 500, 'debit_base' => 500, 'credit_base' => 0, 'line_role' => null,
        ]);
        $this->makeLine($transaction, $credit, $currency, [
            'amount_currency' => 500, 'debit_base' => 0, 'credit_base' => 500, 'line_role' => null,
        ]);

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertTrue($report->hasWarnings());
        $warning = collect($report->warnings())->firstWhere('category', 'unclassified_transaction_structure');
        $this->assertNotNull($warning);
        $this->assertContains($transaction->id, $warning->sampleIds);
    }

    public function test_soft_deleted_transaction_lines_are_excluded_from_balance_replay(): void
    {
        $currency = $this->makeCurrency();
        $debit = $this->makeAccount($currency);
        $credit = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();

        $line1 = $this->makeLine($transaction, $debit, $currency, [
            'amount_currency' => 500, 'debit_base' => 500, 'credit_base' => 0, 'line_role' => 'expense',
        ]);
        $line2 = $this->makeLine($transaction, $credit, $currency, [
            'amount_currency' => 500, 'debit_base' => 0, 'credit_base' => 500, 'line_role' => 'source',
        ]);

        // Soft-delete both lines: zero non-deleted lines remain for this
        // transaction, which must be skipped, not flagged as unclassified
        // or unbalanced.
        $line1->delete();
        $line2->delete();

        $report = $this->check();

        $this->assertFalse($report->hasViolations());
        $this->assertFalse($report->hasWarnings());
    }
}
