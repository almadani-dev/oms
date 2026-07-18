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
