<?php

namespace App\Services\Audit\Financial;

/**
 * The naming contract that ties a foreign key in a financial audit payload
 * to its readability SATELLITE — the bounded label or currency code stored
 * alongside it (OMS Task 9B.3).
 *
 *   project_id               -> project_label
 *   project_cost_id          -> project_cost_label
 *   project_cost_budget_id   -> project_cost_budget_label
 *   partner_id               -> partner_label
 *   destination_account_id   -> destination_account_label
 *   currency_id              -> currency_code
 *   source_currency_id       -> source_currency_code
 *   disbursement_currency_id -> disbursement_currency_code
 *
 * The mapping matters beyond naming tidiness: it is what lets
 * FinancialAuditDiff carry BOTH the old and the new label of a reassigned
 * account/project/currency on an update, while keeping the satellite out of
 * `changed_fields` — a label is a snapshot attached to a foreign key, not a
 * field a user edited. It is also derivable in both directions, so the diff
 * never needs a hand-maintained second list that could drift from the
 * snapshot builders.
 *
 * The convention deliberately matches the general-CRUD one already
 * established by App\Services\Audit\Crud\AuditSubjectDefinition::
 * relationLabelKey() (`<name>_id` -> `<name>_label`).
 */
final class FinancialAuditFieldNames
{
    /**
     * Payload keys present on every financial event as identity/context.
     * Never reported in `changed_fields` and always carried on both sides of
     * an update, so an event can always be tied back to its workflow and its
     * ledger transaction.
     */
    public const CONTEXT_FIELDS = [
        'operation_type',
        'transaction_id',
        'transaction_number',
    ];

    private const LABEL_SUFFIX = '_label';

    private const CODE_SUFFIX = '_code';

    /**
     * The satellite key belonging to a foreign key. Currencies carry their
     * ISO-style `code`; everything else carries a bounded `label`.
     */
    public static function satelliteKey(string $foreignKey): string
    {
        $base = self::stripIdSuffix($foreignKey);

        return str_ends_with($base, 'currency')
            ? $base.self::CODE_SUFFIX
            : $base.self::LABEL_SUFFIX;
    }

    /**
     * The foreign key a satellite belongs to, or null when the key is not a
     * satellite at all.
     */
    public static function baseKey(string $key): ?string
    {
        foreach ([self::LABEL_SUFFIX, self::CODE_SUFFIX] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return substr($key, 0, -strlen($suffix)).'_id';
            }
        }

        return null;
    }

    public static function isContextField(string $key): bool
    {
        return in_array($key, self::CONTEXT_FIELDS, true);
    }

    private static function stripIdSuffix(string $key): string
    {
        return str_ends_with($key, '_id') ? substr($key, 0, -3) : $key;
    }
}
