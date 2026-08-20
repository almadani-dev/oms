<?php

namespace App\Services\Muwakha;

use App\Models\Account;
use App\Models\Currency;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Services\Audit\Crud\AuditedCrudService;
use App\Support\Muwakha\MuwakhaAccountIdentity;
use App\Support\Muwakha\MuwakhaReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single audited write path for a Muwakha family, the Accounts it owns and
 * the mappings that record that ownership.
 *
 * WHY A SERVICE. A family create is three model writes (Account + family +
 * mapping) plus their REQUIRED AuditEvents that must all commit or all roll
 * back. Filament does not open a transaction for Create/Edit pages in this
 * application (the panel never calls ->databaseTransactions()), so scattering
 * this across page callbacks would allow a committed Account with no family —
 * an orphan beneficiary account in the chart of accounts with no owner and no
 * way to find it. Every method here opens one DB::transaction() and delegates
 * each individual mutation to AuditedCrudService, whose own transaction nests
 * safely as a savepoint.
 *
 * A MUWAKHA ACCOUNT IS IMMUTABLE. This class NEVER updates an Account. Not its
 * name, not its currency, not its number, bank, IBAN, balance or active flag.
 * Historical `transaction_line` rows reference `accounts.id`, so rewriting an
 * Account's identity would make every historical statement and report render
 * today's payment details against yesterday's money. The only Account write
 * anywhere in this file is a CREATE.
 *
 * WHAT AN EDIT DOES INSTEAD. The submitted material identity (canonical name,
 * type, currency, account number, bank type, normalized IBAN, holder name — see
 * MuwakhaAccountIdentity) is compared with the identity of the family's current
 * Account:
 *  - identical            -> nothing but the family row is written; the family
 *                            keeps the same Account;
 *  - an EXACT match exists among the Accounts already mapped to THIS family
 *                        -> that Account is REUSED: `account_id` is repointed
 *                            at it and no Account and no mapping are written;
 *  - otherwise            -> exactly one new Account and one new mapping are
 *                            created and `account_id` is repointed at them.
 *
 * The reuse search is strictly family-scoped, through `muwakha_family_accounts`
 * only. `accounts.account_code` is intentionally not unique across OMS, so a
 * global search could hand one family another family's ledger.
 *
 * PROJECT LINKS ON CREATE. The create form may carry initial family <-> project
 * rows. They are replayed through MuwakhaFamilyProjectService INSIDE this
 * class's transaction, so eligibility, duplicate linkage and card-code scoping
 * are enforced by the one service that owns those rules — nothing about them is
 * reimplemented here or in a Filament callback — and a rejected row unwinds the
 * whole creation. After creation, links are managed by ProjectsRelationManager
 * through that same service; `update()` deliberately ignores the key.
 *
 * ACCOUNT LIFECYCLE, STATED PRECISELY:
 *  - create  -> one Account + one mapping, zero balance, NO opening-balance
 *               transaction (this path never touches CreateAccount's
 *               opening-entry branch, which is the only code that writes one);
 *  - update  -> zero or one new Account; never an Account UPDATE;
 *  - delete  -> leaves every Account and every mapping completely untouched.
 *
 * The account type (`أفراد`) is resolved by name and is a hard invariant on
 * every path; the form exposes no control for it.
 */
final class MuwakhaFamilyService
{
    /**
     * Form keys that belong to the Account, not to the family row. Kept as a
     * closed list so a future family column can never leak into an Account
     * payload by name collision.
     *
     * `account_holder_name` is deliberately NOT here: it is a real
     * `muwakha_families` column (the current holder) as well as material
     * Account identity, and it is copied onto the mapping separately.
     */
    private const ACCOUNT_FIELDS = ['account_code', 'bank_type_id', 'iban', 'currency_id'];

    /**
     * The create form's optional project-link rows. Not a column on anything —
     * it is split out of the submitted data and replayed through
     * MuwakhaFamilyProjectService, which owns every link rule.
     */
    public const PROJECT_LINKS_FIELD = 'muwakha_project_links';

    public function __construct(
        private readonly AuditedCrudService $crud,
        private readonly MuwakhaFamilyProjectService $projectLinks,
    ) {}

    /**
     * `أسرة الشهيد {martyr_name} - {currency display name} - ({account_code})`.
     *
     * Delegates to MuwakhaAccountIdentity, which owns the rule because the name
     * is part of the identity. Kept here as the feature's familiar entry point
     * so the form, the service and the tests all reach one implementation.
     */
    public static function accountNameFor(string $martyrName, ?string $currencyName = null, ?string $accountCode = null): string
    {
        return MuwakhaAccountIdentity::accountNameFor($martyrName, $currencyName, $accountCode);
    }

    /**
     * @param  array<string, mixed>  $data  merged family + account form data
     */
    public function create(array $data): MuwakhaFamily
    {
        // Resolved BEFORE the transaction opens: a missing/ambiguous lookup is
        // a configuration problem, and failing before any write means there is
        // nothing to roll back and no partial state to explain.
        $accountTypeId = MuwakhaReference::accountTypeId();

        $projectLinks = $this->extractProjectLinks($data);

        [$familyData, $accountData] = $this->split($data);

        $identity = $this->identityFromSubmission($familyData, $accountData, $accountTypeId);

        return DB::transaction(function () use ($familyData, $identity, $projectLinks): MuwakhaFamily {
            // STEP 1 — the family's dedicated Account.
            $account = $this->crud->create(new Account, $identity->accountAttributes());

            // STEP 2 — the family itself, pointing at that Account.
            $family = $this->crud->create(new MuwakhaFamily, $familyData + [
                'account_id' => $account->id,
            ]);

            // STEP 3 — the durable ownership record.
            $this->mapAccount($family, $account, $identity->accountHolderName);

            // STEP 4 — the optional initial project links, each through the
            // domain service that owns eligibility, duplicate-linkage and
            // card-code rules. They run INSIDE this transaction, so a rejected
            // row unwinds the Account, the family, the mapping and every
            // earlier link with it — there is no partially linked family.
            //
            // Replaying them one by one is also what makes a duplicate project
            // or card code WITHIN one submission fail: the second row is
            // checked against the first, which is already inserted.
            foreach ($projectLinks as $link) {
                $this->projectLinks->link($family, $link);
            }

            return $family;
        });
    }

    /**
     * @param  array<string, mixed>  $data  merged family + account form data
     */
    public function update(MuwakhaFamily $family, array $data): MuwakhaFamily
    {
        $currentAccount = $this->requireAccount($family);
        $accountTypeId = MuwakhaReference::accountTypeId();

        // Project links are a CREATE-form convenience only; after creation they
        // are managed by ProjectsRelationManager through the same domain
        // service. A submission that carries them here is stripped rather than
        // acted on, so an edit can never silently add links.
        $this->extractProjectLinks($data);

        [$familyData, $accountData] = $this->split($data);

        // account_id is never reassigned by a submitted form field. The only
        // thing that may repoint it is the resolution below, from an Account
        // this family already owns or one this method created itself.
        unset($familyData['account_id']);

        $desired = $this->identityFromSubmission($familyData, $accountData, $accountTypeId);

        // The identity the family's CURRENT Account actually carries. The
        // holder name comes from that Account's mapping, falling back to the
        // family row for a pre-mapping record; nothing is written to repair it.
        $currentMapping = $this->mappingFor($family, $currentAccount->id);

        $current = MuwakhaAccountIdentity::fromAccount(
            $currentAccount,
            (string) ($currentMapping?->account_holder_name ?? $family->account_holder_name),
        );

        // Nothing material changed -> the family row is the only write.
        if ($current !== null && $current->matches($desired)) {
            return DB::transaction(function () use ($family, $familyData): MuwakhaFamily {
                $this->crud->update($family, $familyData);

                return $family;
            });
        }

        // An Account this family ALREADY owns that carries exactly the desired
        // identity. Family-scoped by construction — see mappedMatch().
        $reusable = $this->mappedMatch($family, $desired, $currentAccount->id);

        if ($reusable !== null) {
            $this->assertReusable($reusable->account);

            return DB::transaction(function () use ($family, $familyData, $reusable): MuwakhaFamily {
                // The reused Account is NOT written to — it did not change, so
                // manufacturing an account event would be a false trail. Only
                // the family moves.
                $this->crud->update($family, $familyData + ['account_id' => $reusable->account_id]);

                return $family;
            });
        }

        return DB::transaction(function () use ($family, $familyData, $desired): MuwakhaFamily {
            // STEP 1 — a BRAND NEW Account, built entirely from the values just
            // submitted. The previous Account is read nowhere below this point.
            $account = $this->crud->create(new Account, $desired->accountAttributes());

            // STEP 2 — the family row, including the repoint. Sharing the
            // transaction is what guarantees that a failure of either leaves
            // the family on its previous Account.
            $this->crud->update($family, $familyData + ['account_id' => $account->id]);

            // STEP 3 — the durable ownership record for the new Account.
            $this->mapAccount($family, $account, $desired->accountHolderName);

            return $family;
        });
    }

    /**
     * Soft-deletes the family and hard-removes its project links. Accounts and
     * account mappings are not touched in any way.
     */
    public function delete(MuwakhaFamily $family): bool
    {
        return DB::transaction(function () use ($family): bool {
            // STEP 1 — remove every project link first, each as its own
            // audited deletion, so the trail records exactly which links
            // existed at the moment the family was removed. The family is
            // soft-deleted, so the table's cascadeOnDelete would never fire;
            // this is the real removal path, not a fallback.
            foreach ($family->familyProjects()->get() as $link) {
                $this->crud->delete($link);
            }

            // STEP 2 — the family. Its Accounts and its account mappings are
            // deliberately absent from this method entirely: the mappings are
            // the durable record of which ledgers ever belonged to it.
            return $this->crud->delete($family);
        });
    }

    /**
     * Builds the submitted material identity, validating the parts the
     * canonical name and the comparison structurally depend on.
     *
     * Every check is re-run SERVER-SIDE, independent of what the form offered:
     * a Select's options and a required flag are Livewire component state and
     * a crafted request can replace both.
     *
     * @param  array<string, mixed>  $familyData
     * @param  array<string, mixed>  $accountData
     */
    private function identityFromSubmission(array $familyData, array $accountData, int $accountTypeId): MuwakhaAccountIdentity
    {
        $currency = $this->requireCurrency($accountData['currency_id'] ?? null);

        return MuwakhaAccountIdentity::fromSubmission(
            martyrName: (string) ($familyData['martyr_name'] ?? ''),
            currencyDisplayName: (string) MuwakhaReference::currencyDisplayName($currency),
            accountTypeId: $accountTypeId,
            currencyId: (int) $currency->id,
            accountCode: $this->requireAccountCode($accountData['account_code'] ?? null),
            bankTypeId: $this->requireBankTypeId($accountData['bank_type_id'] ?? null),
            iban: $accountData['iban'] ?? null,
            accountHolderName: $this->requireAccountHolderName($familyData['account_holder_name'] ?? null),
        );
    }

    /**
     * The Accounts this family owns that carry exactly the desired identity,
     * excluding the current one (already compared by the caller).
     *
     * Scoped to `muwakha_family_accounts` for this family and nothing else.
     * There is no global lookup and no name/number LIKE search anywhere:
     * `accounts.account_code` is intentionally non-unique across OMS, so a
     * global match could attach one family to another family's ledger.
     */
    private function mappedMatch(MuwakhaFamily $family, MuwakhaAccountIdentity $desired, int $excludeAccountId): ?MuwakhaFamilyAccount
    {
        $mappings = MuwakhaFamilyAccount::query()
            ->where('muwakha_family_id', $family->id)
            ->where('account_id', '!=', $excludeAccountId)
            ->with('account')
            ->get();

        foreach ($mappings as $mapping) {
            $identity = MuwakhaAccountIdentity::fromMapping($mapping);

            if ($identity !== null && $identity->matches($desired)) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * An exact historical match whose Account is inactive or soft-deleted is
     * NOT silently reactivated, restored, or bypassed with a duplicate — all
     * three would either mutate historical data or hide a real problem. The
     * operator resolves the Account's state through the Accounts screen.
     */
    private function assertReusable(?Account $account): void
    {
        if ($account === null) {
            throw ValidationException::withMessages([
                'account_code' => 'حساب الأسرة السابق المطابق غير موجود. لا يمكن استخدامه حتى تتم معالجة هذا الخلل في البيانات.',
            ]);
        }

        if ($account->trashed()) {
            throw ValidationException::withMessages([
                'account_code' => 'يوجد حساب سابق لهذه الأسرة بنفس البيانات لكنه محذوف. يرجى استعادته من شاشة الحسابات ثم إعادة المحاولة.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account_code' => 'يوجد حساب سابق لهذه الأسرة بنفس البيانات لكنه غير نشط. يرجى تفعيله من شاشة الحسابات ثم إعادة المحاولة.',
            ]);
        }
    }

    private function mappingFor(MuwakhaFamily $family, int $accountId): ?MuwakhaFamilyAccount
    {
        return MuwakhaFamilyAccount::query()
            ->where('muwakha_family_id', $family->id)
            ->where('account_id', $accountId)
            ->first();
    }

    private function mapAccount(MuwakhaFamily $family, Account $account, string $accountHolderName): MuwakhaFamilyAccount
    {
        return $this->crud->create(new MuwakhaFamilyAccount, [
            'muwakha_family_id' => $family->id,
            'account_id' => $account->id,
            'account_holder_name' => $accountHolderName,
        ]);
    }

    /**
     * The submitted currency, re-validated SERVER-SIDE against the same live
     * set the form's Select was built from.
     */
    private function requireCurrency(mixed $currencyId): Currency
    {
        $currency = MuwakhaReference::findSelectableCurrency($currencyId);

        if (! $currency) {
            throw ValidationException::withMessages([
                'currency_id' => 'يجب اختيار عملة صالحة لحساب الأسرة من عملات النظام.',
            ]);
        }

        return $currency;
    }

    /**
     * The account number is required for Muwakha — it is part of the canonical
     * Account name and of the identity — so a blank one is rejected rather than
     * producing a nameless or ambiguous Account. It is NOT normalized beyond a
     * string cast: OMS has no account-number normalization rule, and numbers
     * carry leading zeros and formatting the staff entered on purpose.
     */
    private function requireAccountCode(mixed $accountCode): string
    {
        $value = (string) ($accountCode ?? '');

        if (trim($value) === '') {
            throw ValidationException::withMessages([
                'account_code' => 'رقم الحساب مطلوب لحساب الأسرة.',
            ]);
        }

        return $value;
    }

    private function requireBankTypeId(mixed $bankTypeId): int
    {
        if (blank($bankTypeId)) {
            throw ValidationException::withMessages([
                'bank_type_id' => 'يجب اختيار نوع البنك/الحساب لحساب الأسرة.',
            ]);
        }

        return (int) $bankTypeId;
    }

    private function requireAccountHolderName(mixed $accountHolderName): string
    {
        $value = trim((string) ($accountHolderName ?? ''));

        if ($value === '') {
            throw ValidationException::withMessages([
                'account_holder_name' => 'اسم صاحب الحساب مطلوب لحساب الأسرة.',
            ]);
        }

        return $value;
    }

    /**
     * Fails closed when a live family's Account is missing or soft-deleted.
     *
     * Deliberately does NOT repair, recreate or substitute an Account: a
     * family whose payment destination has vanished is a real data problem a
     * human must see, and silently minting a replacement would attach the
     * family's future payments to an account with no history and no relation
     * to the money already paid.
     */
    private function requireAccount(MuwakhaFamily $family): Account
    {
        $account = Account::withTrashed()->find($family->account_id);

        if (! $account) {
            throw ValidationException::withMessages([
                'account_code' => 'حساب الأسرة المرتبط غير موجود. لا يمكن تعديل الأسرة حتى تتم معالجة هذا الخلل في البيانات.',
            ]);
        }

        if ($account->trashed()) {
            throw ValidationException::withMessages([
                'account_code' => 'حساب الأسرة المرتبط محذوف. لا يمكن تعديل الأسرة حتى تتم استعادة الحساب.',
            ]);
        }

        return $account;
    }

    /**
     * Removes the project-link rows from the submitted data and returns them.
     *
     * Taken out BY REFERENCE so the key can never reach `split()` and be
     * mistaken for a family column. Rows are passed through untouched: card-code
     * normalization (blank -> NULL) and every validation rule belong to
     * MuwakhaFamilyProjectService and are not reimplemented here.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function extractProjectLinks(array &$data): array
    {
        $links = $data[self::PROJECT_LINKS_FIELD] ?? [];

        unset($data[self::PROJECT_LINKS_FIELD]);

        if (! is_array($links)) {
            return [];
        }

        // Filament keys repeater items by a random string; only the values
        // matter. Rows are NOT filtered by content: a row with a blank project
        // is forwarded so the domain service REJECTS it, rather than being
        // dropped silently — the form's own `required()` already prevents a
        // legitimate blank row from ever being submitted.
        return array_values(array_map(
            static fn (mixed $link): array => is_array($link) ? $link : [],
            $links,
        ));
    }

    /**
     * Splits merged form data into [family columns, account columns].
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function split(array $data): array
    {
        $accountData = [];

        foreach (self::ACCOUNT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $accountData[$field] = $data[$field];
            }
        }

        $familyData = array_diff_key($data, array_flip(self::ACCOUNT_FIELDS));

        return [$familyData, $accountData];
    }
}
