<?php

namespace Tests\Feature\Muwakha;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Filament\Resources\MuwakhaFamilies\Pages\ViewMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\MuwakhaFamilies\Schemas\MuwakhaFamilyInfolist;
use App\Models\Account;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Models\User;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * `الحسابات المرتبطة بالأسرة` on the Family View page.
 *
 * A read-only window onto `muwakha_family_accounts`: every Account the family
 * owns, current one first, each row carrying ITS OWN historical
 * `account_holder_name` rather than the family's current one.
 *
 * Soft-deleted Accounts are excluded as a DISPLAY FILTER ONLY — the mapping
 * row survives untouched and nothing in this section can restore, reactivate
 * or otherwise write to an Account. That distinction is what the tests below
 * pin: hidden on screen, still present in the database.
 *
 * LAYOUT is pinned here too, because it is the point of the section: ONE
 * `<table>` row per Account rather than one card per Account, spanning the full
 * content width, positioned after `ملاحظات` and before `مشاريع المؤاخاة`.
 */
class MuwakhaFamilyLinkedAccountsTest extends MuwakhaTestCase
{
    private MuwakhaFamilyService $families;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSyncService::class)->sync();

        $this->families = app(MuwakhaFamilyService::class);
    }

    /** #1 + #4 + #5 + #6 */
    public function test_the_section_lists_every_mapped_account_with_its_own_historical_data(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $old, $current] = $this->familyWithTwoAccounts();

        $page = Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()]);

        $page->assertSee('الحسابات المرتبطة بالأسرة');

        // #1 + #4 — both Accounts are listed, not just the current one.
        $page->assertSee($current->name);
        $page->assertSee($old->name);

        // #6 — each row's currency, number, bank and IBAN come from ITS Account.
        foreach ([$current, $old] as $account) {
            $page->assertSee($account->currency->name);
            $page->assertSee($account->account_code);
            $page->assertSee($account->bankType->name);
            $page->assertSee($account->iban);
        }

        // #5 — the holder name is per MAPPING, so the historical row keeps the
        // holder it was registered to and does NOT show today's.
        $page->assertSee('فاطمة أحمد');
        $page->assertSee('سعاد سالم');

        $this->assertSame(
            ['سعاد سالم', 'فاطمة أحمد'],
            $family->fresh()->visibleAccountLinks()->pluck('account_holder_name')->all(),
        );
    }

    /** #2 + #3 */
    public function test_the_current_account_is_badged_and_listed_first(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $old, $current] = $this->familyWithTwoAccounts();

        $links = $family->fresh()->visibleAccountLinks();

        // #3 — current first, then previous.
        $this->assertSame(
            [$current->id, $old->id],
            $links->pluck('account_id')->all(),
        );

        $page = Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()]);

        // #2 — the Arabic badge is rendered.
        $page->assertSee(MuwakhaFamilyInfolist::CURRENT_BADGE);
        $page->assertSee(MuwakhaFamilyInfolist::PREVIOUS_BADGE);

        // ...and the current Account really precedes the previous one on screen.
        $html = $page->html();

        $this->assertLessThan(
            strpos($html, $old->name),
            strpos($html, $current->name),
            'The current Account must appear before the previous one.',
        );

        // #3 — the same order asserted on the TABLE ROWS, so the check survives
        // the account name appearing elsewhere on the page: the first body row
        // carries `الحساب الحالي`, the second `سابق`.
        $rows = $this->linkedAccountsRows($html);

        $this->assertCount(2, $rows, 'One table row per visible mapped Account.');

        // #2 + #4 — the badge on each row, in order.
        $this->assertStringContainsString(MuwakhaFamilyInfolist::CURRENT_BADGE, $rows[0]);
        $this->assertStringContainsString($current->name, $rows[0]);
        $this->assertStringNotContainsString(MuwakhaFamilyInfolist::PREVIOUS_BADGE, $rows[0]);

        $this->assertStringContainsString(MuwakhaFamilyInfolist::PREVIOUS_BADGE, $rows[1]);
        $this->assertStringContainsString($old->name, $rows[1]);
        $this->assertStringNotContainsString(MuwakhaFamilyInfolist::CURRENT_BADGE, $rows[1]);
    }

    /**
     * The section is a TABLE, not a stack of cards: one `<thead>` naming the
     * seven columns once, then one `<tr>` per Account whose cells carry the
     * value alone. A card layout would render Filament's repeatable ITEM
     * wrapper and repeat every label inside every row instead.
     */
    public function test_the_linked_accounts_render_as_one_compact_table_row_per_account(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $old, $current] = $this->familyWithTwoAccounts();

        $html = Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])->html();

        // #1 — rendered through Filament's table repeatable, so it inherits the
        // ordinary OMS/Filament table styling.
        $this->assertStringContainsString('fi-in-table-repeatable', $html);

        // ...and NOT as the stacked-card repeatable.
        $this->assertStringNotContainsString('fi-in-repeatable-item', $html);

        $table = $this->linkedAccountsTable($html);

        // The seven column headings, stated once each in the table head.
        $head = substr($table, 0, (int) strpos($table, '</thead>'));

        foreach ([
            'الحالة',
            'اسم الحساب',
            'العملة',
            'رقم الحساب',
            'نوع البنك / وسيلة الدفع',
            'IBAN',
            'اسم صاحب الحساب',
        ] as $heading) {
            $this->assertSame(
                1,
                substr_count($head, $heading),
                "The column [{$heading}] must be named exactly once, in the table head.",
            );
        }

        // `[\s>]` so the `<thead>` tag itself is not counted as a column.
        $this->assertSame(7, preg_match_all('/<th[\s>]/', $head), 'Seven columns, no more.');

        // One row per Account, seven cells each — no per-cell label stacked
        // above the value, which is what made the old card layout tall.
        $rows = $this->linkedAccountsRows($html);

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(7, substr_count($row, '<td'), 'Seven cells per row.');
            $this->assertSame(
                7,
                substr_count($row, 'fi-in-entry-label fi-hidden'),
                'Every cell must hide its own label; the table head names the column.',
            );
        }

        // Each row still carries ITS Account's values.
        $this->assertStringContainsString($current->account_code, $rows[0]);
        $this->assertStringContainsString($old->account_code, $rows[1]);
    }

    /**
     * The section spans the FULL content width rather than sitting in a column
     * of the upper layout — Filament renders a full-span schema column as
     * `--col-span-default: 1 / -1`.
     */
    public function test_the_linked_accounts_section_spans_the_full_content_width(): void
    {
        $this->actingAs($this->superAdmin());

        [$family] = $this->familyWithTwoAccounts();

        $html = Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])->html();

        $heading = strpos($html, 'الحسابات المرتبطة بالأسرة');

        $this->assertNotFalse($heading);

        // The grid column the section is rendered into is the last one opened
        // before its heading.
        $columnStyle = strrpos(substr($html, 0, $heading), '--col-span-default:');

        $this->assertNotFalse($columnStyle);

        $this->assertStringStartsWith(
            '--col-span-default: 1 / -1',
            substr($html, $columnStyle, 40),
            'The linked-accounts section must span the full content width.',
        );
    }

    /**
     * #8 — the lower sections read `ملاحظات` → `الحسابات المرتبطة بالأسرة` →
     * `مشاريع المؤاخاة`.
     *
     * Asserted on the real page response. The Projects relation manager is a
     * nested Livewire component whose heading is not in this document, so its
     * position is taken from the schema component Filament renders for it —
     * which is what places it after the whole infolist.
     */
    public function test_the_lower_sections_are_ordered_notes_then_accounts_then_projects(): void
    {
        $this->actingAs($this->superAdmin());

        [$family] = $this->familyWithTwoAccounts();

        $html = $this->get(MuwakhaFamilyResource::getUrl('view', ['record' => $family]))
            ->assertSuccessful()
            ->getContent();

        // The END of the notes section — `ملاحظات` is both its heading and its
        // one entry's label, so the last occurrence is the one that must still
        // precede the accounts table.
        $notes = strrpos($html, 'ملاحظات');
        $accounts = strpos($html, 'الحسابات المرتبطة بالأسرة');
        $projects = strpos($html, 'schema-component::content.'.ProjectsRelationManager::class);

        $this->assertNotFalse($notes);
        $this->assertNotFalse($accounts);
        $this->assertNotFalse($projects, 'The Projects relation manager must be rendered on the page.');

        $this->assertLessThan($accounts, $notes, '`ملاحظات` must come before the accounts table.');
        $this->assertLessThan($projects, $accounts, 'The accounts table must come before `مشاريع المؤاخاة`.');

        // Immediately before: no other section heading sits between them.
        $between = substr($html, $accounts, $projects - $accounts);

        foreach ([
            'بيانات الشهيد',
            'بيانات الوصي',
            'بيانات الحساب',
            'ملاحظات',
        ] as $earlierSection) {
            $this->assertStringNotContainsString(
                $earlierSection,
                $between,
                "The accounts table must sit immediately before `مشاريع المؤاخاة`, but [{$earlierSection}] is between them.",
            );
        }
    }

    /** #7 + #8 */
    public function test_a_soft_deleted_account_is_hidden_but_its_mapping_survives(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $old, $current] = $this->familyWithTwoAccounts();

        $old->delete();

        $this->assertTrue($old->fresh()->trashed());

        $page = Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()]);

        // #7 — excluded from the section completely.
        $page->assertDontSee($old->name);
        $page->assertSee($current->name);

        $this->assertSame(
            [$current->id],
            $family->fresh()->visibleAccountLinks()->pluck('account_id')->all(),
        );

        // #8 — the mapping row is NOT removed, and its holder name is intact.
        $this->assertSame(2, MuwakhaFamilyAccount::where('muwakha_family_id', $family->id)->count());
        $this->assertDatabaseHas('muwakha_family_accounts', [
            'muwakha_family_id' => $family->id,
            'account_id' => $old->id,
            'account_holder_name' => 'فاطمة أحمد',
        ]);

        // Nothing restored or reactivated it either.
        $this->assertTrue(Account::withTrashed()->findOrFail($old->id)->trashed());
    }

    /**
     * PART A #1 + #2 + #3 + #5 — a soft-deleted CURRENT Account is reported,
     * never described: `بيانات الحساب` shows only the Arabic warning, and none
     * of that Account's details appear anywhere on the page.
     */
    public function test_a_deleted_current_account_is_replaced_by_a_warning(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $old, $current] = $this->familyWithTwoAccounts();

        $current->delete();

        $page = Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()]);

        // #2 — the warning is shown.
        $page->assertSee(MuwakhaFamilyInfolist::DELETED_ACCOUNT_WARNING);

        // #1 — none of the deleted Account's details are rendered anywhere.
        $page->assertDontSee($current->name)
            ->assertDontSee($current->account_code)
            ->assertDontSee($current->iban)
            ->assertDontSee($current->currency->name)
            ->assertDontSee($current->bankType->name)
            // The account-holder information that belongs to it.
            ->assertDontSee('سعاد سالم');

        // #5 — and it stays out of the linked-accounts section too.
        $this->assertSame(
            [$old->id],
            $family->fresh()->visibleAccountLinks()->pluck('account_id')->all(),
        );

        // #3 — the ownership mapping is untouched, and nothing was restored.
        $this->assertSame(2, MuwakhaFamilyAccount::where('muwakha_family_id', $family->id)->count());
        $this->assertDatabaseHas('muwakha_family_accounts', [
            'muwakha_family_id' => $family->id,
            'account_id' => $current->id,
            'account_holder_name' => 'سعاد سالم',
        ]);
        $this->assertTrue(Account::withTrashed()->findOrFail($current->id)->trashed());
        $this->assertSame($current->id, $family->fresh()->account_id, 'account_id must not be repointed.');
    }

    /** PART A #4 — a live current Account still displays in full. */
    public function test_a_live_current_account_displays_normally(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, , $current] = $this->familyWithTwoAccounts();

        Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])
            ->assertDontSee(MuwakhaFamilyInfolist::DELETED_ACCOUNT_WARNING)
            ->assertSee($current->name)
            ->assertSee($current->account_code)
            ->assertSee($current->iban)
            ->assertSee($current->currency->name)
            ->assertSee($current->bankType->name)
            ->assertSee('سعاد سالم');
    }

    /**
     * A soft-deleted CURRENT Account is hidden from the section too — the
     * filter is applied before the badge, so nothing can be marked
     * `الحساب الحالي` while deleted.
     */
    public function test_a_soft_deleted_current_account_is_never_shown_as_current(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $old, $current] = $this->familyWithTwoAccounts();

        $current->delete();

        $links = $family->fresh()->visibleAccountLinks();

        $this->assertSame([$old->id], $links->pluck('account_id')->all());

        // No row can carry the badge, because the only candidate is filtered
        // out before the badge is ever computed. Asserted on the resolved
        // links rather than on the page text: since Part A the page shows
        // `الحساب الحالي محذوف`, which CONTAINS the badge phrase, so a
        // string-absence assertion here would test nothing.
        $this->assertNotContains(
            $family->fresh()->account_id,
            $links->pluck('account_id')->all(),
            'A deleted Account must never be listed, let alone badged current.',
        );

        // Since Part A the `بيانات الحساب` section shows the warning instead of
        // the details, so the deleted Account's name is absent page-wide.
        Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])
            ->assertSee(MuwakhaFamilyInfolist::DELETED_ACCOUNT_WARNING)
            ->assertDontSee($current->name);
    }

    /** #9 — the section is strictly read-only. */
    public function test_the_section_exposes_no_actions(): void
    {
        $source = file_get_contents(
            app_path('Filament/Resources/MuwakhaFamilies/Schemas/MuwakhaFamilyInfolist.php'),
        );

        foreach ([
            'Action', 'EditAction', 'DeleteAction', 'RestoreAction', 'ForceDeleteAction',
            'headerActions', 'footerActions', 'recordActions',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "The linked-accounts section must expose no [{$forbidden}].",
            );
        }

        $this->actingAs($this->superAdmin());

        [$family] = $this->familyWithTwoAccounts();

        Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])
            ->assertDontSee('استعادة')
            ->assertDontSee('حذف الحساب')
            ->assertDontSee('تعيين كحساب حالي');
    }

    /** #10 — the page's existing authorization is unchanged. */
    public function test_the_view_page_authorization_still_applies(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, , $current] = $this->familyWithTwoAccounts();

        // A user without muwakha_families.view cannot reach the page at all.
        $this->actingAs($this->userWith('projects.view_any'));
        $this->get(MuwakhaFamilyResource::getUrl('view', ['record' => $family]))->assertForbidden();

        // A user WITH it sees the page and the section.
        $this->actingAs($this->userWith('muwakha_families.view_any', 'muwakha_families.view'));

        $this->get(MuwakhaFamilyResource::getUrl('view', ['record' => $family]))
            ->assertSuccessful()
            ->assertSee('الحسابات المرتبطة بالأسرة', escape: false)
            ->assertSee($current->name, escape: false);
    }

    /**
     * The Account name links to the ordinary Accounts view page only for a
     * viewer authorized for it; otherwise it renders as plain text.
     */
    public function test_the_account_name_links_only_when_the_viewer_may_view_accounts(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, , $current] = $this->familyWithTwoAccounts();

        $accountUrl = AccountResource::getUrl('view', ['record' => $current]);

        Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])
            ->assertSee($accountUrl, escape: false);

        $this->actingAs($this->userWith('muwakha_families.view_any', 'muwakha_families.view'));

        Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])
            ->assertSee($current->name)
            ->assertDontSee($accountUrl, escape: false);
    }

    // ------------------------------------------------------------------ helpers

    /** The linked-accounts `<table>` markup, from its wrapper to `</table>`. */
    private function linkedAccountsTable(string $html): string
    {
        $start = strpos($html, 'fi-in-table-repeatable');

        $this->assertNotFalse($start, 'The linked-accounts table was not rendered.');

        $end = strpos($html, '</table>', $start);

        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * The table's BODY rows, one per visible mapped Account, in render order.
     *
     * @return array<int, string>
     */
    private function linkedAccountsRows(string $html): array
    {
        $table = $this->linkedAccountsTable($html);

        $body = substr($table, (int) strpos($table, '<tbody'));

        $rows = preg_split('/<tr>/', $body);

        // The first fragment is everything before the first row.
        return array_values(array_slice($rows === false ? [] : $rows, 1));
    }

    /**
     * A family that has moved from one Account to another, so the section has
     * both a current and a previous row with DIFFERENT holder names.
     *
     * @return array{0: MuwakhaFamily, 1: Account, 2: Account}
     */
    private function familyWithTwoAccounts(): array
    {
        $this->seedIndividualsAccountType();
        $this->seedShekelCurrency();
        $dollar = $this->seedDollarCurrency();
        $otherBank = $this->seedBankType('بنك القدس');

        $family = $this->families->create($this->familyData([
            'martyr_name' => 'أحمد',
            'account_code' => '111000',
            'iban' => 'PS11BANK000000000000111',
            'account_holder_name' => 'فاطمة أحمد',
        ]));

        $old = Account::findOrFail($family->account_id);

        $this->families->update($family->fresh(), $this->familyData([
            'martyr_name' => 'أحمد',
            'currency_id' => $dollar->id,
            'account_code' => '222000',
            'bank_type_id' => $otherBank->id,
            'iban' => 'PS22BANK000000000000222',
            'account_holder_name' => 'سعاد سالم',
        ]));

        $current = Account::findOrFail($family->fresh()->account_id);

        $this->assertNotSame($old->id, $current->id);

        return [$family->fresh(), $old, $current];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        $role = Role::create(['name' => 'muwakha-view-'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);

        return $user;
    }
}
