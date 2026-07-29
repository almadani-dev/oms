<?php

namespace App\Services\Audit\Financial;

/**
 * The closed vocabulary of roles an account can play in an audited
 * financial workflow (OMS Task 9B.3).
 *
 * A role is what makes an account id meaningful in an audit payload: an
 * event that says "account 14 changed" is useless, "the destination account
 * changed from X to Y" is accountability. The role is encoded into the
 * payload key itself (`destination_account_id` / `destination_account_label`)
 * rather than stored as a separate free-text field, so a role can never
 * drift away from the account it describes.
 *
 * Kept as a plain constant list rather than an enum: these are payload key
 * PREFIXES, always used as strings, and FinancialAuditSnapshotter validates
 * every caller-supplied role against this list so an unrecognised role fails
 * closed instead of inventing a new payload key.
 */
final class FinancialAccountRole
{
    /** المبالغ المستلمة / المصروفات العامة */
    public const DEBIT = 'debit';

    /** المبالغ المستلمة / المصروفات العامة / صرف مبالغ التنفيذ */
    public const CREDIT = 'credit';

    /** صرف مبلغ المشروع / التحويلات العامة */
    public const SOURCE = 'source';

    /** صرف مبلغ المشروع / التحويلات العامة */
    public const DESTINATION = 'destination';

    /** النسبة الإدارية */
    public const ADMIN = 'admin';

    /** نسبة التحويل */
    public const TRANSFER = 'transfer';

    /** صرف مبالغ التنفيذ */
    public const BENEFICIARY = 'beneficiary';

    public const ALL = [
        self::DEBIT,
        self::CREDIT,
        self::SOURCE,
        self::DESTINATION,
        self::ADMIN,
        self::TRANSFER,
        self::BENEFICIARY,
    ];
}
