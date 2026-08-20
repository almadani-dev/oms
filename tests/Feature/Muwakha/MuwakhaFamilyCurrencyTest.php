<?php

namespace Tests\Feature\Muwakha;

use App\Filament\Resources\MuwakhaFamilies\Pages\CreateMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\Pages\ListMuwakhaFamilies;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\FiscalYear;
use App\Models\MuwakhaFamily;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Audit\AuditRedactor;
use App\Services\Muwakha\MuwakhaFamiliesExcelExportService;
use App\Services\Muwakha\MuwakhaFamiliesWordExportService;
use App\Services\Muwakha\MuwakhaFamilyExportRow;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Muwakha\MuwakhaReference;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * The approved family-account currency behaviour, end to end.
 *
 * Two rules are load-bearing and every test below exists to pin one of them:
 *
 *  1. The currency is CHOSEN per family and stored in exactly one place,
 *     `accounts.currency_id`. It is never duplicated onto `muwakha_families`
 *     and there is no ILS default anywhere.
 *
 *  2. An existing Account's `currency_id` is NEVER mutated. Changing a
 *     family's currency resolves to a new Account (or, when the family already
 *     owns one with exactly that identity, back to it) and repoints
 *     `muwakha_families.account_id`; the superseded Account — name, code, bank,
 *     IBAN, balance, active flag, currency and its entire ledger — is left
 *     byte-identical. Those superseded Accounts ARE the financial history,
 *     which is why no account-history table exists.
 *
 * The full material-identity and exact-reuse matrix lives in
 * MuwakhaFamilyAccountIdentityTest; this file stays focused on the currency.
 */
class MuwakhaFamilyCurrencyTest extends MuwakhaTestCase
{
    private MuwakhaFamilyService $families;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->families = app(MuwakhaFamilyService::class);
    }

    // ------------------------------------------------------------ selection

    /** #1 */
    public function test_a_family_may_be_created_in_shekels(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();

        $family = $this->families->create($this->familyData(['currency_id' => $shekel->id]));

        $this->assertSame($shekel->id, $this->accountOf($family)->currency_id);
        $this->assertSame('أسرة الشهيد أحمد محمد - شيكل - (123456)', $this->accountOf($family)->name);
    }

    /** #2 */
    public function test_a_family_may_be_created_in_dollars(): void
    {
        $this->seedIndividualsAccountType();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['currency_id' => $dollar->id]));

        $this->assertSame($dollar->id, $this->accountOf($family)->currency_id);
        $this->assertSame('أسرة الشهيد أحمد محمد - دولار - (123456)', $this->accountOf($family)->name);
    }

    /** #3 */
    public function test_a_family_may_be_created_in_euros(): void
    {
        $this->seedIndividualsAccountType();
        $euro = $this->seedEuroCurrency();

        $family = $this->families->create($this->familyData(['currency_id' => $euro->id]));

        $this->assertSame($euro->id, $this->accountOf($family)->currency_id);
        $this->assertSame('أسرة الشهيد أحمد محمد - يورو - (123456)', $this->accountOf($family)->name);
    }

    /** #4 — required on the form... */
    public function test_the_currency_select_is_required_on_the_form(): void
    {
        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());

        $data = $this->familyData();
        unset($data['currency_id']);

        Livewire::test(CreateMuwakhaFamily::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasFormErrors(['currency_id' => 'required']);

        $this->assertSame(0, MuwakhaFamily::count());
        $this->assertSame(0, Account::count());
    }

    /** #4 — ...and re-checked server-side against the live currency set. */
    public function test_an_unknown_or_trashed_currency_is_rejected_server_side(): void
    {
        $reference = $this->seedMuwakhaReference();
        $retired = $this->seedEuroCurrency();
        $retired->delete();

        foreach ([null, 999999, $retired->id] as $submitted) {
            try {
                $this->families->create($this->familyData([
                    'currency_id' => $submitted,
                    'martyr_national_id' => (string) random_int(1000000000, 9999999999),
                ]));
                $this->fail('A blank, unknown or soft-deleted currency must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('currency_id', $exception->errors());
            }
        }

        // A soft-deleted currency is also gone from the Select's options.
        $this->assertArrayNotHasKey($retired->id, MuwakhaReference::currencyOptions());
        $this->assertArrayHasKey($reference['currency']->id, MuwakhaReference::currencyOptions());

        $this->assertSame(0, MuwakhaFamily::count());
        $this->assertSame(0, Account::count());
    }

    /** #5 + #7 */
    public function test_the_account_receives_the_selected_currency_and_stays_on_individuals(): void
    {
        $reference = $this->seedMuwakhaReference();
        $euro = $this->seedEuroCurrency();

        $account = $this->accountOf($this->families->create($this->familyData(['currency_id' => $euro->id])));

        $this->assertSame($euro->id, $account->currency_id);
        $this->assertSame($reference['account_type']->id, $account->account_type_id);
        $this->assertSame(MuwakhaReference::ACCOUNT_TYPE_NAME, $account->accountType->name);
    }

    /** #6 — the name rule is the ONE rule, resolved from `currencies.name`. */
    public function test_the_account_name_uses_the_single_naming_rule(): void
    {
        $this->seedIndividualsAccountType();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد محمد',
            'currency_id' => $dollar->id,
        ]));

        $this->assertSame(
            MuwakhaFamilyService::accountNameFor('أحمد محمد', 'دولار', '123456'),
            $this->accountOf($family)->name,
        );
        $this->assertStringContainsString('دولار', $this->accountOf($family)->name);
    }

    /** #8 — the currency lives on the Account and nowhere else. */
    public function test_the_currency_is_not_duplicated_on_the_family_row(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());

        $this->assertFalse(
            Schema::hasColumn('muwakha_families', 'currency_id'),
            'muwakha_families must carry no currency column.',
        );
        $this->assertArrayNotHasKey('currency_id', $family->fresh()->getAttributes());
        $this->assertArrayNotHasKey(
            'currency_id',
            AuditEvent::where('subject_type', 'muwakha_family')->sole()->new_values,
        );
    }

    // -------------------------------------------- edit WITHOUT an identity change

    /**
     * Resubmitting the identical account identity — including the currency —
     * writes no Account at all. (Martyr-name, account-number, bank and IBAN
     * behaviour now lives in MuwakhaFamilyAccountIdentityTest, where those
     * fields are material identity rather than currency concerns.)
     */
    public function test_resubmitting_the_same_currency_creates_no_new_account(): void
    {
        $this->seedIndividualsAccountType();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData(['currency_id' => $dollar->id]));
        $originalAccountId = $family->account_id;
        $before = Account::findOrFail($originalAccountId)->getAttributes();

        $this->families->update($family, $this->familyData([
            'currency_id' => $dollar->id,
            'guardian_phone' => '0599000111',
        ]));

        $this->assertSame($originalAccountId, $family->fresh()->account_id);
        $this->assertSame(1, Account::count());
        $this->assertSame($before, Account::findOrFail($originalAccountId)->getAttributes());
    }

    // ---------------------------------------------- edit WITH a currency change

    /** #14 + #15 + #16 + #17 + #18 + #19 + #20 */
    public function test_a_currency_change_forks_exactly_one_new_account(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();
        $otherBank = $this->seedBankType('بنك القدس');

        $family = $this->families->create($this->familyData(['martyr_name' => 'أحمد']));
        $oldAccountId = $family->account_id;

        $this->families->update($family, $this->familyData([
            'martyr_name' => 'أحمد',
            'currency_id' => $dollar->id,
            'account_code' => '444111',
            'bank_type_id' => $otherBank->id,
            'iban' => 'PS55BANK000000000000555',
        ]));

        // #14 — exactly one new Account, so two in total.
        $this->assertSame(2, Account::count());

        // #15 — the family now points at the new one.
        $newAccountId = $family->fresh()->account_id;
        $this->assertNotSame($oldAccountId, $newAccountId);

        $new = Account::findOrFail($newAccountId);

        $this->assertSame($dollar->id, $new->currency_id);                      // #16
        $this->assertSame('أسرة الشهيد أحمد - دولار - (444111)', $new->name);              // #17
        $this->assertStringContainsString('دولار', $new->name);
        $this->assertSame('444111', $new->account_code);                        // #18
        $this->assertSame($otherBank->id, $new->bank_type_id);
        $this->assertSame('PS55BANK000000000000555', $new->iban);
        $this->assertSame('0.00', (string) $new->current_balance);              // #19
        $this->assertTrue((bool) $new->is_active);
        $this->assertSame(MuwakhaReference::ACCOUNT_TYPE_NAME, $new->accountType->name);

        // #20 — no opening-balance entry on any path.
        $this->assertSame(0, Transaction::count());
        $this->assertDatabaseCount('transaction_lines', 0);
    }

    /** #21 → #28 — the superseded Account is byte-identical afterwards. */
    public function test_the_previous_account_is_left_completely_untouched(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $euro = $this->seedEuroCurrency();
        $otherBank = $this->seedBankType('بنك القدس');

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد',
            'account_code' => '123456',
            'iban' => 'PS11BANK000000000000111',
        ]));

        $oldAccountId = $family->account_id;

        // A real balance, moved the only way a balance may move in OMS is not
        // reproducible here without posting entries, so it is written directly
        // to prove the fork does not recompute or reset it.
        Account::whereKey($oldAccountId)->update(['current_balance' => 1234.56]);

        $before = Account::findOrFail($oldAccountId)->getAttributes();

        $this->families->update($family, $this->familyData([
            'martyr_name' => 'خالد',
            'currency_id' => $euro->id,
            'account_code' => '999888',
            'bank_type_id' => $otherBank->id,
            'iban' => 'PS22BANK000000000000222',
        ]));

        $old = Account::withTrashed()->find($oldAccountId);

        $this->assertNotNull($old, 'The old Account must still exist.');          // #21
        $this->assertFalse($old->trashed(), 'The old Account must not be soft-deleted.');
        $this->assertTrue((bool) $old->is_active, 'The old Account must stay active.'); // #22

        // #23 → #28, plus every other column, in one comparison.
        $this->assertSame($before, $old->getAttributes());

        // Spelled out individually so a failure names the violated rule.
        $this->assertSame($before['currency_id'], $old->currency_id);            // #23
        $this->assertSame('أسرة الشهيد أحمد - شيكل - (123456)', $old->name);                // #24
        $this->assertSame('123456', $old->account_code);                         // #25
        $this->assertSame($before['bank_type_id'], $old->bank_type_id);          // #26
        $this->assertSame('PS11BANK000000000000111', $old->iban);                // #27
        $this->assertSame('1234.56', (string) $old->current_balance);            // #28
    }

    /** #29 */
    public function test_the_previous_accounts_ledger_is_left_untouched(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData());
        $oldAccountId = $family->account_id;

        $line = $this->postLineAgainst($oldAccountId, $shekel->id);

        $transactionsBefore = DB::table('transactions')->orderBy('id')->get()->toArray();
        $linesBefore = DB::table('transaction_lines')->orderBy('id')->get()->toArray();

        $this->families->update($family, $this->familyData(['currency_id' => $dollar->id]));

        $this->assertEquals($transactionsBefore, DB::table('transactions')->orderBy('id')->get()->toArray());
        $this->assertEquals($linesBefore, DB::table('transaction_lines')->orderBy('id')->get()->toArray());

        // The line still points at the OLD account, not at the new one.
        $this->assertSame($oldAccountId, TransactionLine::findOrFail($line->id)->account_id);
        $this->assertNotSame($family->fresh()->account_id, TransactionLine::findOrFail($line->id)->account_id);
    }

    /** #30 — a failed fork rolls back everything, including the repoint. */
    public function test_a_failed_new_account_leaves_the_family_on_its_original_account(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData());
        $originalAccountId = $family->account_id;

        // A non-existent bank type violates accounts.bank_type_id, failing the
        // new Account's INSERT inside the transaction.
        try {
            $this->families->update($family, $this->familyData([
                'currency_id' => $dollar->id,
                'bank_type_id' => 999999,
            ]));
            $this->fail('An Account with an invalid bank type must not be insertable.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($originalAccountId, $family->fresh()->account_id);
        $this->assertSame(1, Account::count(), 'No half-created Account may survive.');
        $this->assertSame($this->seedShekelCurrency()->id, $this->accountOf($family->fresh())->currency_id);
    }

    /** #30 — the same guarantee when it is the FAMILY update that fails. */
    public function test_a_failed_family_update_leaves_the_family_on_its_original_account(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $taken = $this->families->create($this->familyData(['martyr_national_id' => '1111111111']));
        $family = $this->families->create($this->familyData([
            'martyr_national_id' => '2222222222',
            'martyr_name' => 'محمد',
        ]));

        $originalAccountId = $family->account_id;

        // The new Account inserts fine; the family UPDATE then violates the
        // UNIQUE martyr_national_id, so the whole operation must unwind.
        try {
            $this->families->update($family, $this->familyData([
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
        $this->assertSame(2, Account::count(), 'The forked Account must have been rolled back.');
        $this->assertSame($taken->account_id, $taken->fresh()->account_id);
    }

    /** #31 */
    public function test_repeated_currency_changes_stack_up_separate_untouched_accounts(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();
        $euro = $this->seedEuroCurrency();

        $family = $this->families->create($this->familyData(['martyr_name' => 'أحمد']));

        $shekelAccountId = $family->account_id;
        $shekelSnapshot = Account::findOrFail($shekelAccountId)->getAttributes();

        $this->families->update($family, $this->familyData([
            'martyr_name' => 'أحمد',
            'currency_id' => $dollar->id,
        ]));

        $dollarAccountId = $family->fresh()->account_id;
        $dollarSnapshot = Account::findOrFail($dollarAccountId)->getAttributes();

        $this->families->update($family->fresh(), $this->familyData([
            'martyr_name' => 'أحمد',
            'currency_id' => $euro->id,
        ]));

        $euroAccountId = $family->fresh()->account_id;

        $this->assertCount(3, array_unique([$shekelAccountId, $dollarAccountId, $euroAccountId]));
        $this->assertSame(3, Account::count());

        // Every earlier Account is exactly as it was left.
        $this->assertSame($shekelSnapshot, Account::findOrFail($shekelAccountId)->getAttributes());
        $this->assertSame($dollarSnapshot, Account::findOrFail($dollarAccountId)->getAttributes());

        $this->assertSame($shekel->id, Account::findOrFail($shekelAccountId)->currency_id);
        $this->assertSame($dollar->id, Account::findOrFail($dollarAccountId)->currency_id);
        $this->assertSame($euro->id, Account::findOrFail($euroAccountId)->currency_id);

        $this->assertSame('أسرة الشهيد أحمد - شيكل - (123456)', Account::findOrFail($shekelAccountId)->name);
        $this->assertSame('أسرة الشهيد أحمد - دولار - (123456)', Account::findOrFail($dollarAccountId)->name);
        $this->assertSame('أسرة الشهيد أحمد - يورو - (123456)', Account::findOrFail($euroAccountId)->name);
    }

    /**
     * Going back to an identity the family already owns REUSES that Account —
     * the exhaustive reuse matrix lives in MuwakhaFamilyAccountIdentityTest.
     */
    public function test_returning_to_the_original_currency_reuses_the_original_account(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData());
        $firstShekelAccountId = $family->account_id;

        $this->families->update($family, $this->familyData(['currency_id' => $dollar->id]));
        $this->families->update($family->fresh(), $this->familyData(['currency_id' => $shekel->id]));

        $this->assertSame(2, Account::count(), 'The original ILS Account must be reused, not duplicated.');
        $this->assertSame($firstShekelAccountId, $family->fresh()->account_id);
        $this->assertSame($shekel->id, $this->accountOf($family->fresh())->currency_id);
    }

    // -------------------------------------------------------------- auditing

    /** #11 (brief §11) — existing Account audit behaviour, nothing new. */
    public function test_a_currency_change_audits_a_new_account_and_the_family_repoint(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData());
        $oldAccountId = $family->account_id;

        AuditEvent::query()->delete();

        $this->families->update($family, $this->familyData(['currency_id' => $dollar->id]));

        // One `created` mapping event for the newly owned Account.
        $this->assertSame(1, AuditEvent::where('subject_type', 'muwakha_family_account')->count());

        // One `created` Account event for the new Account, and NO event on the
        // old Account — because nothing wrote to it.
        $accountEvents = AuditEvent::where('subject_type', 'account')->get();

        $this->assertCount(1, $accountEvents);
        $this->assertSame('created', $accountEvents->first()->event_action);
        $this->assertNotSame($oldAccountId, (int) $accountEvents->first()->subject_key);

        // The family event records that account_id moved.
        $familyEvent = AuditEvent::where('subject_type', 'muwakha_family')->sole();

        $this->assertSame('updated', $familyEvent->event_action);
        $this->assertContains('account_id', $familyEvent->changed_fields);
        $this->assertSame($oldAccountId, (int) $familyEvent->old_values['account_id']);
        $this->assertSame($family->fresh()->account_id, (int) $familyEvent->new_values['account_id']);
    }

    /** #34 — the personal-data redaction still holds on this path. */
    public function test_a_currency_change_leaks_no_personal_data(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        $family = $this->families->create($this->familyData([
            'martyr_national_id' => '0412345678',
            'guardian_national_id' => '0498765432',
            'guardian_phone' => '0599123456',
        ]));

        AuditEvent::query()->delete();

        $this->families->update($family, $this->familyData([
            'martyr_national_id' => '0412345678',
            'guardian_national_id' => '0498765432',
            'guardian_phone' => '0599123456',
            'currency_id' => $dollar->id,
        ]));

        $encoded = (string) json_encode(AuditEvent::all()->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (['0412345678', '0498765432', '0599123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        $this->assertNotSame('', AuditRedactor::MARKER);
    }

    // --------------------------------------------------------------- exports

    /** #32 + #33 — both files carry the ACTUAL currency, never a fixed ILS. */
    public function test_both_exports_carry_the_actual_account_currency(): void
    {
        $this->seedIndividualsAccountType();
        $shekel = $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        app(PermissionSyncService::class)->sync();
        $this->actingAs($this->superAdmin());

        $this->families->create($this->familyData([
            'martyr_name' => 'أحمد', 'martyr_national_id' => '1111111111',
            'currency_id' => $shekel->id,
        ]));
        $this->families->create($this->familyData([
            'martyr_name' => 'محمد', 'martyr_national_id' => '2222222222',
            'currency_id' => $dollar->id,
        ]));

        // The row DTO resolves the linked Account's own currency.
        $rows = $this->exportRowsOf(Livewire::test(ListMuwakhaFamilies::class)->instance());

        $this->assertSame(['شيكل', 'دولار'], array_map(fn ($row) => $row->currencyName, $rows));

        // ...and the shared header/value contract keeps the two files aligned.
        $headers = MuwakhaFamilyExportRow::headers();
        $currencyIndex = array_search('العملة', $headers, true);

        $this->assertNotFalse($currencyIndex, 'The exports must carry a العملة column.');
        $this->assertSame('شيكل', $rows[0]->values()[$currencyIndex]);
        $this->assertSame('دولار', $rows[1]->values()[$currencyIndex]);
        $this->assertCount(count($headers), $rows[0]->values());

        // #32 — the real workbook.
        $path = $this->tempFile($this->capture(app(MuwakhaFamiliesExcelExportService::class)->stream($rows)), 'xlsx');
        $sheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path)->getActiveSheet();

        $column = $currencyIndex + 1;
        $cells = [];

        foreach (range(1, $sheet->getHighestRow()) as $rowNumber) {
            $cells[] = (string) $sheet->getCell([$column, $rowNumber])->getValue();
        }

        $this->assertContains('العملة', $cells);
        $this->assertContains('شيكل', $cells);
        $this->assertContains('دولار', $cells);
        $this->assertNotContains('ILS', $cells);

        // #33 — the real document.
        $zip = new \ZipArchive;
        $zip->open($this->tempFile($this->capture(app(MuwakhaFamiliesWordExportService::class)->stream($rows)), 'docx'));
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('العملة', $document);
        $this->assertStringContainsString('شيكل', $document);
        $this->assertStringContainsString('دولار', $document);
    }

    // ----------------------------------------------------------------- table

    public function test_the_table_currency_column_shows_the_real_account_currency(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();

        app(PermissionSyncService::class)->sync();
        $this->actingAs($this->superAdmin());

        $family = $this->families->create($this->familyData(['currency_id' => $dollar->id]));

        $columns = Livewire::test(ListMuwakhaFamilies::class)->instance()->getTable()->getColumns();

        $this->assertArrayHasKey('account.currency.name', $columns);
        $this->assertTrue($columns['account.currency.name']->isToggleable());
        $this->assertTrue($columns['account.currency.name']->isToggledHiddenByDefault());

        Livewire::test(ListMuwakhaFamilies::class)
            ->assertTableColumnStateSet('account.currency.name', 'دولار', $family);
    }

    // ----------------------------------------------------------------- helpers

    private function superAdmin(): User
    {
        // Idempotent: creates the roles/permissions this panel authorizes against.
        app(PermissionSyncService::class)->sync();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }

    /**
     * One ordinary transaction line against an account, used only to prove the
     * currency fork leaves an existing ledger alone. Nothing here goes through
     * the financial workflow — it is fixture data, not a posting path.
     */
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
