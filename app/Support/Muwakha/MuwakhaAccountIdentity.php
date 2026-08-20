<?php

namespace App\Support\Muwakha;

use App\Models\Account;
use App\Models\MuwakhaFamilyAccount;

/**
 * The material identity of one Muwakha family Account, and the single place
 * that identity is BUILT, NAMED and COMPARED.
 *
 * WHY AN IDENTITY OBJECT. A family Account is immutable once it exists: a
 * material change never rewrites it, it either resolves to an Account the
 * family already owns or produces a new one. That decision is a comparison,
 * and a comparison spread across a service, a form and a test drifts. Every
 * caller therefore builds one of these and asks `matches()`.
 *
 * WHAT COUNTS AS IDENTITY. The canonical name, the account type, the currency,
 * the account number, the bank/payment type, the normalized IBAN and the
 * account-holder name. The name is compared as well as its ingredients — but
 * never INSTEAD of them, because a name is a rendering and two different
 * identities must never be able to collapse into one by rendering alike.
 *
 * NORMALIZATION, DELIBERATELY MINIMAL. Names and the IBAN are trimmed, and a
 * blank IBAN is the same as no IBAN (`'' === null`) — the convention
 * MuwakhaFamilyProjectService::normalizeCardCode() already established in this
 * feature, so a browser that posts an empty string cannot manufacture a second
 * identity. The ACCOUNT NUMBER is deliberately NOT normalized beyond a string
 * cast: OMS has no account-number normalization rule anywhere, numbers may
 * carry leading zeros and formatting the staff entered on purpose, and
 * inventing one here would silently merge two numbers a human considers
 * different.
 */
final class MuwakhaAccountIdentity
{
    private function __construct(
        public readonly string $martyrName,
        public readonly string $accountName,
        public readonly int $accountTypeId,
        public readonly int $currencyId,
        public readonly string $accountCode,
        public readonly int $bankTypeId,
        public readonly ?string $iban,
        public readonly string $accountHolderName,
    ) {}

    /**
     * `أسرة الشهيد {martyr_name} - {currency display name} - ({account_code})`
     * — the ONE canonical name rule for a Muwakha Account.
     *
     * It lives here, on the identity, because the name IS part of the identity;
     * the form's live preview, every Account this feature creates and the
     * reuse comparison all read this one method, so they cannot drift.
     *
     * The account number is required for Muwakha, so there is no
     * "name without a number" fallback in the write paths. The nullable
     * arguments exist only for the form preview, which renders before the
     * operator has filled the fields in: a missing part is simply omitted
     * rather than rendered as an empty bracket.
     */
    public static function accountNameFor(string $martyrName, ?string $currencyName = null, ?string $accountCode = null): string
    {
        $name = 'أسرة الشهيد '.trim($martyrName);

        $currency = trim((string) $currencyName);
        $code = trim((string) $accountCode);

        if ($currency !== '') {
            $name .= ' - '.$currency;
        }

        if ($code !== '') {
            $name .= ' - ('.$code.')';
        }

        return $name;
    }

    /**
     * The identity the operator just submitted.
     */
    public static function fromSubmission(
        string $martyrName,
        string $currencyDisplayName,
        int $accountTypeId,
        int $currencyId,
        string $accountCode,
        int $bankTypeId,
        ?string $iban,
        string $accountHolderName,
    ): self {
        $martyrName = trim($martyrName);
        $accountCode = (string) $accountCode;

        return new self(
            martyrName: $martyrName,
            accountName: self::accountNameFor($martyrName, $currencyDisplayName, $accountCode),
            accountTypeId: $accountTypeId,
            currencyId: $currencyId,
            accountCode: $accountCode,
            bankTypeId: $bankTypeId,
            iban: self::normalizeIban($iban),
            accountHolderName: trim($accountHolderName),
        );
    }

    /**
     * The identity an Account the family already owns actually carries.
     *
     * Reads the payment fields from `accounts` (authoritative) and the holder
     * name from the mapping (the only place it is recorded per Account). The
     * martyr name is derived back out of the stored NAME rather than from the
     * family's current row on purpose: a historical Account keeps the martyr
     * name it was created with, and that is exactly what a corrected-name
     * submission must fail to match.
     *
     * Returns null when the mapping's Account is missing or when the Account
     * is incomplete enough that no identity can be stated — such a mapping can
     * never be a reuse target.
     */
    public static function fromMapping(MuwakhaFamilyAccount $mapping): ?self
    {
        return self::fromAccount($mapping->account, (string) $mapping->account_holder_name);
    }

    public static function fromAccount(?Account $account, string $accountHolderName): ?self
    {
        if ($account === null) {
            return null;
        }

        if ($account->account_type_id === null || $account->currency_id === null || $account->bank_type_id === null) {
            return null;
        }

        $accountCode = (string) ($account->account_code ?? '');

        if (trim($accountCode) === '') {
            return null;
        }

        return new self(
            // Never used for comparison on its own; carried so a caller can
            // report what the stored Account is actually named.
            martyrName: '',
            accountName: (string) $account->name,
            accountTypeId: (int) $account->account_type_id,
            currencyId: (int) $account->currency_id,
            accountCode: $accountCode,
            bankTypeId: (int) $account->bank_type_id,
            iban: self::normalizeIban($account->iban),
            accountHolderName: trim($accountHolderName),
        );
    }

    /**
     * Exact identity equality. Every field is compared explicitly — there is
     * no loose name matching and no `LIKE` anywhere in this feature.
     */
    public function matches(self $other): bool
    {
        return $this->accountName === $other->accountName
            && $this->accountTypeId === $other->accountTypeId
            && $this->currencyId === $other->currencyId
            && $this->accountCode === $other->accountCode
            && $this->bankTypeId === $other->bankTypeId
            && $this->iban === $other->iban
            && $this->accountHolderName === $other->accountHolderName;
    }

    /**
     * The Account column payload for a NEW Account carrying this identity.
     *
     * `current_balance` is deliberately absent: the column default (and
     * Account::$attributes) is 0, and a balance may only ever move through
     * balanced entries. Nothing here reaches CreateAccount's opening-entry
     * branch, so no opening-balance Transaction is ever written.
     *
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        return [
            'account_code' => $this->accountCode,
            'name' => $this->accountName,
            'account_type_id' => $this->accountTypeId,
            'bank_type_id' => $this->bankTypeId,
            'currency_id' => $this->currencyId,
            'is_active' => true,
            'iban' => $this->iban,
        ];
    }

    /**
     * A blank IBAN and a NULL IBAN are the same absence of an IBAN.
     */
    public static function normalizeIban(mixed $iban): ?string
    {
        $value = trim((string) ($iban ?? ''));

        return $value === '' ? null : $value;
    }
}
