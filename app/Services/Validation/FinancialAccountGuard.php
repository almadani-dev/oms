<?php

namespace App\Services\Validation;

use App\Models\Account;
use Illuminate\Validation\ValidationException;

/**
 * Server-side re-validation of financial account selections, independent of
 * whatever Filament's own Select options allowed through (account_type_id/
 * bank_type_id are Livewire component state only, never persisted, and can be
 * submitted independently of the actual account_id). Must be called before
 * any transaction, transaction line, record, or account balance is written.
 */
class FinancialAccountGuard
{
    /**
     * Verify a single submitted account: exists (not soft-deleted), matches the
     * submitted account type and bank type, matches the required operation
     * currency, and (when $requireActive) is currently active. Returns the
     * verified Account on success.
     */
    public static function assertAccountMatches(
        ?int $accountId,
        ?int $expectedAccountTypeId,
        ?int $expectedBankTypeId,
        ?int $expectedCurrencyId,
        string $field,
        string $label,
        bool $requireActive = true,
    ): Account {
        $account = Account::find($accountId);

        if (! $account) {
            throw ValidationException::withMessages([
                $field => "{$label}: الحساب المحدد غير موجود أو محذوف.",
            ]);
        }

        if ((int) $account->account_type_id !== (int) $expectedAccountTypeId) {
            throw ValidationException::withMessages([
                $field => "{$label}: نوع الحساب المحدد لا يطابق نوع الحساب المختار.",
            ]);
        }

        if ((int) $account->bank_type_id !== (int) $expectedBankTypeId) {
            throw ValidationException::withMessages([
                $field => "{$label}: نوع بنك الحساب المحدد لا يطابق نوع البنك المختار.",
            ]);
        }

        if ((int) $account->currency_id !== (int) $expectedCurrencyId) {
            throw ValidationException::withMessages([
                $field => "{$label}: يجب أن يكون الحساب بنفس عملة العملية المطلوبة.",
            ]);
        }

        if ($requireActive && ! $account->is_active) {
            throw ValidationException::withMessages([
                $field => "{$label}: الحساب المحدد غير نشط.",
            ]);
        }

        return $account;
    }

    /**
     * Verify a batch of account specs in one call. Each spec is keyed by an
     * arbitrary role name (e.g. 'debit', 'source', 'destination') and must
     * provide account_id/account_type_id/bank_type_id/currency_id/field/label,
     * plus an optional require_active (defaults to true). Returns the verified
     * Account models keyed the same way as the input specs.
     *
     * @param  array<string, array{account_id: ?int, account_type_id: ?int, bank_type_id: ?int, currency_id: ?int, field: string, label: string, require_active?: bool}>  $specs
     * @return array<string, Account>
     */
    public static function assertAccounts(array $specs): array
    {
        $verified = [];

        foreach ($specs as $role => $spec) {
            $verified[$role] = self::assertAccountMatches(
                $spec['account_id'] ?? null,
                $spec['account_type_id'] ?? null,
                $spec['bank_type_id'] ?? null,
                $spec['currency_id'] ?? null,
                $spec['field'],
                $spec['label'],
                $spec['require_active'] ?? true,
            );
        }

        return $verified;
    }

    /**
     * On Edit, an account only needs to be active if the user actually changed
     * it from the record's originally-saved account for that role. Historical
     * records may reference an account that was active when created but has
     * since been deactivated; editing an unrelated field must not force a
     * currently-inactive historical account out, and accounts are never
     * silently swapped or reactivated.
     */
    public static function requireActiveOnChange(?int $originalAccountId, $submittedAccountId): bool
    {
        return (int) $originalAccountId !== (int) $submittedAccountId;
    }
}
