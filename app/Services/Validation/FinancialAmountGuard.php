<?php

namespace App\Services\Validation;

use Illuminate\Validation\ValidationException;

/**
 * Server-side re-validation of financial amounts, independent of whatever
 * Filament's own client-side form rules allowed through (form state can be
 * bypassed by a directly submitted Livewire request). Must be called before
 * any transaction, transaction line, record, or account balance is written.
 */
class FinancialAmountGuard
{
    private const MIN_AMOUNT = 0.01;

    private const MIN_FX_RATE = 0.000001;

    public static function assertPositiveAmount(float $amount, string $field, string $label): void
    {
        if (round($amount, 2) < self::MIN_AMOUNT) {
            throw ValidationException::withMessages([
                $field => "{$label} يجب أن يكون أكبر من صفر (الحد الأدنى 0.01).",
            ]);
        }
    }

    public static function assertPositiveFxRate(float $fxRate, string $field = 'fx_rate'): void
    {
        if (round($fxRate, 6) < self::MIN_FX_RATE) {
            throw ValidationException::withMessages([
                $field => 'سعر الصرف يجب أن يكون أكبر من صفر.',
            ]);
        }
    }

    public static function assertValidPercentage(float $percentage, string $field, string $label): void
    {
        if (round($percentage, 2) < 0) {
            throw ValidationException::withMessages([
                $field => "{$label} لا يمكن أن تكون قيمة سالبة.",
            ]);
        }

        if (round($percentage, 2) > 100) {
            throw ValidationException::withMessages([
                $field => "{$label} لا يمكن أن تتجاوز 100%.",
            ]);
        }
    }

    public static function assertCombinedPercentagesBelowFull(float $administrativePercentage, float $transferPercentage): void
    {
        if (round($administrativePercentage + $transferPercentage, 2) >= 100) {
            throw ValidationException::withMessages([
                'transfer_percentage' => 'مجموع النسبة الإدارية ونسبة التحويل يجب أن يكون أقل من 100%.',
            ]);
        }
    }

    public static function assertPositiveDerivedAmount(float $amount, string $field, string $label): void
    {
        if (round($amount, 2) <= 0) {
            throw ValidationException::withMessages([
                $field => "{$label} يجب أن يكون أكبر من صفر بعد احتساب النسب وسعر الصرف المدخلة.",
            ]);
        }
    }

    /**
     * Simple single-amount workflows: project cost receipts, execution
     * payments, general expenses.
     */
    public static function assertSimpleAmount(float $amount, string $field = 'amount', string $label = 'المبلغ'): void
    {
        self::assertPositiveAmount($amount, $field, $label);
    }

    /**
     * A deduction the operator ASKED for must be large enough to actually
     * record. Called by the two deduction/FX workflows (project cost budget
     * disbursements, general exchanges) alongside assertDisbursementInputs().
     *
     * Since 2026-08-19 a 0% deduction is a valid business case and simply
     * produces NO transaction line at all (see
     * FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines).
     * That creates one narrow gap this method closes: a percentage that is
     * greater than zero but so small that `round(original × pct / 100, 2)`
     * lands on 0.00 — e.g. 0.4% of 1.00. Such a deduction can neither be
     * written (a debit_base = credit_base = 0 line is forbidden, and
     * assertValidLinePayload() must keep forbidding it) nor silently
     * dropped (the operator explicitly entered a non-zero percentage and
     * would never be told it had no effect).
     *
     * Rejecting it here, on the percentage field the operator actually
     * typed, is the only outcome that is both honest and safe. After this
     * assertion passes, `percentage > 0` and `amount > 0` are equivalent
     * for both deductions — which is what lets the line payload, the
     * account validation, the balance mutations and the audit role map all
     * agree on which optional roles exist.
     */
    public static function assertDeductionsAreRecordable(
        float $administrativePercentage,
        float $administrativeAmount,
        float $transferPercentage,
        float $transferAmount
    ): void {
        self::assertDeductionRecordable(
            $administrativePercentage,
            $administrativeAmount,
            'administrative_percentage',
            'النسبة الإدارية',
        );

        self::assertDeductionRecordable(
            $transferPercentage,
            $transferAmount,
            'transfer_percentage',
            'نسبة التحويل',
        );
    }

    private static function assertDeductionRecordable(
        float $percentage,
        float $amount,
        string $field,
        string $label
    ): void {
        if (round($percentage, 2) > 0 && round($amount, 2) <= 0) {
            throw ValidationException::withMessages([
                $field => "{$label} صغيرة جدًا: قيمتها المحتسبة تساوي صفرًا بعد التقريب، فلا يمكن تسجيلها كسطر قيد. ضع صفرًا لإلغاء الخصم أو ارفع النسبة.",
            ]);
        }
    }

    /**
     * Full guard for the deduction/FX workflows: project cost budget
     * disbursements and general exchanges. Validates the original amount,
     * both percentages (individually and combined), the FX rate, and the
     * two amounts derived from them (amount_after_deductions, final_amount).
     */
    public static function assertDisbursementInputs(
        float $originalAmount,
        float $administrativePercentage,
        float $transferPercentage,
        float $fxRate,
        float $amountAfterDeductions,
        float $finalAmount
    ): void {
        self::assertPositiveAmount($originalAmount, 'original_amount', 'المبلغ بالعملة الأصلية');
        self::assertValidPercentage($administrativePercentage, 'administrative_percentage', 'النسبة الإدارية');
        self::assertValidPercentage($transferPercentage, 'transfer_percentage', 'نسبة التحويل');
        self::assertCombinedPercentagesBelowFull($administrativePercentage, $transferPercentage);
        self::assertPositiveFxRate($fxRate);
        self::assertPositiveDerivedAmount($amountAfterDeductions, 'amount_after_deductions', 'المبلغ بعد الخصومات');
        self::assertPositiveDerivedAmount($finalAmount, 'final_amount', 'المبلغ النهائي (بعملة الصرف)');
    }
}
