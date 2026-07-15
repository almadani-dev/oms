<?php

namespace App\Enums;

/**
 * The authoritative vocabulary for transaction_lines.line_role.
 *
 * The backed string value is the stable English machine value stored in the
 * database; the Arabic label is for UI display only. Roles are assigned
 * explicitly by the six legitimate financial flows at line-creation time —
 * never inferred from account name, line order, description, or notes.
 * This vocabulary is separate from (and does not replace) the existing
 * transaction_lines.notes LINE_* tags.
 */
enum TransactionLineRole: string
{
    // Project cost receipt
    case FundingSource      = 'funding_source';
    case ReceiptDestination = 'receipt_destination';

    // Shared by disbursement, general expense, and general exchange
    case Source                  = 'source';
    case AdministrativeDeduction = 'administrative_deduction';
    case TransferFee             = 'transfer_fee';
    case Destination             = 'destination';

    // Execution payment
    case Beneficiary     = 'beneficiary';
    case ExecutionSource = 'execution_source';

    // General expense
    case Expense = 'expense';

    // Opening balance
    case OpeningBalanceTarget      = 'opening_balance_target';
    case OpeningBalanceCounterpart = 'opening_balance_counterpart';

    public function arabicLabel(): string
    {
        return match ($this) {
            self::FundingSource             => 'مصدر التمويل',
            self::ReceiptDestination        => 'وجهة الاستلام',
            self::Source                    => 'مصدر',
            self::AdministrativeDeduction   => 'خصم إداري',
            self::TransferFee               => 'عمولة تحويل',
            self::Destination               => 'وجهة',
            self::Beneficiary               => 'مستفيد',
            self::ExecutionSource           => 'مصدر التنفيذ',
            self::Expense                   => 'مصروف',
            self::OpeningBalanceTarget      => 'حساب الرصيد الافتتاحي',
            self::OpeningBalanceCounterpart => 'الطرف المقابل الافتتاحي',
        };
    }

    /**
     * Arabic label for a stored line_role value, null-safe for historical
     * rows (which keep line_role = NULL) and unknown values.
     */
    public static function labelFor(?string $value): ?string
    {
        return $value === null ? null : self::tryFrom($value)?->arabicLabel();
    }
}
