<?php

namespace Tests\Feature\Muwakha;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyProject;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit\AuditRedactor;
use App\Services\Muwakha\MuwakhaFamilyProjectService;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Support\Muwakha\MuwakhaReference;
use App\Support\Muwakha\MuwakhaReferenceException;
use Illuminate\Validation\ValidationException;

/**
 * §19 "Family create" (5-18), "Synchronization" (36-42), "Project links"
 * (43-54) and "Delete" (55-60), exercised through the real domain services.
 */
class MuwakhaFamilyServiceTest extends MuwakhaTestCase
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

    // ---------------------------------------------------------------- create

    /** #5 + #6 + #11 */
    public function test_creates_a_family_with_no_projects_and_exactly_one_account(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());

        $this->assertSame(1, MuwakhaFamily::count());
        $this->assertSame(1, Account::count());
        $this->assertSame(0, MuwakhaFamilyProject::count());
        $this->assertNotNull($family->account_id);
        $this->assertSame($family->account_id, Account::first()->id);
    }

    /** #7 — name = martyr name + the SELECTED currency's display name. */
    public function test_account_name_is_derived_from_the_martyr_name_and_currency(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData(['martyr_name' => 'أحمد محمد']));

        $this->assertSame('أسرة الشهيد أحمد محمد - شيكل - (123456)', $this->accountOf($family)->name);
    }

    /** #8 + #9 + #10 */
    public function test_account_uses_the_individuals_type_the_selected_currency_and_zero_balance(): void
    {
        $reference = $this->seedMuwakhaReference();

        $account = $this->accountOf($this->families->create($this->familyData()));

        $this->assertSame($reference['account_type']->id, $account->account_type_id);
        $this->assertSame(MuwakhaReference::ACCOUNT_TYPE_NAME, $account->accountType->name);
        $this->assertSame($reference['currency']->id, $account->currency_id);
        $this->assertSame('ILS', $account->currency->code);
        $this->assertSame('0.00', (string) $account->current_balance);
    }

    /** #12 + #13 + #14 + #15 */
    public function test_payment_fields_land_on_the_right_records(): void
    {
        $reference = $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد محمد',
            'account_holder_name' => 'فاطمة أحمد محمد',
            'account_code' => '987654',
            'iban' => 'PS00ARAB000000000000123456789',
        ]));

        $account = $this->accountOf($family);

        // The holder name is family-owned and is never used as the account name.
        $this->assertSame('فاطمة أحمد محمد', $family->account_holder_name);
        $this->assertSame('أسرة الشهيد أحمد محمد - شيكل - (987654)', $account->name);
        $this->assertNotSame($family->account_holder_name, $account->name);

        $this->assertSame('987654', $account->account_code);
        $this->assertSame($reference['bank_type']->id, $account->bank_type_id);
        $this->assertSame('PS00ARAB000000000000123456789', $account->iban);
    }

    /** #16 — the opening-balance branch must never be reached. */
    public function test_no_opening_balance_transaction_is_created(): void
    {
        $this->seedMuwakhaReference();

        $this->families->create($this->familyData());

        $this->assertSame(0, Transaction::count());
        $this->assertDatabaseCount('transaction_lines', 0);
    }

    /** #17 + #18 */
    public function test_two_families_may_share_an_account_number_but_get_distinct_accounts(): void
    {
        $this->seedMuwakhaReference();

        $first = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد',
            'martyr_national_id' => '1111111111',
            'account_code' => '123456',
        ]));

        $second = $this->families->create($this->familyData([
            'martyr_name' => 'محمد',
            'martyr_national_id' => '2222222222',
            'account_code' => '123456',
        ]));

        $this->assertNotSame($first->account_id, $second->account_id);
        $this->assertSame('123456', $this->accountOf($first)->account_code);
        $this->assertSame('123456', $this->accountOf($second)->account_code);
        $this->assertSame('أسرة الشهيد أحمد - شيكل - (123456)', $this->accountOf($first)->name);
        $this->assertSame('أسرة الشهيد محمد - شيكل - (123456)', $this->accountOf($second)->name);
    }

    public function test_create_fails_closed_when_the_individuals_account_type_is_missing(): void
    {
        $this->seedShekelCurrency();
        $this->seedBankType();

        $this->expectException(MuwakhaReferenceException::class);

        $this->families->create($this->familyData());
    }

    public function test_create_fails_closed_when_the_account_type_is_ambiguous(): void
    {
        $this->seedIndividualsAccountType();
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $this->seedBankType();

        $this->expectException(MuwakhaReferenceException::class);

        $this->families->create($this->familyData());
    }

    /**
     * The currency is an operator choice, not reference data, so an absent or
     * unknown one is a field-level validation failure rather than a
     * MuwakhaReferenceException.
     */
    public function test_create_rejects_a_missing_or_unknown_currency(): void
    {
        $this->seedMuwakhaReference();

        $data = $this->familyData();
        unset($data['currency_id']);

        try {
            $this->families->create($data);
            $this->fail('A family may not be created without a currency.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currency_id', $exception->errors());
        }

        $this->expectException(ValidationException::class);

        $this->families->create($this->familyData(['currency_id' => 999999]));
    }

    public function test_a_failed_family_insert_leaves_no_orphan_account(): void
    {
        $this->seedMuwakhaReference();

        $this->families->create($this->familyData(['martyr_national_id' => '5555555555']));

        // A duplicate national id violates the UNIQUE index, failing the
        // family insert AFTER its Account was already created inside the same
        // transaction. Nothing may survive.
        try {
            $this->families->create($this->familyData([
                'martyr_national_id' => '5555555555',
                'account_code' => '777',
            ]));
            $this->fail('A duplicate martyr national id must not be insertable.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(1, MuwakhaFamily::count());
        $this->assertSame(1, Account::count(), 'The rolled-back create must leave no orphan Account.');
        $this->assertNull(Account::where('account_code', '777')->first());
    }

    // ----------------------------------------------------------- synchronize

    /**
     * #36 + #40 + #41 + #42, restated for the final design (2026-08-17): the
     * martyr name is part of the canonical Account name and therefore part of
     * the Account identity, so correcting it never RENAMES the historical
     * Account — it produces a new one and leaves the old label intact.
     */
    public function test_editing_the_martyr_name_creates_a_new_account_and_never_renames_the_old_one(): void
    {
        $reference = $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData(['martyr_name' => 'أحمد محمد']));
        $originalAccountId = $family->account_id;

        $this->families->update($family, $this->familyData([
            'martyr_name' => 'خالد محمد',
        ]));

        $account = $this->accountOf($family->fresh());

        $this->assertNotSame($originalAccountId, $account->id);
        $this->assertSame(2, Account::count());
        $this->assertSame('أسرة الشهيد خالد محمد - شيكل - (123456)', $account->name);
        $this->assertSame($reference['account_type']->id, $account->account_type_id);
        $this->assertSame($reference['currency']->id, $account->currency_id);

        // The historical Account keeps the name it carried while it was in use.
        $this->assertSame(
            'أسرة الشهيد أحمد محمد - شيكل - (123456)',
            Account::findOrFail($originalAccountId)->name,
        );
    }

    /**
     * #37 + #38 + #39, restated: payment details ARE the Account identity, so
     * changing them creates a new Account rather than rewriting the one the
     * ledger already references.
     */
    public function test_editing_payment_details_creates_a_new_account(): void
    {
        $this->seedMuwakhaReference();
        $otherBank = $this->seedBankType('بنك القدس');

        $family = $this->families->create($this->familyData());
        $originalAccountId = $family->account_id;
        $before = Account::findOrFail($originalAccountId)->getAttributes();

        $this->families->update($family, $this->familyData([
            'account_code' => '555000',
            'bank_type_id' => $otherBank->id,
            'iban' => 'PS99BANK000000000000999',
        ]));

        $account = $this->accountOf($family->fresh());

        $this->assertNotSame($originalAccountId, $account->id);
        $this->assertSame('555000', $account->account_code);
        $this->assertSame($otherBank->id, $account->bank_type_id);
        $this->assertSame('PS99BANK000000000000999', $account->iban);
        $this->assertSame('أسرة الشهيد أحمد محمد - شيكل - (555000)', $account->name);

        $this->assertSame($before, Account::findOrFail($originalAccountId)->getAttributes());
    }

    /** A family-only edit touches no Account at all. */
    public function test_editing_non_account_family_data_keeps_the_same_account(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());
        $originalAccountId = $family->account_id;
        $before = Account::findOrFail($originalAccountId)->getAttributes();

        $this->families->update($family, $this->familyData([
            'guardian_name' => 'سعاد سالم',
            'guardian_phone' => '0599000111',
            'children_count' => 7,
            'notes' => 'ملاحظة',
        ]));

        $this->assertSame($originalAccountId, $family->fresh()->account_id);
        $this->assertSame(1, Account::count());
        $this->assertSame($before, Account::findOrFail($originalAccountId)->getAttributes());
        $this->assertSame(7, $family->fresh()->children_count);
    }

    /**
     * The account TYPE is still a hard invariant — only the currency became a
     * choice, and a currency change forks a new Account rather than moving the
     * existing one (proven in MuwakhaFamilyCurrencyTest).
     */
    public function test_update_cannot_move_the_account_off_individuals(): void
    {
        $reference = $this->seedMuwakhaReference();
        $otherType = \App\Models\AccountType::create(['name' => 'حساب الجمعية']);

        $family = $this->families->create($this->familyData());

        // A forged submission carrying account_type_id must be ignored.
        $this->families->update($family, $this->familyData([
            'account_type_id' => $otherType->id,
        ]));

        $account = $this->accountOf($family->fresh());

        $this->assertSame($reference['account_type']->id, $account->account_type_id);
        $this->assertSame($reference['currency']->id, $account->currency_id);
    }

    public function test_update_fails_closed_when_the_account_is_soft_deleted(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());
        Account::find($family->account_id)->delete();

        $this->expectException(ValidationException::class);

        $this->families->update($family->fresh(), $this->familyData(['martyr_name' => 'خالد']));
    }

    // ------------------------------------------------------------ delete

    /** #55 + #56 + #57 + #58 + #59 + #60 */
    public function test_deleting_a_family_leaves_its_account_completely_untouched(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData());
        $project = $this->makeMuwakhaProject();
        $this->links->link($family, ['project_id' => $project->id, 'card_code' => 'G 10']);

        $before = Account::find($family->account_id)->only([
            'id', 'account_code', 'name', 'account_type_id', 'bank_type_id',
            'currency_id', 'current_balance', 'is_active', 'iban',
        ]);

        $this->families->delete($family);

        $this->assertSoftDeleted('muwakha_families', ['id' => $family->id]);
        $this->assertSame(0, MuwakhaFamilyProject::where('muwakha_family_id', $family->id)->count());

        $account = Account::withTrashed()->find($family->account_id);

        $this->assertNotNull($account, 'The Account must still exist.');
        $this->assertFalse($account->trashed(), 'The Account must not be soft-deleted.');
        $this->assertSame($before, $account->only(array_keys($before)));

        // The durable ownership record survives the deletion untouched.
        $this->assertSame(1, \App\Models\MuwakhaFamilyAccount::where('muwakha_family_id', $family->id)->count());
    }

    // ------------------------------------------------------- project links

    /** #43 + #44 + #46 */
    public function test_a_family_may_link_to_several_eligible_projects(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());

        $this->assertSame(0, $family->familyProjects()->count());

        $first = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $second = $this->makeMuwakhaProject('مؤاخاة البركة 2026');

        $this->links->link($family, ['project_id' => $first->id, 'card_code' => 'G 10']);
        $this->links->link($family, ['project_id' => $second->id, 'card_code' => 'B 4']);

        $this->assertSame(2, $family->familyProjects()->count());
    }

    /** #45 */
    public function test_the_same_family_cannot_be_linked_twice_to_one_project(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());
        $project = $this->makeMuwakhaProject();

        $this->links->link($family, ['project_id' => $project->id]);

        $this->expectException(ValidationException::class);

        $this->links->link($family, ['project_id' => $project->id]);
    }

    /** #47 */
    public function test_card_code_is_optional_and_blank_is_stored_as_null(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());
        $project = $this->makeMuwakhaProject();

        $link = $this->links->link($family, ['project_id' => $project->id, 'card_code' => '   ']);

        $this->assertNull($link->card_code);
    }

    /** #48 */
    public function test_a_duplicate_card_code_within_one_project_is_rejected(): void
    {
        $this->seedMuwakhaReference();
        $project = $this->makeMuwakhaProject();

        $first = $this->families->create($this->familyData(['martyr_national_id' => '1111111111']));
        $second = $this->families->create($this->familyData([
            'martyr_national_id' => '2222222222',
            'martyr_name' => 'محمد',
        ]));

        $this->links->link($first, ['project_id' => $project->id, 'card_code' => 'G 10']);

        $this->expectException(ValidationException::class);

        $this->links->link($second, ['project_id' => $project->id, 'card_code' => 'G 10']);
    }

    /** #49 */
    public function test_the_same_card_code_may_exist_in_two_different_projects(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());

        $first = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $second = $this->makeMuwakhaProject('مؤاخاة كاف 2027');

        $this->links->link($family, ['project_id' => $first->id, 'card_code' => 'G 10']);
        $this->links->link($family, ['project_id' => $second->id, 'card_code' => 'G 10']);

        $this->assertSame(2, MuwakhaFamilyProject::where('card_code', 'G 10')->count());
    }

    /** #50 — forged request against an unrelated project. */
    public function test_an_unrelated_project_cannot_be_linked(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());
        $unrelated = $this->makeUnrelatedProject();

        $this->expectException(ValidationException::class);

        $this->links->link($family, ['project_id' => $unrelated->id]);
    }

    /** #51 — the root itself is a ProjectSuper and can never be a link target. */
    public function test_the_muwakha_root_cannot_be_linked_as_a_project(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());

        // The root's id interpreted as a project id resolves to no eligible
        // project — a ProjectSuper is not a Project at all.
        $this->assertFalse(MuwakhaReference::isEligibleProject($this->muwakhaRoot()->id));

        $this->expectException(ValidationException::class);

        $this->links->link($family, ['project_id' => $this->muwakhaRoot()->id]);
    }

    /** #52 + #53 + #54 */
    public function test_removing_one_link_leaves_everything_else_intact(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());

        $first = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $second = $this->makeMuwakhaProject('مؤاخاة كاف 2027');

        $removed = $this->links->link($family, ['project_id' => $first->id, 'card_code' => 'G 10']);
        $kept = $this->links->link($family, ['project_id' => $second->id, 'card_code' => 'B 4']);

        $accountBefore = Account::find($family->account_id)->toArray();

        $this->links->unlink($removed);

        $this->assertNull(MuwakhaFamilyProject::find($removed->id));
        $this->assertNotNull(MuwakhaFamilyProject::find($kept->id));
        $this->assertNotNull(MuwakhaFamily::find($family->id));
        $this->assertSame($accountBefore, Account::find($family->account_id)->toArray());
    }

    public function test_a_card_code_may_be_changed_on_an_existing_link(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());
        $project = $this->makeMuwakhaProject();

        $link = $this->links->link($family, ['project_id' => $project->id, 'card_code' => 'G 10']);

        $this->links->update($link, ['project_id' => $project->id, 'card_code' => 'G 11']);

        $this->assertSame('G 11', $link->fresh()->card_code);
    }

    // ------------------------------------------------------------- auditing

    public function test_creating_a_family_writes_one_event_per_subject(): void
    {
        $this->seedMuwakhaReference();

        $this->families->create($this->familyData());

        $this->assertSame(1, AuditEvent::where('subject_type', 'muwakha_family')->count());
        $this->assertSame(1, AuditEvent::where('subject_type', 'account')->count());
        $this->assertSame(1, AuditEvent::where('subject_type', 'muwakha_family_account')->count());

        $familyEvent = AuditEvent::where('subject_type', 'muwakha_family')->sole();

        $this->assertSame('crud', $familyEvent->event_category);
        $this->assertSame('created', $familyEvent->event_action);
        $this->assertSame('أحمد محمد', $familyEvent->new_values['martyr_name']);
    }

    public function test_link_and_unlink_each_write_exactly_one_event(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());
        $project = $this->makeMuwakhaProject();

        $link = $this->links->link($family, ['project_id' => $project->id, 'card_code' => 'G 10']);
        $this->links->update($link, ['project_id' => $project->id, 'card_code' => 'G 11']);
        $this->links->unlink($link);

        $events = AuditEvent::where('subject_type', 'muwakha_family_project')
            ->orderBy('id')->pluck('event_action')->all();

        $this->assertSame(['created', 'updated', 'deleted'], $events);
    }

    public function test_personal_identifiers_are_redacted_in_audit_payloads(): void
    {
        $this->seedMuwakhaReference();

        $this->families->create($this->familyData([
            'martyr_national_id' => '0412345678',
            'guardian_national_id' => '0498765432',
            'guardian_phone' => '0599123456',
        ]));

        $event = AuditEvent::where('subject_type', 'muwakha_family')->sole();

        foreach (['martyr_national_id', 'guardian_national_id', 'guardian_phone'] as $field) {
            $this->assertSame(
                AuditRedactor::MARKER,
                $event->new_values[$field],
                "[{$field}] must be redacted in the audit payload.",
            );
        }

        // The raw values must appear nowhere in the event at all — including
        // subject_label, which is displayed in the audit list.
        $encoded = json_encode($event->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (['0412345678', '0498765432', '0599123456'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $encoded);
        }

        $this->assertStringNotContainsString('0412345678', (string) $event->subject_label);

        // Non-sensitive business fields are still recorded in full.
        $this->assertSame('أحمد محمد', $event->new_values['martyr_name']);
        $this->assertSame('فاطمة أحمد', $event->new_values['guardian_name']);
    }

    public function test_changed_field_tracking_survives_redaction(): void
    {
        $this->seedMuwakhaReference();

        $family = $this->families->create($this->familyData(['guardian_phone' => '0599111111']));

        AuditEvent::query()->delete();

        $this->families->update($family, $this->familyData(['guardian_phone' => '0599222222']));

        $event = AuditEvent::where('subject_type', 'muwakha_family')->sole();

        // The trail records THAT the phone changed...
        $this->assertContains('guardian_phone', $event->changed_fields);
        // ...while withholding both values.
        $this->assertSame(AuditRedactor::MARKER, $event->old_values['guardian_phone']);
        $this->assertSame(AuditRedactor::MARKER, $event->new_values['guardian_phone']);

        $encoded = json_encode($event->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('0599111111', (string) $encoded);
        $this->assertStringNotContainsString('0599222222', (string) $encoded);
    }

    public function test_link_events_never_carry_a_national_id(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData(['martyr_national_id' => '0412345678']));
        $project = $this->makeMuwakhaProject();

        $this->links->link($family, ['project_id' => $project->id, 'card_code' => 'G 10']);

        $event = AuditEvent::where('subject_type', 'muwakha_family_project')->sole();

        $this->assertStringNotContainsString(
            '0412345678',
            (string) json_encode($event->toArray(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_a_family_only_edit_writes_no_account_event(): void
    {
        $this->seedMuwakhaReference();
        $family = $this->families->create($this->familyData());

        AuditEvent::query()->delete();

        // Only the guardian phone changes; every account field is resubmitted
        // unchanged, so AuditedCrudService must suppress the account event.
        $this->families->update($family, $this->familyData(['guardian_phone' => '0599000111']));

        $this->assertSame(1, AuditEvent::where('subject_type', 'muwakha_family')->count());
        $this->assertSame(0, AuditEvent::where('subject_type', 'account')->count());
    }
}
