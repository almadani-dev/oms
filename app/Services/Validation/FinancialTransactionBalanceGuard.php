<?php

namespace App\Services\Validation;

use Illuminate\Validation\ValidationException;

/**
 * Final defensive check on the exact TransactionLine payload about to be
 * written — independent of FinancialAmountGuard (validates the raw form
 * inputs) and FinancialAccountGuard (validates account selection). Must be
 * called after the payload is fully built in memory and before
 * DB::transaction() opens, for both Create and Edit across all five
 * financial workflows.
 *
 * All comparisons use integer minor units (whole cents) with exact equality
 * — never a float tolerance — because every production amount is already
 * built via round(..., 2) and must land on a whole cent. A one-cent
 * imbalance is a real bug, not rounding noise, and must be rejected.
 *
 * `fx_rate` is a structurally-required key on every line payload, with no
 * default and no fallback: every caller — including EditProjectCostReceipt's
 * in-place line updates — must explicitly state the fx_rate it is writing.
 * A missing value is never silently assumed to be 1; it is rejected, exactly
 * like any other missing required field.
 */
class FinancialTransactionBalanceGuard
{
    /**
     * Keys every line payload must always carry, regardless of workflow or
     * whether it is a Create (full insert) or Edit (in-place update) payload.
     */
    private const REQUIRED_KEYS = [
        'account_id',
        'currency_id',
        'amount_currency',
        'fx_rate',
        'debit_base',
        'credit_base',
        'line_role',
    ];

    /**
     * Expected debit/credit side per line_role, for every role used by the
     * five in-scope financial workflows. A role not listed here is simply
     * not direction-checked (out of scope for this guard).
     */
    private const ROLE_EXPECTED_SIDE = [
        'funding_source'            => 'credit',
        'receipt_destination'       => 'debit',
        'source'                    => 'credit',
        'administrative_deduction'  => 'debit',
        'transfer_fee'              => 'debit',
        'destination'               => 'debit',
        'beneficiary'               => 'debit',
        'execution_source'          => 'credit',
        'expense'                   => 'debit',
    ];

    private static function toMinorUnits(int|float|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private static function toRateUnits(int|float|string $value): int
    {
        return (int) round(((float) $value) * 1_000_000);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['lines' => $message]);
    }

    /**
     * Structural check applied to every line regardless of workflow:
     *
     *  - all structurally required keys are present
     *  - exactly one side (debit_base/credit_base) carries a positive amount
     *    — never both, never neither, never negative
     *  - amount_currency is present, positive, and exactly equals whichever
     *    side is active (in integer minor units)
     *  - fx_rate is present (required, no default) and positive
     *  - line_role is present (non-empty) and unique within the payload
     *  - line_role's expected debit/credit direction (for roles used by the
     *    five in-scope workflows) matches the line's actual active side
     *  - notes, when present, is unique within the payload; and when
     *    $expectedNotesByRole maps this line's role to an expected tag
     *    (i.e. notes are authoritative for that role), the line's notes must
     *    match exactly
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, string>  $expectedNotesByRole  Optional line_role => expected notes tag map, for workflows where notes are authoritative.
     */
    public static function assertValidLinePayload(array $lines, array $expectedNotesByRole = []): void
    {
        $seenRoles = [];
        $seenNotes = [];

        foreach ($lines as $line) {
            foreach (self::REQUIRED_KEYS as $key) {
                if (! array_key_exists($key, $line)) {
                    self::fail("حقل {$key} مفقود من سطر القيد.");
                }
            }

            $debitMinor  = self::toMinorUnits($line['debit_base']);
            $creditMinor = self::toMinorUnits($line['credit_base']);

            if ($debitMinor < 0 || $creditMinor < 0) {
                self::fail('لا يمكن أن يكون أي سطر قيد بقيمة سالبة.');
            }

            if ($debitMinor > 0 && $creditMinor > 0) {
                self::fail('لا يمكن أن يحتوي سطر القيد الواحد على مدين ودائن معًا.');
            }

            if ($debitMinor === 0 && $creditMinor === 0) {
                self::fail('لا يمكن أن يكون سطر القيد بقيمة صفر على الجانبين.');
            }

            $amountMinor = self::toMinorUnits($line['amount_currency']);

            if ($amountMinor <= 0) {
                self::fail('مبلغ السطر (amount_currency) يجب أن يكون أكبر من صفر.');
            }

            $activeSideMinor = max($debitMinor, $creditMinor);

            if ($amountMinor !== $activeSideMinor) {
                self::fail('مبلغ السطر (amount_currency) لا يطابق قيمة الجانب الفعّال من القيد.');
            }

            $fxRate = (float) $line['fx_rate'];

            if ($fxRate <= 0) {
                self::fail('سعر الصرف لسطر القيد يجب أن يكون أكبر من صفر.');
            }

            $role = $line['line_role'] ?? null;

            if ($role === null || $role === '') {
                self::fail('دور سطر القيد (line_role) مفقود.');
            }

            if (isset($seenRoles[$role])) {
                self::fail('لا يمكن تكرار نفس دور السطر (line_role) أكثر من مرة في نفس القيد.');
            }

            $seenRoles[$role] = true;

            $expectedSide = self::ROLE_EXPECTED_SIDE[$role] ?? null;

            if ($expectedSide === 'debit' && $debitMinor === 0) {
                self::fail('اتجاه سطر القيد لا يطابق دوره — كان يجب أن يكون مدينًا.');
            }

            if ($expectedSide === 'credit' && $creditMinor === 0) {
                self::fail('اتجاه سطر القيد لا يطابق دوره — كان يجب أن يكون دائنًا.');
            }

            $notes = $line['notes'] ?? null;

            if ($notes !== null && $notes !== '') {
                if (isset($seenNotes[$notes])) {
                    self::fail('لا يمكن تكرار نفس علامة الملاحظات (notes) على أكثر من سطر في نفس القيد.');
                }

                $seenNotes[$notes] = true;
            }

            if (array_key_exists($role, $expectedNotesByRole) && $notes !== $expectedNotesByRole[$role]) {
                self::fail('علامة الملاحظات (notes) لسطر القيد غير صحيحة لدوره.');
            }
        }
    }

    /**
     * Single-currency workflows: project cost receipts, execution payments,
     * general expenses. Every line must share the same currency, carry
     * fx_rate = 1 (no FX conversion in a single-currency transaction), and
     * total debit must exactly equal total credit in integer minor units.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function assertBalancedSingleCurrencyLines(array $lines, int $expectedCurrencyId): void
    {
        $totalDebitMinor  = 0;
        $totalCreditMinor = 0;

        foreach ($lines as $line) {
            if ((int) ($line['currency_id'] ?? 0) !== $expectedCurrencyId) {
                self::fail('جميع سطور القيد يجب أن تكون بنفس عملة العملية.');
            }

            // No fallback: a missing fx_rate fails this check (toRateUnits(0) => 0 !== 1_000_000)
            // rather than being silently assumed to be 1. assertValidLinePayload() already rejects a
            // missing fx_rate outright when called first, as every production call site does.
            if (self::toRateUnits($line['fx_rate'] ?? 0) !== 1_000_000) {
                self::fail('سعر الصرف يجب أن يساوي 1 في معاملة أحادية العملة.');
            }

            $totalDebitMinor  += self::toMinorUnits($line['debit_base'] ?? 0);
            $totalCreditMinor += self::toMinorUnits($line['credit_base'] ?? 0);
        }

        if ($totalDebitMinor !== $totalCreditMinor) {
            self::fail('مجموع المدين لا يساوي مجموع الدائن — القيد غير متوازن.');
        }
    }

    /**
     * Multi-currency workflows: project cost budget disbursements and
     * general exchanges. Source, administrative, and transfer lines share
     * the source currency; the destination line carries the disbursement
     * currency and its own fx_rate. Two currencies are never summed
     * together — each equation below stays entirely within one currency:
     *
     *  - amount_after_deductions is derived here (never trusted from a
     *    stored field) as: source.credit_base - admin.debit_base - transfer.debit_base
     *  - destination FX conversion: destination.debit_base = amount_after_deductions × fx_rate
     *
     * OPTIONAL DEDUCTION LINES. `administrative_percentage` and
     * `transfer_percentage` are both legitimately 0 in these two workflows,
     * and a 0% deduction has no accounting line at all — writing one would
     * mean a debit_base = credit_base = amount_currency = 0 row, which
     * assertValidLinePayload() rejects outright and must keep rejecting.
     * So $adminLine and $transferLine are nullable: a null line is an
     * ABSENT deduction and contributes exactly 0 to the
     * amount_after_deductions equation below. It is never a substitute for
     * a malformed or zero-valued line — a caller that passes a zero line
     * still fails, in assertValidLinePayload() first and here second.
     *
     * The four valid shapes are therefore:
     *   source + admin + transfer + destination  (4 lines)
     *   source + transfer + destination          (3 lines, admin = 0%)
     *   source + admin + destination             (3 lines, transfer = 0%)
     *   source + destination                     (2 lines, both = 0%)
     *
     * Source and destination are ALWAYS required — there is no disbursement
     * or exchange without money leaving one account and arriving in another.
     */
    public static function assertBalancedMultiCurrencyLines(
        array $sourceLine,
        ?array $adminLine,
        ?array $transferLine,
        array $destinationLine,
        int $sourceCurrencyId,
        int $destinationCurrencyId
    ): void {
        $sourceCurrencyLines = array_filter(
            ['المصدر' => $sourceLine, 'النسبة الإدارية' => $adminLine, 'التحويل' => $transferLine],
            static fn (?array $line): bool => $line !== null,
        );

        foreach ($sourceCurrencyLines as $label => $line) {
            if ((int) ($line['currency_id'] ?? 0) !== $sourceCurrencyId) {
                self::fail("سطر {$label} يجب أن يكون بعملة المصدر نفسها.");
            }
        }

        if ((int) ($destinationLine['currency_id'] ?? 0) !== $destinationCurrencyId) {
            self::fail('سطر الوجهة يجب أن يكون بعملة الصرف المحددة.');
        }

        // An absent (null) deduction line contributes 0 — that is what "no
        // administrative deduction" means arithmetically. A PRESENT line
        // still contributes its own debit_base with no fallback.
        $originalMinor    = self::toMinorUnits($sourceLine['credit_base'] ?? 0);
        $adminMinor       = $adminLine === null ? 0 : self::toMinorUnits($adminLine['debit_base'] ?? 0);
        $transferMinor    = $transferLine === null ? 0 : self::toMinorUnits($transferLine['debit_base'] ?? 0);
        $afterDeductMinor = $originalMinor - $adminMinor - $transferMinor;

        if ($afterDeductMinor <= 0) {
            self::fail('المبلغ بعد الخصومات يجب أن يكون أكبر من صفر.');
        }

        // No fallback: a missing fx_rate fails this check (0 <= 0) rather than being silently
        // assumed valid. assertValidLinePayload() already rejects a missing fx_rate outright
        // when called first, as every production call site does.
        $fxRate = (float) ($destinationLine['fx_rate'] ?? 0);

        if ($fxRate <= 0) {
            self::fail('سعر الصرف في سطر الوجهة يجب أن يكون أكبر من صفر.');
        }

        $expectedFinalMinor = self::toMinorUnits(round(($afterDeductMinor / 100) * $fxRate, 2));
        $actualFinalMinor   = self::toMinorUnits($destinationLine['debit_base'] ?? 0);

        if ($expectedFinalMinor !== $actualFinalMinor) {
            self::fail('مبلغ سطر الوجهة لا يطابق المبلغ بعد الخصومات مضروبًا في سعر الصرف.');
        }
    }
}
