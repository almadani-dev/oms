<?php

namespace Tests\Unit\Services\Validation;

use App\Services\Validation\FinancialTransactionBalanceGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pure unit tests against the guard's static methods with hand-built line
 * arrays — no database, no workflow code. Malformed payloads are constructed
 * directly here (never by weakening production code) to exercise every
 * rejection branch in isolation. See the BalanceGuardIntegrationTest classes
 * under tests/Feature for the wiring proof (real workflow Create/Edit paths,
 * real database, real rejection-safety guarantees).
 */
class FinancialTransactionBalanceGuardTest extends TestCase
{
    // =====================================================================
    // COMMON PAYLOAD — assertValidLinePayload()
    // =====================================================================

    public function test_valid_debit_line_is_accepted(): void
    {
        FinancialTransactionBalanceGuard::assertValidLinePayload([
            $this->line(debit: 100.00, credit: 0, role: 'expense'),
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_valid_credit_line_is_accepted(): void
    {
        FinancialTransactionBalanceGuard::assertValidLinePayload([
            $this->line(debit: 0, credit: 100.00, role: 'source'),
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_both_debit_and_credit_positive_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialTransactionBalanceGuard::assertValidLinePayload([
            $this->line(debit: 100.00, credit: 100.00, role: 'expense', amount: 100.00),
        ]);
    }

    public function test_both_debit_and_credit_zero_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialTransactionBalanceGuard::assertValidLinePayload([
            $this->line(debit: 0, credit: 0, role: 'expense', amount: 0),
        ]);
    }

    public function test_negative_debit_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialTransactionBalanceGuard::assertValidLinePayload([
            $this->line(debit: -50.00, credit: 0, role: 'expense', amount: -50.00),
        ]);
    }

    public function test_negative_credit_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialTransactionBalanceGuard::assertValidLinePayload([
            $this->line(debit: 0, credit: -50.00, role: 'source', amount: -50.00),
        ]);
    }

    public function test_zero_amount_currency_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $line             = $this->line(debit: 100.00, credit: 0, role: 'expense');
        $line['amount_currency'] = 0;

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_negative_amount_currency_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $line             = $this->line(debit: 100.00, credit: 0, role: 'expense');
        $line['amount_currency'] = -100.00;

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_zero_fx_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $line             = $this->line(debit: 100.00, credit: 0, role: 'expense');
        $line['fx_rate']  = 0;

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_negative_fx_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $line             = $this->line(debit: 100.00, credit: 0, role: 'expense');
        $line['fx_rate']  = -1;

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_missing_fx_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // fx_rate is a required key with no default — a payload that omits
        // it entirely (not just a falsy value) must be rejected, never
        // silently treated as 1.
        $line = $this->line(debit: 100.00, credit: 0, role: 'expense');
        unset($line['fx_rate']);

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_amount_currency_not_matching_active_side_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // debit_base = 100 but amount_currency = 90: internally inconsistent line.
        $line                     = $this->line(debit: 100.00, credit: 0, role: 'expense');
        $line['amount_currency']  = 90.00;

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_missing_required_key_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $line = $this->line(debit: 100.00, credit: 0, role: 'expense');
        unset($line['currency_id']);

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_missing_role_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $line               = $this->line(debit: 100.00, credit: 0, role: 'expense');
        $line['line_role']  = '';

        FinancialTransactionBalanceGuard::assertValidLinePayload([$line]);
    }

    public function test_duplicate_role_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialTransactionBalanceGuard::assertValidLinePayload([
            $this->line(debit: 100.00, credit: 0, role: 'expense'),
            $this->line(debit: 50.00, credit: 0, role: 'expense'),
        ]);
    }

    public function test_incorrect_notes_tag_is_rejected_where_notes_are_authoritative(): void
    {
        $this->expectException(ValidationException::class);

        $line          = $this->line(debit: 0, credit: 100.00, role: 'source');
        $line['notes'] = 'وسم-خاطئ';

        FinancialTransactionBalanceGuard::assertValidLinePayload(
            [$line],
            expectedNotesByRole: ['source' => 'صرف - المصدر (دائن)']
        );
    }

    public function test_correct_notes_tag_is_accepted_when_authoritative(): void
    {
        $line          = $this->line(debit: 0, credit: 100.00, role: 'source');
        $line['notes'] = 'صرف - المصدر (دائن)';

        FinancialTransactionBalanceGuard::assertValidLinePayload(
            [$line],
            expectedNotesByRole: ['source' => 'صرف - المصدر (دائن)']
        );

        $this->addToAssertionCount(1);
    }

    public function test_duplicate_notes_tag_is_rejected_even_without_expected_map(): void
    {
        $this->expectException(ValidationException::class);

        $debit          = $this->line(debit: 100.00, credit: 0, role: 'expense');
        $debit['notes'] = 'نفس-الوسم';
        $credit         = $this->line(debit: 0, credit: 100.00, role: 'source');
        $credit['notes'] = 'نفس-الوسم';

        FinancialTransactionBalanceGuard::assertValidLinePayload([$debit, $credit]);
    }

    // =====================================================================
    // SINGLE-CURRENCY — assertBalancedSingleCurrencyLines()
    // =====================================================================

    public function test_single_currency_valid_balanced_payload_is_accepted(): void
    {
        $lines = $this->singleCurrencyLines();

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, 1);

        $this->addToAssertionCount(1);
    }

    public function test_single_currency_debit_one_cent_higher_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $lines = $this->singleCurrencyLines(debitAmount: 100.01, creditAmount: 100.00);

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, 1);
    }

    public function test_single_currency_credit_one_cent_higher_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $lines = $this->singleCurrencyLines(debitAmount: 100.00, creditAmount: 100.01);

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, 1);
    }

    public function test_single_currency_wrong_currency_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $lines               = $this->singleCurrencyLines();
        $lines[1]['currency_id'] = 2;

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, 1);
    }

    public function test_single_currency_fx_rate_other_than_one_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $lines             = $this->singleCurrencyLines();
        $lines[0]['fx_rate'] = 1.5;

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, 1);
    }

    public function test_single_currency_missing_fx_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // Even if a caller bypasses assertValidLinePayload() and calls this
        // method directly, a missing fx_rate must still fail — never be
        // silently treated as 1 — since the method has no fallback either.
        $lines = $this->singleCurrencyLines();
        unset($lines[0]['fx_rate']);

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, 1);
    }

    public function test_single_currency_wrong_role_direction_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // Numerically balanced (100 debit + 100 credit) — the sum check alone
        // would accept this — but 'expense' expects debit and 'source' expects
        // credit, and here the two roles have their sides swapped.
        $lines = [
            $this->line(debit: 0, credit: 100.00, role: 'expense'),
            $this->line(debit: 100.00, credit: 0, role: 'source'),
        ];

        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, 1);
    }

    // =====================================================================
    // MULTI-CURRENCY — assertBalancedMultiCurrencyLines()
    // =====================================================================
    //
    // Shared baseline: original 1000 (currency 1), admin 50, transfer 20,
    // amount_after_deductions 930, fx_rate 3.75, final 3487.50 (currency 2).
    // 930 * 3.75 = 3487.50 exactly, so the baseline has no rounding noise.

    public function test_multi_currency_valid_payload_is_accepted(): void
    {
        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();

        FinancialTransactionBalanceGuard::assertValidLinePayload([$source, $admin, $transfer, $destination]);
        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);

        $this->addToAssertionCount(1);
    }

    public function test_multi_currency_incorrect_source_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $source = $this->multiLine(credit: 900.00, role: 'source', currencyId: 1); // was 1000

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_incorrect_administrative_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $admin = $this->multiLine(debit: 80.00, role: 'administrative_deduction', currencyId: 1); // was 50

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_incorrect_transfer_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $transfer = $this->multiLine(debit: 50.00, role: 'transfer_fee', currencyId: 1); // was 20

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_incorrect_after_deduction_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // admin + transfer consume more than the source amount: after-deduction <= 0.
        $source     = $this->multiLine(credit: 1000.00, role: 'source', currencyId: 1);
        $admin      = $this->multiLine(debit: 600.00, role: 'administrative_deduction', currencyId: 1);
        $transfer   = $this->multiLine(debit: 500.00, role: 'transfer_fee', currencyId: 1);
        $destination = $this->multiLine(debit: 3487.50, role: 'destination', currencyId: 2, fxRate: 3.75);

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_incorrect_destination_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $destination = $this->multiLine(debit: 5000.00, role: 'destination', currencyId: 2, fxRate: 3.75); // way off

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_one_cent_fx_error_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $destination = $this->multiLine(debit: 3487.51, role: 'destination', currencyId: 2, fxRate: 3.75); // 1 cent over

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_reversed_fx_direction_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // Correct destination is afterDeduct * fxRate = 3487.50; this uses
        // afterDeduct / fxRate = 248.00 instead (reversed direction).
        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $destination = $this->multiLine(debit: 248.00, role: 'destination', currencyId: 2, fxRate: 3.75);

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_wrong_source_currency_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $source = $this->multiLine(credit: 1000.00, role: 'source', currencyId: 99); // wrong

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_wrong_destination_currency_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        [$source, $admin, $transfer, $destination] = $this->multiCurrencyLines();
        $destination = $this->multiLine(debit: 3487.50, role: 'destination', currencyId: 99, fxRate: 3.75); // wrong

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);
    }

    public function test_multi_currency_valid_six_decimal_fx_rate_is_accepted(): void
    {
        // 930 * 3.756789 = 3493.81377 -> rounds to 3493.81
        $source      = $this->multiLine(credit: 1000.00, role: 'source', currencyId: 1);
        $admin       = $this->multiLine(debit: 50.00, role: 'administrative_deduction', currencyId: 1);
        $transfer    = $this->multiLine(debit: 20.00, role: 'transfer_fee', currencyId: 1);
        $destination = $this->multiLine(debit: 3493.81, role: 'destination', currencyId: 2, fxRate: 3.756789);

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($source, $admin, $transfer, $destination, 1, 2);

        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // Test data builders
    // =====================================================================

    /**
     * A single, internally-consistent line (amount_currency mirrors whichever
     * side is active), overridable per-field via the optional $amount arg.
     */
    private function line(float $debit, float $credit, string $role, ?float $amount = null): array
    {
        return [
            'account_id'      => 1,
            'currency_id'     => 1,
            'amount_currency' => $amount ?? max($debit, $credit),
            'fx_rate'         => 1,
            'debit_base'      => $debit,
            'credit_base'     => $credit,
            'line_role'       => $role,
        ];
    }

    /** Two balanced single-currency lines: debit 'expense' + credit 'source'. */
    private function singleCurrencyLines(float $debitAmount = 100.00, float $creditAmount = 100.00): array
    {
        return [
            $this->line(debit: $debitAmount, credit: 0, role: 'expense'),
            $this->line(debit: 0, credit: $creditAmount, role: 'source'),
        ];
    }

    /** A single multi-currency line with an explicit currency/fx_rate. */
    private function multiLine(string $role, int $currencyId, float $debit = 0, float $credit = 0, float $fxRate = 1): array
    {
        return [
            'account_id'      => 1,
            'currency_id'     => $currencyId,
            'amount_currency' => max($debit, $credit),
            'fx_rate'         => $fxRate,
            'debit_base'      => $debit,
            'credit_base'     => $credit,
            'line_role'       => $role,
        ];
    }

    /** Valid 4-line multi-currency baseline: 1000 -50 -20 = 930 * 3.75 = 3487.50. */
    private function multiCurrencyLines(): array
    {
        return [
            $this->multiLine(credit: 1000.00, role: 'source', currencyId: 1),
            $this->multiLine(debit: 50.00, role: 'administrative_deduction', currencyId: 1),
            $this->multiLine(debit: 20.00, role: 'transfer_fee', currencyId: 1),
            $this->multiLine(debit: 3487.50, role: 'destination', currencyId: 2, fxRate: 3.75),
        ];
    }
}
