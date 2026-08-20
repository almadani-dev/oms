<?php

namespace Tests\Feature\Muwakha;

use App\Filament\Resources\MuwakhaFamilies\Pages\CreateMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\Pages\EditMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\Pages\ListMuwakhaFamilies;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\FiscalYear;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Models\MuwakhaFamilyProject;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Audit\AuditRedactor;
use App\Services\Muwakha\MuwakhaFamiliesExcelExportService;
use App\Services\Muwakha\MuwakhaFamiliesWordExportService;
use App\Services\Muwakha\MuwakhaFamilyExportRow;
use App\Services\Muwakha\MuwakhaFamilyProjectService;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Muwakha\MuwakhaAccountIdentity;
use App\Support\Muwakha\MuwakhaReference;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Livewire\Livewire;

/**
 * The final historical family-account design (2026-08-17).
 *
 * Three rules are load-bearing, and every test here pins one of them:
 *
 *  1. A Muwakha Account is IMMUTABLE. This feature never issues an Account
 *     UPDATE. A material identity change resolves to a different Account
 *     instead, so historical statements and reports keep rendering the payment
 *     details that actually belonged to the money they show.
 *
 *  2. Reuse before duplication, and reuse is FAMILY-SCOPED. Returning to an
 *     identity the family already owns returns to that exact Account, found
 *     only through `muwakha_family_accounts`. `accounts.account_code` is
 *     intentionally non-unique across OMS, so a global search could hand one
 *     family another family's ledger.
 *
 *  3. `muwakha_family_accounts` is ownership, not history. No dates, no
 *     versions, no ordering — the `accounts` rows are the financial record.
 */
class MuwakhaFamilyAccountIdentityTest extends MuwakhaTestCase
{
    private MuwakhaFamilyService $families;

    private MuwakhaFamilyProjectService $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->families = app(MuwakhaFamilyService::class);
        $this->links = app(MuwakhaFamilyProjectService::class);
    }

    // ------------------------------------------------------------------ create

    /** #1 + #2 + #3 + #4 + #5 + #6 + #7 + #8 + #9 */
    public function test_creating_a_family_creates_one_account_and_one_mapping(): void
    {
        $reference = $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد محمد',
            'account_holder_name' => 'فاطمة أحمد',
            'account_code' => '123456789',
        ]));

        $this->assertSame(1, Account::count());                                   // #1
        $this->assertSame(1, MuwakhaFamilyAccount::count());                      // #2

        $mapping = MuwakhaFamilyAccount::sole();
        $account = $this->accountOf($family);

        $this->assertSame($family->id, $mapping->muwakha_family_id);
        $this->assertSame($account->id, $mapping->account_id);                    // #3
        $this->assertSame($family->account_id, $mapping->account_id);
        $this->assertSame('فاطمة أحمد', $mapping->account_holder_name);           // #4

        // #5 — the canonical name, in full.
        $this->assertSame('أسرة الشهيد أحمد محمد - شيكل - (123456789)', $account->name);

        $this->assertSame($reference['account_type']->id, $account->account_type_id); // #6
        $this->assertSame(MuwakhaReference::ACCOUNT_TYPE_NAME, $account->accountType->name);
        $this->assertSame($reference['currency']->id, $account->currency_id);     // #7
        $this->assertSame('123456789', $account->account_code);                   // #8
        $this->assertSame('0.00', (string) $account->current_balance);
        $this->assertTrue((bool) $account->is_active);

        $this->assertSame(0, Transaction::count());                               // #9
        $this->assertDatabaseCount('transaction_lines', 0);
    }

    /** The account number is required server-side, not only on the form. */
    public function test_a_blank_account_number_is_rejected(): void
    {
        $this->seedMuwakhaReference();

        $this->expectException(ValidationException::class);

        $this->families->create($this->familyData(['account_code' => '   ']));
    }

    /** #51 — a leading-zero account number survives as entered. */
    public function test_the_account_number_is_stored_and_named_exactly_as_entered(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد',
            'account_code' => '000123',
        ]));

        $this->assertSame('000123', $this->accountOf($family)->account_code);
        $this->assertSame('أسرة الشهيد أحمد - شيكل - (000123)', $this->accountOf($family)->name);
    }

    // ------------------------------------------------------- no identity change

    /** #10 + #11 + #12 */
    public function test_resubmitting_the_same_identity_writes_no_account_and_no_mapping(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());
        $accountId = $family->account_id;
        $before = Account::findOrFail($accountId)->getAttributes();

        $this->families->update($family, $this->familyData());

        $this->assertSame(1, Account::count());                                   // #10
        $this->assertSame($accountId, $family->fresh()->account_id);              // #11
        $this->assertSame(1, MuwakhaFamilyAccount::count());                      // #12
        $this->assertSame($before, Account::findOrFail($accountId)->getAttributes());
    }

    /** #8 (brief §8) — non-account family data never moves the Account. */
    public function test_non_account_family_edits_never_change_the_account(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());
        $accountId = $family->account_id;
        $before = Account::findOrFail($accountId)->getAttributes();

        foreach ([
            ['children_count' => 9],
            ['guardian_name' => 'سعاد سالم'],
            ['guardian_national_id' => '0400000001'],
            ['guardian_date_of_birth' => '1980-02-02'],
            ['guardian_phone' => '0599777888'],
            ['notes' => 'ملاحظة جديدة'],
        ] as $change) {
            $this->families->update($family->fresh(), $this->familyData($change));
        }

        $this->assertSame(1, Account::count());
        $this->assertSame(1, MuwakhaFamilyAccount::count());
        $this->assertSame($accountId, $family->fresh()->account_id);
        $this->assertSame($before, Account::findOrFail($accountId)->getAttributes());
    }

    /** A blank IBAN and a NULL IBAN are the same identity, not two. */
    public function test_blank_and_null_iban_do_not_produce_two_identities(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData(['iban' => null]));
        $accountId = $family->account_id;

        $this->families->update($family, $this->familyData(['iban' => '']));
        $this->families->update($family->fresh(), $this->familyData(['iban' => '   ']));

        $this->assertSame(1, Account::count());
        $this->assertSame($accountId, $family->fresh()->account_id);
        $this->assertNull(MuwakhaAccountIdentity::normalizeIban(''));
        $this->assertNull(MuwakhaAccountIdentity::normalizeIban(null));
    }

    // ------------------------------------------------------- material changes

    /**
     * #13 + #14 + #15 + #16 + #17 + #18 + #19 — each material field, changed
     * alone, produces exactly one new Account and one new mapping.
     *
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('materialChanges')]
    public function test_each_material_change_creates_exactly_one_new_account(string $_label, array $change): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $this->seedDollarCurrency();
        $this->seedBankType('بنك القدس');

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد',
            'account_code' => '111',
            'iban' => 'PS11BANK000000000000111',
        ]));

        $oldAccountId = $family->account_id;

        $this->families->update($family, $this->familyData(array_merge([
            'martyr_name' => 'أحمد',
            'account_code' => '111',
            'iban' => 'PS11BANK000000000000111',
        ], $this->resolve($change))));

        $this->assertSame(2, Account::count());
        $this->assertSame(2, MuwakhaFamilyAccount::count());
        $this->assertNotSame($oldAccountId, $family->fresh()->account_id);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function materialChanges(): array
    {
        return [
            'currency' => ['currency', ['currency_id' => 'USD']],          // #13
            'account number' => ['account number', ['account_code' => '222']], // #14
            'bank type' => ['bank type', ['bank_type_id' => 'بنك القدس']],  // #15
            'iban' => ['iban', ['iban' => 'PS22BANK000000000000222']],      // #16
            'holder name' => ['holder name', ['account_holder_name' => 'سعاد سالم']], // #17
            'martyr name' => ['martyr name', ['martyr_name' => 'خالد']],    // #18
        ];
    }

    /** #19 — the new Account carries the canonical name for the NEW number. */
    public function test_the_new_account_name_uses_the_new_account_number(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد',
            'account_code' => '111',
        ]));

        $this->families->update($family, $this->familyData([
            'martyr_name' => 'أحمد',
            'account_code' => '333',
        ]));

        $this->assertSame('أسرة الشهيد أحمد - شيكل - (333)', $this->accountOf($family->fresh())->name);
    }

    // ------------------------------------------------- historical immutability

    /** #20 + #21 + #22 + #23 + #24 + #25 */
    public function test_the_previous_account_and_its_ledger_stay_byte_identical(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $this->seedDollarCurrency();
        $otherBank = $this->seedBankType('بنك القدس');

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد',
            'account_code' => '111',
            'iban' => 'PS11BANK000000000000111',
        ]));

        $oldAccountId = $family->account_id;

        // A balance cannot be produced here without posting entries, so it is
        // written directly to prove the switch neither resets nor recomputes it.
        Account::whereKey($oldAccountId)->update(['current_balance' => 1234.56]);
        $this->postLineAgainst($oldAccountId, $shekel->id);

        $before = Account::findOrFail($oldAccountId)->getAttributes();
        $transactionsBefore = DB::table('transactions')->orderBy('id')->get()->toArray();
        $linesBefore = DB::table('transaction_lines')->orderBy('id')->get()->toArray();

        $this->families->update($family, $this->familyData([
            'martyr_name' => 'خالد',
            'currency_id' => $this->seedDollarCurrency()->id,
            'account_code' => '999',
            'bank_type_id' => $otherBank->id,
            'iban' => 'PS22BANK000000000000222',
            'account_holder_name' => 'سعاد سالم',
        ]));

        $old = Account::withTrashed()->findOrFail($oldAccountId);

        $this->assertSame($before, $old->getAttributes());                        // #20
        $this->assertSame('1234.56', (string) $old->current_balance);             // #21
        $this->assertTrue((bool) $old->is_active);                                // #22
        $this->assertFalse($old->trashed());
        $this->assertSame('أسرة الشهيد أحمد - شيكل - (111)', $old->name);         // #25

        $this->assertEquals($transactionsBefore, DB::table('transactions')->orderBy('id')->get()->toArray()); // #23
        $this->assertEquals($linesBefore, DB::table('transaction_lines')->orderBy('id')->get()->toArray());   // #24

        // The ledger still points at the OLD account, never at the new one.
        $this->assertSame($oldAccountId, (int) TransactionLine::sole()->account_id);
        $this->assertNotSame($family->fresh()->account_id, (int) TransactionLine::sole()->account_id);
    }

    // -------------------------------------- same currency, multiple accounts

    /** #26 + #27 + #28 */
    public function test_one_family_may_hold_several_accounts_in_one_currency(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $otherBank = $this->seedBankType('بنك القدس');

        $family = $this->families->create($this->familyData(['account_code' => '111']));

        // #26 — same currency, different account number.
        $this->families->update($family, $this->familyData(['account_code' => '222']));

        // #27 — same currency AND same number, different bank + IBAN + holder.
        $this->families->update($family->fresh(), $this->familyData([
            'account_code' => '222',
            'bank_type_id' => $otherBank->id,
            'iban' => 'PS33BANK000000000000333',
            'account_holder_name' => 'سعاد سالم',
        ]));

        $accounts = Account::orderBy('id')->get();

        $this->assertCount(3, $accounts);
        $this->assertSame([$shekel->id, $shekel->id, $shekel->id], $accounts->pluck('currency_id')->all());
        $this->assertSame(3, MuwakhaFamilyAccount::where('muwakha_family_id', $family->id)->count());

        // #28 — nothing anywhere enforces one Account per family per currency.
        $this->assertFalse(Schema::hasColumn('muwakha_family_accounts', 'currency_id'));
    }

    // ------------------------------------------------------- historical reuse

    /** #29 + #36 + #37 */
    public function test_returning_to_an_exact_previous_identity_reuses_that_account(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['account_code' => '111']));
        $ilsAccountId = $family->account_id;
        $ilsSnapshot = Account::findOrFail($ilsAccountId)->getAttributes();

        $this->families->update($family, $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        $this->assertSame(2, Account::count());

        // Back to the exact original identity.
        $this->families->update($family->fresh(), $this->familyData(['account_code' => '111']));

        $this->assertSame($ilsAccountId, $family->fresh()->account_id);           // #29
        $this->assertSame(2, Account::count());                                   // #36
        $this->assertSame(2, MuwakhaFamilyAccount::count());                      // #37
        $this->assertSame($ilsSnapshot, Account::findOrFail($ilsAccountId)->getAttributes());
    }

    /** #30 + #31 — ILS -> USD -> ILS -> USD keeps using the same two Accounts. */
    public function test_alternating_between_two_exact_identities_never_creates_a_third_account(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $ils = $this->familyData(['currency_id' => $shekel->id, 'account_code' => '111']);
        $usd = $this->familyData(['currency_id' => $dollar->id, 'account_code' => '222']);

        $family = $this->families->create($ils);
        $ilsAccountId = $family->account_id;

        $this->families->update($family->fresh(), $usd);
        $usdAccountId = $family->fresh()->account_id;

        $this->assertNotSame($ilsAccountId, $usdAccountId);

        $this->families->update($family->fresh(), $ils);
        $this->assertSame($ilsAccountId, $family->fresh()->account_id);           // #30

        $this->families->update($family->fresh(), $usd);
        $this->assertSame($usdAccountId, $family->fresh()->account_id);           // #31

        $this->assertSame(2, Account::count());
        $this->assertSame(2, MuwakhaFamilyAccount::count());
    }

    /**
     * #32 + #33 + #34 — a near-miss is NOT a match. Each case returns to the
     * old account number but differs in exactly one other identity field.
     *
     * @param  array<string, mixed>  $difference
     */
    #[DataProvider('nearMisses')]
    public function test_a_near_miss_never_reuses_the_old_account(string $_label, array $difference): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();
        $this->seedBankType('بنك القدس');

        $original = $this->familyData([
            'currency_id' => $shekel->id,
            'account_code' => '111',
            'iban' => 'PS11BANK000000000000111',
            'account_holder_name' => 'فاطمة أحمد',
        ]);

        $family = $this->families->create($original);
        $originalAccountId = $family->account_id;

        // Move away first, so the near miss is compared against history.
        $this->families->update($family->fresh(), $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        $this->families->update($family->fresh(), array_merge($original, $this->resolve($difference)));

        $this->assertNotSame($originalAccountId, $family->fresh()->account_id);
        $this->assertSame(3, Account::count());
        $this->assertSame(3, MuwakhaFamilyAccount::count());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function nearMisses(): array
    {
        return [
            'different bank' => ['different bank', ['bank_type_id' => 'بنك القدس']],           // #32
            'different iban' => ['different iban', ['iban' => 'PS99BANK000000000000999']],     // #33
            'different holder' => ['different holder', ['account_holder_name' => 'سعاد سالم']], // #34
        ];
    }

    /** #35 — the full identity matching in every field DOES reuse. */
    public function test_a_complete_identity_match_reuses_the_account(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $original = $this->familyData([
            'martyr_name' => 'أحمد',
            'currency_id' => $shekel->id,
            'account_code' => '111',
            'iban' => 'PS11BANK000000000000111',
            'account_holder_name' => 'فاطمة أحمد',
        ]);

        $family = $this->families->create($original);
        $originalAccountId = $family->account_id;

        $this->families->update($family->fresh(), $this->familyData([
            'martyr_name' => 'أحمد',
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        // Every identity field back to the original, including the martyr name.
        $this->families->update($family->fresh(), $original);

        $this->assertSame($originalAccountId, $family->fresh()->account_id);
        $this->assertSame(2, Account::count());
    }

    /** #38 — a reused Account is not written to, so it gets no event. */
    public function test_reuse_writes_no_account_event(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['account_code' => '111']));
        $ilsAccountId = $family->account_id;

        $this->families->update($family->fresh(), $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        AuditEvent::query()->delete();

        $this->families->update($family->fresh(), $this->familyData(['account_code' => '111']));

        $this->assertSame(0, AuditEvent::where('subject_type', 'account')->count());
        $this->assertSame(0, AuditEvent::where('subject_type', 'muwakha_family_account')->count());

        // #53 — the switch itself is recorded on the family.
        $familyEvent = AuditEvent::where('subject_type', 'muwakha_family')->sole();

        $this->assertContains('account_id', $familyEvent->changed_fields);
        $this->assertSame($ilsAccountId, (int) $familyEvent->new_values['account_id']);
    }

    /** §17 — an exact match that is inactive or trashed fails safely. */
    public function test_an_inactive_or_trashed_historical_match_is_not_silently_revived(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['account_code' => '111']));
        $ilsAccountId = $family->account_id;

        $this->families->update($family->fresh(), $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        $usdAccountId = $family->fresh()->account_id;

        Account::whereKey($ilsAccountId)->update(['is_active' => false]);

        try {
            $this->families->update($family->fresh(), $this->familyData(['account_code' => '111']));
            $this->fail('An inactive historical match must not be reused silently.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_code', $exception->errors());
        }

        $this->assertSame($usdAccountId, $family->fresh()->account_id);
        $this->assertSame(2, Account::count(), 'No duplicate may be minted to bypass the state.');
        $this->assertFalse((bool) Account::findOrFail($ilsAccountId)->is_active, 'It must not be reactivated.');

        // The same holds for a soft-deleted one.
        Account::whereKey($ilsAccountId)->update(['is_active' => true]);
        Account::findOrFail($ilsAccountId)->delete();

        $this->expectException(ValidationException::class);

        $this->families->update($family->fresh(), $this->familyData(['account_code' => '111']));
    }

    // -------------------------------------------------------------- family scope

    /** #39 + #40 */
    public function test_reuse_never_crosses_to_another_family(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $shared = [
            'currency_id' => $shekel->id,
            'account_code' => '111',
            'iban' => 'PS11BANK000000000000111',
            'account_holder_name' => 'فاطمة أحمد',
            'martyr_name' => 'أحمد',
        ];

        $first = $this->families->create($this->familyData($shared + ['martyr_national_id' => '1111111111']));
        $second = $this->families->create($this->familyData($shared + ['martyr_national_id' => '2222222222']));

        // #40 — identical visible payment details, distinct Account rows.
        $this->assertNotSame($first->account_id, $second->account_id);
        $this->assertSame('111', $this->accountOf($first)->account_code);
        $this->assertSame('111', $this->accountOf($second)->account_code);

        // The second family moves away and back. It must return to ITS OWN
        // account, never to the first family's identical-looking one.
        $this->families->update($second->fresh(), $this->familyData(array_merge($shared, [
            'martyr_national_id' => '2222222222',
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ])));

        $this->families->update($second->fresh(), $this->familyData($shared + ['martyr_national_id' => '2222222222']));

        $this->assertSame($second->account_id, $second->fresh()->account_id);      // #39
        $this->assertNotSame($first->account_id, $second->fresh()->account_id);
        $this->assertSame($first->account_id, $first->fresh()->account_id);
        $this->assertSame(3, Account::count());
    }

    // -------------------------------------------------------------------- delete

    /** #41 + #42 + #43 */
    public function test_deleting_a_family_keeps_every_mapping_and_every_account(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['account_code' => '111']));
        $this->families->update($family->fresh(), $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        $project = $this->makeMuwakhaProject();
        $this->links->link($family->fresh(), ['project_id' => $project->id, 'card_code' => 'G 10']);

        $accountsBefore = Account::orderBy('id')->get()->map->getAttributes()->all();

        $this->families->delete($family->fresh());

        $this->assertSoftDeleted('muwakha_families', ['id' => $family->id]);
        $this->assertSame(0, MuwakhaFamilyProject::where('muwakha_family_id', $family->id)->count()); // #43
        $this->assertSame(2, MuwakhaFamilyAccount::where('muwakha_family_id', $family->id)->count()); // #41
        $this->assertSame($accountsBefore, Account::orderBy('id')->get()->map->getAttributes()->all()); // #42
    }

    // ------------------------------------------------------------------------ UI

    /** #44 — the live preview carries all three ingredients. */
    public function test_the_form_preview_shows_the_full_canonical_name(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateMuwakhaFamily::class)
            ->fillForm($this->familyData([
                'martyr_name' => 'أحمد محمد',
                'account_code' => '123456789',
            ]))
            ->assertSee('أسرة الشهيد أحمد محمد - شيكل - (123456789)');
    }

    /** #45 + #46 + #47 */
    public function test_the_edit_page_loads_the_current_account_details(): void
    {
        $reference = $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        $family = $this->families->create($this->familyData([
            'account_code' => '778899',
            'iban' => 'PS12BANK0000000000001',
            'account_holder_name' => 'فاطمة أحمد',
        ]));

        $page = Livewire::test(EditMuwakhaFamily::class, ['record' => $family->getKey()]);

        $page->assertFormSet([                                                    // #45
            'account_code' => '778899',
            'iban' => 'PS12BANK0000000000001',
            'currency_id' => $reference['currency']->id,
            'account_holder_name' => 'فاطمة أحمد',
        ]);

        // #46 — live currencies, required, and no retired currency among them.
        $this->seedEuroCurrency()->delete();

        $page->assertFormFieldExists('currency_id', function ($field): bool {
            $options = $field->getOptions();

            $this->assertTrue($field->isRequired());
            $this->assertSame(MuwakhaReference::currencyOptions(), $options);
            $this->assertContains('شيكل', $options);
            $this->assertNotContains('يورو', $options);

            return true;
        });

        // #47 — the account type is a read-only mirror that is never posted,
        // and `account_type_id` is not a field on this form at all.
        $page->assertFormFieldExists('muwakha_account_type_display', function ($field): bool {
            $this->assertTrue($field->isDisabled());
            $this->assertFalse($field->isDehydrated());

            return true;
        });

        $page->assertFormFieldDoesNotExist('account_type_id');
    }

    // -------------------------------------------------------------------- export

    /** #48 + #49 + #50 + #51 — exports carry the CURRENT Account only. */
    public function test_exports_carry_the_current_account_after_a_switch(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();
        $otherBank = $this->seedBankType('بنك القدس');

        app(PermissionSyncService::class)->sync();
        $this->actingAs($this->superAdmin());

        $family = $this->families->create($this->familyData([
            'account_code' => '000111',
            'iban' => 'PS11BANK000000000000111',
            'account_holder_name' => 'فاطمة أحمد',
        ]));

        $this->families->update($family->fresh(), $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '000222',
            'bank_type_id' => $otherBank->id,
            'iban' => 'PS22BANK000000000000222',
            'account_holder_name' => 'سعاد سالم',
        ]));

        $rows = $this->exportRowsOf(Livewire::test(ListMuwakhaFamilies::class)->instance());

        $this->assertCount(1, $rows, 'The register exports one row per FAMILY, never one per historical Account.');

        $row = $rows[0];

        $this->assertSame('سعاد سالم', $row->accountHolderName);
        $this->assertSame('000222', $row->accountCode);                            // #51
        $this->assertSame('بنك القدس', $row->bankTypeName);
        $this->assertSame('PS22BANK000000000000222', $row->iban);
        $this->assertSame('دولار', $row->currencyName);                            // #50

        // #48 — the workbook, read back.
        $headers = MuwakhaFamilyExportRow::headers();
        $path = $this->tempFile($this->capture(app(MuwakhaFamiliesExcelExportService::class)->stream($rows)), 'xlsx');
        $sheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path)->getActiveSheet();

        $values = [];

        foreach (range(1, $sheet->getHighestRow()) as $rowNumber) {
            foreach (range(1, count($headers)) as $column) {
                $values[] = (string) $sheet->getCell([$column, $rowNumber])->getValue();
            }
        }

        $this->assertContains('000222', $values, 'A leading-zero number must survive as text.');
        $this->assertContains('دولار', $values);
        $this->assertContains('سعاد سالم', $values);
        $this->assertNotContains('000111', $values, 'The superseded Account must not appear.');

        // #49 — the document, read back.
        $zip = new \ZipArchive;
        $zip->open($this->tempFile($this->capture(app(MuwakhaFamiliesWordExportService::class)->stream($rows)), 'docx'));
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('000222', $document);
        $this->assertStringContainsString('دولار', $document);
        $this->assertStringContainsString('سعاد سالم', $document);
        $this->assertStringNotContainsString('000111', $document);
    }

    // ------------------------------------------------------------ audit / privacy

    /** #52 — the approved redaction still holds across a switch. */
    public function test_personal_identifiers_are_still_redacted_across_a_switch(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $secrets = [
            'martyr_national_id' => '0412345678',
            'guardian_national_id' => '0498765432',
            'guardian_phone' => '0599123456',
        ];

        $family = $this->families->create($this->familyData($secrets));

        $this->families->update($family->fresh(), $this->familyData($secrets + [
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        $encoded = (string) json_encode(AuditEvent::all()->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (array_values($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        $familyEvent = AuditEvent::where('subject_type', 'muwakha_family')
            ->where('event_action', 'created')->sole();

        foreach (array_keys($secrets) as $field) {
            $this->assertSame(AuditRedactor::MARKER, $familyEvent->new_values[$field]);
        }
    }

    /** #53 + #54 */
    public function test_a_switch_audits_the_new_account_the_mapping_and_the_family(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['account_code' => '111']));
        $oldAccountId = $family->account_id;

        AuditEvent::query()->delete();

        $this->families->update($family->fresh(), $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        $newAccountId = $family->fresh()->account_id;

        // #54 — one ordinary Account `created` event, for the NEW account only.
        $accountEvent = AuditEvent::where('subject_type', 'account')->sole();

        $this->assertSame('created', $accountEvent->event_action);
        $this->assertSame($newAccountId, (int) $accountEvent->subject_key);

        // The mapping is audited like any other domain row.
        $mappingEvent = AuditEvent::where('subject_type', 'muwakha_family_account')->sole();

        $this->assertSame('created', $mappingEvent->event_action);
        $this->assertSame($newAccountId, (int) $mappingEvent->new_values['account_id']);

        // #53 — the family records the switch.
        $familyEvent = AuditEvent::where('subject_type', 'muwakha_family')->sole();

        $this->assertContains('account_id', $familyEvent->changed_fields);
        $this->assertSame($oldAccountId, (int) $familyEvent->old_values['account_id']);
        $this->assertSame($newAccountId, (int) $familyEvent->new_values['account_id']);
    }

    /** #55 — no mutation event is manufactured for the superseded Account. */
    public function test_the_superseded_account_receives_no_mutation_event(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['account_code' => '111']));
        $oldAccountId = $family->account_id;

        AuditEvent::query()->delete();

        $this->families->update($family->fresh(), $this->familyData([
            'currency_id' => $dollar->id,
            'account_code' => '222',
        ]));

        $this->assertSame(
            0,
            AuditEvent::where('subject_type', 'account')->where('subject_key', (string) $oldAccountId)->count(),
        );
    }

    // ---------------------------------------------------------------- atomicity

    /** #56 + #58 — the new Account cannot be inserted. */
    public function test_a_failed_account_insert_rolls_everything_back(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData());
        $originalAccountId = $family->account_id;

        try {
            $this->families->update($family->fresh(), $this->familyData([
                'currency_id' => $dollar->id,
                'bank_type_id' => 999999,
            ]));
            $this->fail('An Account with an invalid bank type must not be insertable.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($originalAccountId, $family->fresh()->account_id);       // #58
        $this->assertSame(1, Account::count());
        $this->assertSame(1, MuwakhaFamilyAccount::count());
    }

    /** #57 + #58 — the mapping cannot be inserted. */
    public function test_a_failed_mapping_insert_rolls_everything_back(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['account_code' => '111']));
        $originalAccountId = $family->account_id;

        // Making `account_holder_name` NOT-NULLable in the schema is the only
        // column a submitted value reaches on the mapping, so the insert is
        // failed by removing the table's ability to accept it at all.
        DB::statement('DROP TABLE muwakha_family_accounts');

        try {
            $this->families->update($family->fresh(), $this->familyData([
                'currency_id' => $dollar->id,
                'account_code' => '222',
            ]));
            $this->fail('A failing mapping insert must abort the whole switch.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($originalAccountId, $family->fresh()->account_id);       // #58
        $this->assertSame(1, Account::count(), 'The new Account must have been rolled back.'); // #57
    }

    /** #58 — the family UPDATE itself fails. */
    public function test_a_failed_family_update_leaves_the_family_on_its_previous_account(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $this->families->create($this->familyData(['martyr_national_id' => '1111111111']));
        $family = $this->families->create($this->familyData([
            'martyr_national_id' => '2222222222',
            'martyr_name' => 'محمد',
        ]));

        $originalAccountId = $family->account_id;

        try {
            $this->families->update($family->fresh(), $this->familyData([
                'martyr_national_id' => '1111111111',
                'martyr_name' => 'محمد',
                'currency_id' => $dollar->id,
            ]));
            $this->fail('A duplicate martyr national id must not be updatable.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($originalAccountId, $family->fresh()->account_id);
        $this->assertSame('2222222222', $family->fresh()->martyr_national_id);
        $this->assertSame(2, Account::count());
        $this->assertSame(2, MuwakhaFamilyAccount::count());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Data providers are static and run before the database exists, so they
     * express bank types and currencies by NAME/CODE and this resolves them to
     * live ids at run time.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function resolve(array $change): array
    {
        if (($change['currency_id'] ?? null) === 'USD') {
            $change['currency_id'] = $this->seedDollarCurrency()->id;
        }

        if (is_string($change['bank_type_id'] ?? null)) {
            $change['bank_type_id'] = $this->seedBankType($change['bank_type_id'])->id;
        }

        return $change;
    }

    private function superAdmin(): User
    {
        app(PermissionSyncService::class)->sync();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }

    private function postLineAgainst(int $accountId, int $currencyId): TransactionLine
    {
        $transaction = Transaction::create([
            'fiscal_year_id' => FiscalYear::create([
                'name' => 'سنة 2026', 'start_date' => '2026-01-01',
                'end_date' => '2026-12-31', 'is_active' => true,
            ])->id,
            'transaction_type_id' => TransactionType::create(['name' => 'نوع معاملة'])->id,
            'transaction_number' => 'TXN-MUW-0001',
            'transaction_time' => now(),
        ]);

        return TransactionLine::create([
            'transaction_id' => $transaction->id,
            'account_id' => $accountId,
            'currency_id' => $currencyId,
            'amount_currency' => 500,
            'debit_base' => 500,
            'credit_base' => 0,
        ]);
    }

    /**
     * @return array<int, MuwakhaFamilyExportRow>
     */
    private function exportRowsOf(ListMuwakhaFamilies $page): array
    {
        $method = new \ReflectionMethod($page, 'exportRows');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    private function capture(mixed $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function tempFile(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'muwakha').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }
}
