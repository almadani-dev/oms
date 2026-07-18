<?php

namespace Tests\Unit\Services\Validation;

use App\Services\Validation\FinancialAmountGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialAmountGuardTest extends TestCase
{
    // ---- assertSimpleAmount / assertPositiveAmount ---------------------

    public function test_zero_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertSimpleAmount(0);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertSimpleAmount(-10);
    }

    public function test_amount_of_0_01_is_accepted(): void
    {
        FinancialAmountGuard::assertSimpleAmount(0.01);

        $this->addToAssertionCount(1);
    }

    // ---- assertPositiveFxRate -------------------------------------------

    public function test_zero_fx_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertPositiveFxRate(0);
    }

    public function test_negative_fx_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertPositiveFxRate(-1);
    }

    public function test_fx_rate_of_0_000001_is_accepted(): void
    {
        FinancialAmountGuard::assertPositiveFxRate(0.000001);

        $this->addToAssertionCount(1);
    }

    // ---- assertValidPercentage -------------------------------------------

    public function test_negative_administrative_percentage_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertValidPercentage(-5, 'administrative_percentage', 'النسبة الإدارية');
    }

    public function test_percentage_above_100_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertValidPercentage(100.01, 'administrative_percentage', 'النسبة الإدارية');
    }

    public function test_percentage_of_100_is_accepted_individually(): void
    {
        FinancialAmountGuard::assertValidPercentage(100, 'administrative_percentage', 'النسبة الإدارية');

        $this->addToAssertionCount(1);
    }

    // ---- assertCombinedPercentagesBelowFull -------------------------------

    public function test_combined_percentages_equal_to_100_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertCombinedPercentagesBelowFull(60, 40);
    }

    public function test_combined_percentages_above_100_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertCombinedPercentagesBelowFull(70, 40);
    }

    public function test_valid_combined_percentages_below_100_is_accepted(): void
    {
        FinancialAmountGuard::assertCombinedPercentagesBelowFull(30, 40);

        $this->addToAssertionCount(1);
    }

    // ---- assertPositiveDerivedAmount ---------------------------------------

    public function test_zero_amount_after_deductions_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertPositiveDerivedAmount(0, 'amount_after_deductions', 'المبلغ بعد الخصومات');
    }

    public function test_negative_amount_after_deductions_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertPositiveDerivedAmount(-1, 'amount_after_deductions', 'المبلغ بعد الخصومات');
    }

    public function test_zero_final_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertPositiveDerivedAmount(0, 'final_amount', 'المبلغ النهائي');
    }

    public function test_negative_final_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertPositiveDerivedAmount(-0.01, 'final_amount', 'المبلغ النهائي');
    }

    // ---- assertDisbursementInputs (composed guard) ------------------------

    public function test_disbursement_inputs_rejects_combined_percentages_of_100(): void
    {
        $this->expectException(ValidationException::class);

        // original 1000, admin 60%, transfer 40% -> after deductions = 0
        FinancialAmountGuard::assertDisbursementInputs(1000, 60, 40, 1, 0, 0);
    }

    public function test_disbursement_inputs_rejects_zero_fx_rate(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAmountGuard::assertDisbursementInputs(1000, 10, 10, 0, 800, 0);
    }

    public function test_disbursement_inputs_accepts_valid_values(): void
    {
        // original 1000, admin 10%, transfer 10% -> after deductions 800, fx 1 -> final 800
        FinancialAmountGuard::assertDisbursementInputs(1000, 10, 10, 1, 800, 800);

        $this->addToAssertionCount(1);
    }
}
