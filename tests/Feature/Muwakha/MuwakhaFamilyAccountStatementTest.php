<?php

namespace Tests\Feature\Muwakha;

use App\Filament\Resources\MuwakhaFamilies\Pages\MuwakhaFamilyAccountStatement;
use App\Filament\Resources\MuwakhaFamilies\Pages\ViewMuwakhaFamily;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditEvent;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\MuwakhaFamily;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementExcelExportService;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementService;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementWordExportService;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Muwakha\MuwakhaReference;
use App\Support\Muwakha\MuwakhaStatementScopeException;
use App\Support\Permissions\PermissionRegistry;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "كشف حساب الأسرة" — focused coverage for the Family Account Statement.
 *
 * The fixtures post REAL ledger rows (Transaction + balanced TransactionLine
 * pairs, and for the Project cases a genuine execution-payment business record
 * chain) rather than stubbing the report's own queries, so the assertions
 * exercise the same joins production runs.
 *
 * Every transaction is created as a BALANCED PAIR — the family's Account line
 * plus a counterparty line on an Account the family does not own — which also
 * makes "movements on unmapped Accounts are excluded" a property of every
 * single fixture rather than of one dedicated test.
 */
class MuwakhaFamilyAccountStatementTest extends MuwakhaTestCase
{
    private MuwakhaFamilyService $families;

    private MuwakhaFamilyAccountStatementService $statements;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSyncService::class)->sync();

        $this->families = app(MuwakhaFamilyService::class);
        $this->statements = app(MuwakhaFamilyAccountStatementService::class);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Family scope (1-6)
    // ═════════════════════════════════════════════════════════════════════

    /** #1 — the statement is reachable from the Family View page, and only from there. */
    public function test_the_statement_action_is_on_the_family_view_page_and_not_in_navigation(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();

        Livewire::test(ViewMuwakhaFamily::class, ['record' => $family->getKey()])
            ->assertActionVisible('accountStatement');

        $this->assertFalse(
            MuwakhaFamilyAccountStatement::shouldRegisterNavigation(),
            'The Family Account Statement must never appear in the sidebar.',
        );

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->assertOk()
            ->assertSee(MuwakhaFamilyAccountStatementService::TITLE);
    }

    /** #2 + #3 + #4 + #5 — current, previous and soft-deleted mapped Accounts all appear; unmapped ones never do. */
    public function test_it_includes_current_previous_and_soft_deleted_mapped_accounts_only(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $previous, $current] = $this->familyWithTwoShekelAccounts();

        $deleted = $this->mapExtraAccount($family, '333000', 'شيكل');
        $deleted->delete();

        $this->postPair($current, 100.00, '2026-03-01 10:00', number: 'TX-CURRENT');
        $this->postPair($previous, 200.00, '2026-02-01 10:00', number: 'TX-PREVIOUS');
        $this->postPair($deleted, 300.00, '2026-01-01 10:00', number: 'TX-DELETED');

        $report = $this->statements->generate($family->fresh());
        $numbers = $this->numbersIn($report);

        // #2 + #3 + #4
        $this->assertContains('TX-CURRENT', $numbers);
        $this->assertContains('TX-PREVIOUS', $numbers);
        $this->assertContains('TX-DELETED', $numbers);

        // #5 — every fixture's counterparty line sits on an unmapped Account.
        $this->assertNotContains('TX-CURRENT/counter', $numbers);
        $this->assertCount(3, $numbers);
    }

    /** #5 — an Account belonging to ANOTHER family never enters this family's statement. */
    public function test_another_familys_account_movements_are_excluded(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $other = $this->makeFamily(['martyr_name' => 'خالد', 'account_code' => '999000', 'martyr_national_id' => '0411111111']);

        $this->postPair($this->accountOf($family), 100.00, '2026-03-01 10:00', number: 'TX-MINE');
        $this->postPair($this->accountOf($other), 100.00, '2026-03-02 10:00', number: 'TX-THEIRS');

        $this->assertSame(['TX-MINE'], $this->numbersIn($this->statements->generate($family->fresh())));
    }

    /** #6 — a forged Account filter belonging to another family is REJECTED, not silently narrowed. */
    public function test_a_forged_account_filter_from_another_family_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $other = $this->makeFamily(['martyr_name' => 'خالد', 'account_code' => '999000', 'martyr_national_id' => '0411111111']);

        $this->expectException(MuwakhaStatementScopeException::class);

        $this->statements->generate($family->fresh(), ['account_id' => $other->account_id]);
    }

    /**
     * #6 — the page rejects a forged Account on TWO independent layers, and the
     * distinction matters.
     *
     * Layer 1 is Filament's own `in:` rule, derived from the Select's options —
     * which for this page are the family's mapped Accounts. It fires first and
     * loads nothing.
     *
     * Layer 2 is the service's ownership check, which is the AUTHORITATIVE one:
     * it does not depend on what a Select happened to offer when the page was
     * rendered, so it still rejects if the option list and the submitted value
     * ever disagree (an Account unmapped between render and submit). That branch
     * is asserted below, on its own.
     */
    public function test_the_page_rejects_a_forged_account_filter(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $other = $this->makeFamily(['martyr_name' => 'خالد', 'account_code' => '999000', 'martyr_national_id' => '0411111111']);

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->set('data.account_id', $other->account_id)
            ->call('showReport')
            ->assertHasErrors('data.account_id')
            ->assertSet('hasSubmitted', false)
            ->assertSet('report', null);
    }

    /** #6 — the authoritative layer: an out-of-scope filter becomes a 403, never a report. */
    public function test_the_pages_scope_check_turns_an_out_of_scope_filter_into_403(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $other = $this->makeFamily(['martyr_name' => 'خالد', 'account_code' => '999000', 'martyr_national_id' => '0411111111']);

        $page = new class extends MuwakhaFamilyAccountStatement
        {
            /** @param array<string, mixed> $state */
            public function callBuildReport(array $state): array
            {
                return $this->buildReport($state);
            }
        };

        $page->record = $family;

        try {
            $page->callBuildReport(['account_id' => $other->account_id]);
            $this->fail('An out-of-scope Account filter must abort, not return a report.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Account filter (7-10)
    // ═════════════════════════════════════════════════════════════════════

    /** #7 + #9 — every mapped Account is offered, deleted ones marked `— محذوف`. */
    public function test_the_account_filter_lists_every_mapped_account_and_marks_deleted_ones(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $previous, $current] = $this->familyWithTwoShekelAccounts();
        $deleted = $this->mapExtraAccount($family, '333000', 'شيكل');
        $deleted->delete();

        $options = $this->statements->accountFilterOptions($family->fresh());

        $this->assertCount(3, $options);
        $this->assertSame($current->name, $options[$current->id]);
        $this->assertSame($previous->name, $options[$previous->id]);
        // #9 — the deleted Account is offered, and unmistakably labelled.
        $this->assertSame($deleted->name.' — محذوف', $options[$deleted->id]);
        $this->assertStringContainsString('محذوف', $options[$deleted->id]);
    }

    /** #7 vs #8 — "كل الحسابات" spans every mapped Account; one Account narrows to it alone. */
    public function test_all_accounts_versus_a_single_account_filter(): void
    {
        $this->actingAs($this->superAdmin());

        [$family, $previous, $current] = $this->familyWithTwoShekelAccounts();

        $this->postPair($current, 100.00, '2026-03-01 10:00', number: 'TX-CURRENT');
        $this->postPair($previous, 200.00, '2026-02-01 10:00', number: 'TX-PREVIOUS');

        // #7
        $all = $this->statements->generate($family->fresh());
        $this->assertEqualsCanonicalizing(['TX-CURRENT', 'TX-PREVIOUS'], $this->numbersIn($all));
        $this->assertCount(2, $all['accounts']);

        // #8
        $one = $this->statements->generate($family->fresh(), ['account_id' => $previous->id]);
        $this->assertSame(['TX-PREVIOUS'], $this->numbersIn($one));
        $this->assertCount(1, $one['accounts'], 'A single-Account report lists only that Account.');
        $this->assertSame($previous->id, $one['accounts'][0]['id']);
    }

    /** #10 — selecting a deleted historical Account still returns its movements. */
    public function test_selecting_a_deleted_historical_account_returns_its_movements(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $deleted = $this->mapExtraAccount($family, '333000', 'شيكل');
        $this->postPair($deleted, 300.00, '2026-01-01 10:00', number: 'TX-DELETED');
        $deleted->delete();

        $report = $this->statements->generate($family->fresh(), ['account_id' => $deleted->id]);

        $this->assertSame(['TX-DELETED'], $this->numbersIn($report));
        $this->assertTrue($report['accounts'][0]['is_deleted']);
        $this->assertStringContainsString('محذوف', $report['currency_groups'][0]['rows'][0]['account_label']);

        // The report reads history; it never repairs it.
        $this->assertNotNull(Account::withTrashed()->find($deleted->id)->deleted_at);
        $this->assertNull(Account::find($deleted->id));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Dates (11-15)
    // ═════════════════════════════════════════════════════════════════════

    /** #11 + #12 + #13 + #14 */
    public function test_the_optional_date_filters(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);

        $this->postPair($account, 10.00, '2026-01-15 10:00', number: 'TX-JAN');
        $this->postPair($account, 20.00, '2026-02-15 10:00', number: 'TX-FEB');
        $this->postPair($account, 30.00, '2026-03-15 10:00', number: 'TX-MAR');

        $family = $family->fresh();

        // #11 — no dates at all = the family's whole history.
        $this->assertEqualsCanonicalizing(
            ['TX-JAN', 'TX-FEB', 'TX-MAR'],
            $this->numbersIn($this->statements->generate($family)),
        );

        // #12 — lower bound only.
        $this->assertEqualsCanonicalizing(
            ['TX-FEB', 'TX-MAR'],
            $this->numbersIn($this->statements->generate($family, ['date_from' => '2026-02-01'])),
        );

        // #13 — upper bound only, inclusive of the whole end day.
        $this->assertEqualsCanonicalizing(
            ['TX-JAN', 'TX-FEB'],
            $this->numbersIn($this->statements->generate($family, ['date_to' => '2026-02-15'])),
        );

        // #14 — both bounds.
        $this->assertSame(
            ['TX-FEB'],
            $this->numbersIn($this->statements->generate($family, [
                'date_from' => '2026-02-01',
                'date_to' => '2026-02-28',
            ])),
        );
    }

    /** #15 — a reversed range is rejected by the service... */
    public function test_a_reversed_date_range_is_rejected_by_the_service(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();

        $this->expectException(MuwakhaStatementScopeException::class);

        $this->statements->generate($family, ['date_from' => '2026-03-01', 'date_to' => '2026-02-01']);
    }

    /** #15 — ...and by the page's own form validation, which loads nothing. */
    public function test_a_reversed_date_range_is_rejected_by_the_page(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->set('data.date_from', '2026-03-01')
            ->set('data.date_to', '2026-02-01')
            ->call('showReport')
            ->assertHasErrors('data.date_from')
            ->assertSet('hasSubmitted', false);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Transaction type (16-17)
    // ═════════════════════════════════════════════════════════════════════

    /** #16 + #17 */
    public function test_the_transaction_type_filter(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);

        $payment = $this->transactionType('دفعة تنفيذ');
        $expense = $this->transactionType('مصروف عام');

        $this->postPair($account, 10.00, '2026-01-15 10:00', number: 'TX-PAY', type: $payment);
        $this->postPair($account, 20.00, '2026-02-15 10:00', number: 'TX-EXP', type: $expense);

        $family = $family->fresh();

        // #16 — no type filter includes every type.
        $this->assertEqualsCanonicalizing(['TX-PAY', 'TX-EXP'], $this->numbersIn($this->statements->generate($family)));

        // #17 — filtering uses transactions.transaction_type_id, the authoritative field.
        $this->assertSame(
            ['TX-PAY'],
            $this->numbersIn($this->statements->generate($family, ['transaction_type_id' => $payment->id])),
        );

        // The option list is the types that actually occur on this family's accounts.
        $this->assertEqualsCanonicalizing(
            ['دفعة تنفيذ', 'مصروف عام'],
            array_values($this->statements->transactionTypeFilterOptions($family)),
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Project (18-21) — the authoritative structured relationship only
    // ═════════════════════════════════════════════════════════════════════

    /**
     * #18 + #19 + #21 — the whole point of this test.
     *
     * The family is LINKED to `$linkedOnly` through muwakha_family_projects but
     * has no movement belonging to it, and has a real execution payment
     * belonging to `$paidProject`. Project filtering must follow the money, not
     * the link.
     */
    public function test_project_filtering_follows_the_execution_payment_record_not_the_family_link(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);

        $paidProject = $this->makeMuwakhaProject('مؤاخاة كاف 2026');
        $linkedOnly = $this->makeMuwakhaProject('مؤاخاة لام 2026');

        // The family is linked to BOTH projects — this link is deliberately
        // irrelevant to the report.
        $family->projects()->attach([$paidProject->id => ['card_code' => 'G 10'], $linkedOnly->id => ['card_code' => 'G 20']]);

        $this->executionPayment($account, $paidProject, 500.00, '2026-03-01 10:00', 'TX-PAID');
        // A movement with no project linkage at all.
        $this->postPair($account, 40.00, '2026-04-01 10:00', number: 'TX-NOPROJECT');

        $family = $family->fresh();

        // #18 — "كل المشاريع" is the full valid scope: linked AND unlinked movements.
        $this->assertEqualsCanonicalizing(
            ['TX-PAID', 'TX-NOPROJECT'],
            $this->numbersIn($this->statements->generate($family)),
        );

        // #19 — filtering by the paid project resolves through
        // project_cost_budgets_payments -> project_cost_budgets -> projects_costs.
        $this->assertSame(
            ['TX-PAID'],
            $this->numbersIn($this->statements->generate($family, ['project_id' => $paidProject->id])),
        );

        // #21 — being LINKED to a project never puts it in the report's scope.
        $options = $this->statements->projectFilterOptions($family);
        $this->assertSame([$paidProject->id => $paidProject->name], $options);
        $this->assertArrayNotHasKey($linkedOnly->id, $options);
    }

    /** #20 + #21 — an unrelated or merely-linked Project is rejected, never answered with a plausible-looking report. */
    public function test_an_out_of_scope_project_filter_is_rejected(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $unrelated = $this->makeUnrelatedProject();

        $this->expectException(MuwakhaStatementScopeException::class);

        $this->statements->generate($family, ['project_id' => $unrelated->id]);
    }

    /**
     * #19 — the line-level path (transaction_lines.project_cost_id) is honoured
     * too, so a movement posted by the project-cost workflows is still
     * filterable. Both paths are live; neither replaces the other.
     */
    public function test_project_filtering_also_honours_the_line_level_project_cost_path(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);

        $project = $this->makeMuwakhaProject('مؤاخاة ميم 2026');
        $cost = $this->projectCost($project);

        $this->postPair($account, 60.00, '2026-05-01 10:00', number: 'TX-LINELEVEL', projectCostId: $cost->id);
        $this->postPair($account, 70.00, '2026-05-02 10:00', number: 'TX-UNLINKED');

        $family = $family->fresh();

        $this->assertSame(
            ['TX-LINELEVEL'],
            $this->numbersIn($this->statements->generate($family, ['project_id' => $project->id])),
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Currency grouping (22-27)
    // ═════════════════════════════════════════════════════════════════════

    /** #22 + #23 + #24 + #25 + #26 + #27 */
    public function test_currency_grouping_totals_and_the_absence_of_a_grand_total(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $shekelOne = $this->accountOf($family);
        $shekelTwo = $this->mapExtraAccount($family, '444000', 'شيكل');
        $dollar = $this->mapExtraAccount($family, '555000', 'دولار');

        $this->postPair($shekelOne, 100.00, '2026-03-01 10:00', number: 'ILS-A');
        $this->postPair($shekelTwo, 250.00, '2026-03-02 10:00', number: 'ILS-B');
        $this->postPair($shekelTwo, 40.00, '2026-03-03 10:00', number: 'ILS-C', familySideDebit: false);
        $this->postPair($dollar, 900.00, '2026-03-04 10:00', number: 'USD-A');
        $this->postPair($dollar, 200.00, '2026-03-05 10:00', number: 'USD-B', familySideDebit: false);

        $groups = $this->statements->generate($family->fresh())['currency_groups'];

        // #22 — exactly two sections; ILS and USD never share one.
        $this->assertCount(2, $groups);
        $byCurrency = collect($groups)->keyBy('currency_code');
        $this->assertTrue($byCurrency->has('ILS'));
        $this->assertTrue($byCurrency->has('USD'));

        // #23 — the two shekel Accounts are MERGED into one section.
        $ils = $byCurrency['ILS'];
        $this->assertCount(3, $ils['rows']);
        $this->assertEqualsCanonicalizing(['ILS-A', 'ILS-B', 'ILS-C'], array_column($ils['rows'], 'transaction_number'));

        // #24 — each row names the Account it actually belongs to.
        $labelsByNumber = array_column($ils['rows'], 'account_label', 'transaction_number');
        $this->assertSame($shekelOne->name, $labelsByNumber['ILS-A']);
        $this->assertSame($shekelTwo->name, $labelsByNumber['ILS-B']);

        // #25 + #26 — per-currency totals, computed only from that currency.
        $this->assertEqualsWithDelta(350.00, $ils['total_debit'], 0.001);
        $this->assertEqualsWithDelta(40.00, $ils['total_credit'], 0.001);

        $usd = $byCurrency['USD'];
        $this->assertEqualsWithDelta(900.00, $usd['total_debit'], 0.001);
        $this->assertEqualsWithDelta(200.00, $usd['total_credit'], 0.001);

        // #27 — no blended figure exists anywhere in the result.
        $report = $this->statements->generate($family->fresh());
        $this->assertArrayNotHasKey('total_debit', $report);
        $this->assertArrayNotHasKey('total_credit', $report);
        $this->assertArrayNotHasKey('grand_total', $report);
        $this->assertNotContains(1250.00, array_column($groups, 'total_debit'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Ordering (28-29)
    // ═════════════════════════════════════════════════════════════════════

    /** #28 + #29 */
    public function test_movements_are_ordered_newest_first_with_a_deterministic_tie_break(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);

        $this->postPair($account, 10.00, '2026-01-01 10:00', number: 'TX-OLD');
        // Two movements sharing the exact same instant.
        $tieA = $this->postPair($account, 20.00, '2026-02-01 10:00', number: 'TX-TIE-A');
        $tieB = $this->postPair($account, 30.00, '2026-02-01 10:00', number: 'TX-TIE-B');
        $this->postPair($account, 40.00, '2026-03-01 10:00', number: 'TX-NEW');

        $numbers = $this->numbersIn($this->statements->generate($family->fresh()));

        // #28 — newest to oldest.
        $this->assertSame('TX-NEW', $numbers[0]);
        $this->assertSame('TX-OLD', $numbers[3]);

        // #29 — the tie breaks on transaction id descending, so the later-created
        // one comes first, deterministically and repeatably.
        $this->assertGreaterThan($tieA->id, $tieB->id);
        $this->assertSame(['TX-NEW', 'TX-TIE-B', 'TX-TIE-A', 'TX-OLD'], $numbers);

        $this->assertSame($numbers, $this->numbersIn($this->statements->generate($family->fresh())));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Balance exclusions (30-32) and detail columns (33-39)
    // ═════════════════════════════════════════════════════════════════════

    /** #30 + #31 + #32 — no balance concept exists in the result or on the screen. */
    public function test_no_opening_running_or_cumulative_balance_exists(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $this->postPair($this->accountOf($family), 100.00, '2026-03-01 10:00', number: 'TX-1');

        $report = $this->statements->generate($family->fresh());

        foreach (['opening_balance', 'closing_balance', 'previous_balance', 'running_balance', 'balance'] as $key) {
            $this->assertArrayNotHasKey($key, $report);
            $this->assertArrayNotHasKey($key, $report['currency_groups'][0]);
            $this->assertArrayNotHasKey($key, $report['currency_groups'][0]['rows'][0]);
        }

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->call('showReport')
            ->assertDontSee('الرصيد الافتتاحي')
            ->assertDontSee('الرصيد الختامي')
            ->assertDontSee('الرصيد المتراكم');
    }

    /** #33 - #39 — every approved business column is present, and nothing is invented. */
    public function test_every_approved_transaction_column_is_rendered(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);
        $type = $this->transactionType('دفعة تنفيذ');

        $this->postPair(
            $account,
            125.50,
            '2026-03-01 10:00',
            number: 'TX-COLUMNS',
            type: $type,
            description: 'صرف دفعة',
            notes: 'حساب المستفيد',
        );

        $row = $this->statements->generate($family->fresh())['currency_groups'][0]['rows'][0];

        $this->assertNotNull($row['date']);                                     // #33
        $this->assertSame('TX-COLUMNS', $row['transaction_number']);            // #34
        $this->assertSame('دفعة تنفيذ', $row['type_name']);                     // #35
        $this->assertSame('صرف دفعة — حساب المستفيد', $row['description']);     // #36
        $this->assertSame($account->name, $row['account_label']);               // #37
        $this->assertEqualsWithDelta(125.50, $row['debit'], 0.001);             // #38
        $this->assertEqualsWithDelta(0.0, $row['credit'], 0.001);               // #39

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->call('showReport')
            ->assertSee('التاريخ')
            ->assertSee('رقم المعاملة')
            ->assertSee('نوع الحركة')
            ->assertSee('البيان / الملاحظات')
            ->assertSee('الحساب')
            ->assertSee('مدين')
            ->assertSee('دائن')
            ->assertSee('TX-COLUMNS');
    }

    /**
     * #38 + #39 — the credit side is read from credit_base, never derived from
     * a transaction-type name or a sign convention this report invented.
     */
    public function test_debit_and_credit_come_from_the_ledger_columns_verbatim(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $this->postPair($this->accountOf($family), 77.25, '2026-03-01 10:00', number: 'TX-CREDIT', familySideDebit: false);

        $row = $this->statements->generate($family->fresh())['currency_groups'][0]['rows'][0];

        $this->assertEqualsWithDelta(0.0, $row['debit'], 0.001);
        $this->assertEqualsWithDelta(77.25, $row['credit'], 0.001);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Empty state (24 in §24)
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_empty_state_still_shows_the_family_summary_and_filters(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->set('data.date_from', '2030-01-01')
            ->call('showReport')
            ->assertSee(MuwakhaFamilyAccountStatementService::EMPTY_NOTICE)
            ->assertSee('بيانات الأسرة')
            ->assertSee($family->martyr_name)
            ->assertSee('الحسابات المشمولة')
            ->assertSee($this->accountOf($family)->name);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Authorization (40-45)
    // ═════════════════════════════════════════════════════════════════════

    /** #40 + #41 + #42 — every role approved for full Muwakha access can view AND export. */
    public function test_full_access_roles_can_view_and_export(): void
    {
        $family = $this->makeFamily();

        foreach ([PermissionRegistry::ADMIN, PermissionRegistry::PROJECT_MANAGER] as $roleName) {
            $this->actingAs($this->userWithRole($roleName));

            Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
                ->assertOk()
                ->assertActionVisible('exportExcel')
                ->assertActionVisible('exportWord');
        }

        // Super Admin passes through the Gate::before bypass.
        $this->actingAs($this->superAdmin());
        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->assertOk()
            ->assertActionVisible('exportExcel');
    }

    /** #43 + #44 — Viewer may view the statement but must not export it. */
    public function test_viewer_can_view_but_cannot_export(): void
    {
        $family = $this->makeFamily();

        $this->actingAs($this->userWithRole(PermissionRegistry::VIEWER));

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->assertOk()
            ->assertActionHidden('exportExcel')
            ->assertActionHidden('exportWord');

        // The hidden button is not the guard: calling the method directly —
        // exactly what a crafted Livewire request does — is still a 403.
        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->call('exportExcel')
            ->assertForbidden();

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->call('exportWord')
            ->assertForbidden();
    }

    /** #45 — Accountant holds no Muwakha permission at all and cannot open the page. */
    public function test_accountant_cannot_access_the_statement(): void
    {
        $family = $this->makeFamily();

        $this->actingAs($this->userWithRole(PermissionRegistry::ACCOUNTANT));

        $this->assertFalse(
            MuwakhaFamilyAccountStatement::canAccess(['record' => $family]),
            'Accountant has no Muwakha permissions and must not reach the statement.',
        );

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->assertForbidden();
    }

    /** The approved permission matrix is REUSED — no new permission was registered. */
    public function test_no_dedicated_statement_permission_was_added(): void
    {
        $names = PermissionRegistry::names();

        // Nothing Muwakha-and-statement shaped was registered; the page reuses
        // the two permissions the resource already owns.
        $statementPermissions = array_values(array_filter(
            $names,
            static fn (string $name): bool => str_contains($name, 'muwakha') && str_contains($name, 'statement'),
        ));

        $this->assertSame([], $statementPermissions);
        $this->assertContains('muwakha_families.view', $names);
        $this->assertContains('muwakha_families.export', $names);

        $this->assertSame('muwakha_families.view', MuwakhaFamilyAccountStatement::VIEW_PERMISSION);
        $this->assertSame('muwakha_families.export', MuwakhaFamilyAccountStatement::EXPORT_PERMISSION);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Excel (46-54)
    // ═════════════════════════════════════════════════════════════════════

    /** #46 + #47 + #52 + #53 + #54 */
    public function test_the_excel_export_is_rtl_currency_separated_and_text_safe(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily(['martyr_national_id' => '0012345678']);
        $shekel = $this->accountOf($family);
        $dollar = $this->mapExtraAccount($family, '0555000', 'دولار');

        $this->postPair($shekel, 100.00, '2026-03-01 10:00', number: 'ILS-A');
        $this->postPair($dollar, 900.00, '2026-03-04 10:00', number: 'USD-A');

        $report = $this->statements->generate($family->fresh());
        $sheet = $this->renderExcel($report);

        // #47
        $this->assertTrue($sheet->getRightToLeft());

        $text = $this->sheetText($sheet);

        // #52 — both currency sections exist, separately.
        $this->assertStringContainsString('العملة: شيكل (ILS)', $text);
        $this->assertStringContainsString('العملة: دولار (USD)', $text);
        $this->assertStringContainsString('ILS-A', $text);
        $this->assertStringContainsString('USD-A', $text);

        // #54 — the totals in the file are the screen's per-currency totals,
        // stated twice (once per currency section) and never summed together.
        $this->assertSame(2, substr_count($text, 'إجمالي المدين'));
        $this->assertSame(2, substr_count($text, 'إجمالي الدائن'));
        $this->assertStringNotContainsString('الإجمالي العام', $text);
        $this->assertStringNotContainsString('الرصيد الافتتاحي', $text);

        // #53 — leading zeros survive on identifiers and account numbers.
        $this->assertStringContainsString('0012345678', $text);
        $this->assertStringContainsString('0555000', $text);
    }

    /** #48 + #49 + #50 + #51 — the file renders the applied snapshot, so every filter carries through. */
    public function test_the_excel_export_respects_every_applied_filter(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);
        $other = $this->mapExtraAccount($family, '444000', 'شيكل');
        $payment = $this->transactionType('دفعة تنفيذ');
        $expense = $this->transactionType('مصروف عام');
        $project = $this->makeMuwakhaProject('مؤاخاة كاف 2026');

        $this->executionPayment($account, $project, 500.00, '2026-03-01 10:00', 'TX-KEEP', $payment);
        $this->postPair($other, 20.00, '2026-06-01 10:00', number: 'TX-OTHERACCOUNT', type: $expense);
        $this->postPair($account, 30.00, '2026-06-02 10:00', number: 'TX-LATE', type: $expense);

        $report = $this->statements->generate($family->fresh(), [
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
            'account_id' => $account->id,
            'transaction_type_id' => $payment->id,
            'project_id' => $project->id,
        ]);

        $text = $this->sheetText($this->renderExcel($report));

        $this->assertStringContainsString('TX-KEEP', $text);
        $this->assertStringNotContainsString('TX-OTHERACCOUNT', $text);  // #48
        $this->assertStringNotContainsString('TX-LATE', $text);          // #49 + #50
        $this->assertStringContainsString($project->name, $text);        // #51 — the applied project is stated
        $this->assertStringContainsString('الفلاتر المطبقة', $text);
    }

    /** Exporting before "عرض" streams nothing and warns. */
    public function test_the_export_requires_the_submission_gate(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();

        $page = Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()]);

        $this->assertNull($page->instance()->exportExcel());
        $this->assertNull($page->instance()->exportWord());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Word (55-59)
    // ═════════════════════════════════════════════════════════════════════

    /** #55 + #56 + #57 + #58 + #59 */
    public function test_the_word_export_is_rtl_and_matches_the_screen(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $shekel = $this->accountOf($family);
        $dollar = $this->mapExtraAccount($family, '555000', 'دولار');

        $this->postPair($shekel, 100.00, '2026-03-01 10:00', number: 'ILS-A');
        $this->postPair($shekel, 40.00, '2026-03-02 10:00', number: 'ILS-B', familySideDebit: false);
        $this->postPair($dollar, 900.00, '2026-03-04 10:00', number: 'USD-A');

        $report = $this->statements->generate($family->fresh());

        $response = app(MuwakhaFamilyAccountStatementWordExportService::class)->stream($report);
        $this->assertInstanceOf(StreamedResponse::class, $response);   // #55

        $xml = $this->wordDocumentXml($response);

        // #56 — PhpWord's default-RTL setting reaches the document body.
        $this->assertStringContainsString('<w:bidi', $xml);

        $text = $this->stripTags($xml);

        // #58 — the same two currency sections as the screen.
        $this->assertStringContainsString('العملة: شيكل (ILS)', $text);
        $this->assertStringContainsString('العملة: دولار (USD)', $text);

        // #59 — per-currency totals, matching the report snapshot exactly.
        $this->assertSame(2, substr_count($text, 'إجمالي المدين'));
        $this->assertStringContainsString('100.00', $text);
        $this->assertStringContainsString('40.00', $text);
        $this->assertStringContainsString('900.00', $text);
        $this->assertStringNotContainsString('الرصيد الافتتاحي', $text);
        $this->assertStringNotContainsString('الرصيد المتراكم', $text);
    }

    /** #57 — the Word file is built from the same snapshot, so it cannot exceed the applied filters. */
    public function test_the_word_export_respects_the_applied_filters(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $account = $this->accountOf($family);
        $other = $this->mapExtraAccount($family, '444000', 'شيكل');

        $this->postPair($account, 10.00, '2026-01-15 10:00', number: 'TX-KEEP');
        $this->postPair($other, 20.00, '2026-01-16 10:00', number: 'TX-DROP');

        $report = $this->statements->generate($family->fresh(), ['account_id' => $account->id]);

        $text = $this->stripTags($this->wordDocumentXml(
            app(MuwakhaFamilyAccountStatementWordExportService::class)->stream($report),
        ));

        $this->assertStringContainsString('TX-KEEP', $text);
        $this->assertStringNotContainsString('TX-DROP', $text);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Audit (60-62)
    // ═════════════════════════════════════════════════════════════════════

    /** #60 + #61 + #62 */
    public function test_both_exports_are_audited_without_leaking_private_identifiers(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();
        $this->postPair($this->accountOf($family), 100.00, '2026-03-01 10:00', number: 'TX-1');

        $page = Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()]);
        $page->call('showReport');

        $page->instance()->exportExcel();   // #60
        $page->instance()->exportWord();    // #61

        $events = AuditEvent::where('event_category', ReportExportAuditRecorder::EVENT_CATEGORY)
            ->where('subject_type', ReportExportSubject::MuwakhaFamilyAccountStatement->value)
            ->get();

        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(
            ['xlsx', 'docx'],
            $events->map(fn (AuditEvent $event) => $event->new_values['format'])->all(),
        );

        foreach ($events as $event) {
            $this->assertSame(ReportExportAuditRecorder::EVENT_ACTION, $event->event_action);
            $this->assertSame('كشف حساب الأسرة', $event->subject_label);
            $this->assertSame((int) $family->getKey(), $event->new_values['muwakha_family_id']);
            $this->assertSame(1, $event->new_values['rows_count']);

            // #62 — the redacted family identifiers never appear in the payload.
            $payload = json_encode($event->new_values, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString((string) $family->martyr_national_id, $payload);
            $this->assertStringNotContainsString((string) $family->guardian_national_id, $payload);
            $this->assertStringNotContainsString((string) $family->guardian_phone, $payload);
        }
    }

    /** A blocked export (no "عرض") must not be recorded as an export at all. */
    public function test_a_blocked_export_writes_no_audit_event(): void
    {
        $this->actingAs($this->superAdmin());

        $family = $this->makeFamily();

        Livewire::test(MuwakhaFamilyAccountStatement::class, ['record' => $family->getKey()])
            ->instance()
            ->exportExcel();

        $this->assertSame(0, AuditEvent::where('subject_type', ReportExportSubject::MuwakhaFamilyAccountStatement->value)->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Fixtures
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFamily(array $overrides = []): MuwakhaFamily
    {
        // Idempotent on purpose: several tests build more than one family, and
        // MuwakhaReference::accountTypeId() fails closed on a DUPLICATE `أفراد`
        // type — which is exactly the guarantee a non-idempotent seeder would
        // break.
        $this->individualsAccountType();
        $this->seedShekelCurrency();
        $this->seedDollarCurrency();
        $this->seedBankType();

        return $this->families->create($this->familyData($overrides));
    }

    /** The `أفراد` account type, created at most once per test. */
    private function individualsAccountType(): AccountType
    {
        return AccountType::firstOrCreate(['name' => MuwakhaReference::ACCOUNT_TYPE_NAME]);
    }

    /**
     * A family that has moved payment destination once, so it owns a previous
     * shekel Account and a current shekel Account.
     *
     * @return array{0: MuwakhaFamily, 1: Account, 2: Account}
     */
    private function familyWithTwoShekelAccounts(): array
    {
        $family = $this->makeFamily(['account_code' => '111000']);
        $previous = $this->accountOf($family);

        $this->families->update($family->fresh(), $this->familyData(['account_code' => '222000']));
        $current = $this->accountOf($family->fresh());

        $this->assertNotSame($previous->id, $current->id);

        return [$family->fresh(), $previous, $current];
    }

    /**
     * Maps ONE more Account to the family through the real ownership table —
     * the only scope this report is allowed to read.
     */
    private function mapExtraAccount(MuwakhaFamily $family, string $accountCode, string $currencyName): Account
    {
        $currency = Currency::where('name', $currencyName)->firstOrFail();

        $account = Account::create([
            'account_code' => $accountCode,
            'name' => 'أسرة الشهيد '.$family->martyr_name.' - '.$currencyName.' - ('.$accountCode.')',
            'account_type_id' => $this->individualsAccountType()->id,
            'bank_type_id' => $this->seedBankType()->id,
            'currency_id' => $currency->id,
            'is_active' => true,
        ]);

        $family->familyAccounts()->create([
            'account_id' => $account->id,
            'account_holder_name' => $family->account_holder_name ?: 'فاطمة أحمد محمد',
        ]);

        return $account;
    }

    /**
     * Posts ONE balanced transaction: the family's Account on one side and a
     * counterparty Account the family does NOT own on the other.
     *
     * The counterparty line is what proves, in every fixture, that the report
     * reads `muwakha_family_accounts` and not "every line of a transaction that
     * touched the family".
     */
    private function postPair(
        Account $familyAccount,
        float $amount,
        string $time,
        string $number,
        ?TransactionType $type = null,
        ?string $description = null,
        ?string $notes = null,
        bool $familySideDebit = true,
        ?int $projectCostId = null,
    ): Transaction {
        $transaction = Transaction::create([
            'fiscal_year_id' => $this->fiscalYear()->id,
            'transaction_type_id' => ($type ?? $this->transactionType('دفعة تنفيذ'))->id,
            'transaction_number' => $number,
            'transaction_time' => $time,
            'description' => $description,
        ]);

        $counterparty = $this->counterpartyAccount((int) $familyAccount->currency_id);

        TransactionLine::create([
            'transaction_id' => $transaction->id,
            'account_id' => $familyAccount->id,
            'project_cost_id' => $projectCostId,
            'currency_id' => $familyAccount->currency_id,
            'amount_currency' => $amount,
            'fx_rate' => 1,
            'debit_base' => $familySideDebit ? $amount : 0,
            'credit_base' => $familySideDebit ? 0 : $amount,
            'notes' => $notes,
        ]);

        TransactionLine::create([
            'transaction_id' => $transaction->id,
            'account_id' => $counterparty->id,
            'project_cost_id' => $projectCostId,
            'currency_id' => $familyAccount->currency_id,
            'amount_currency' => $amount,
            'fx_rate' => 1,
            'debit_base' => $familySideDebit ? 0 : $amount,
            'credit_base' => $familySideDebit ? $amount : 0,
            'notes' => $number.'/counter',
        ]);

        return $transaction;
    }

    /**
     * A REAL execution payment: two lines whose `project_cost_id` is NULL —
     * exactly as CreateExecutionPayment::buildLines() writes them — plus the
     * `project_cost_budgets_payments` business record that is the only
     * structured link back to the Project.
     */
    private function executionPayment(
        Account $beneficiary,
        Project $project,
        float $amount,
        string $time,
        string $number,
        ?TransactionType $type = null,
    ): Transaction {
        $transaction = $this->postPair($beneficiary, $amount, $time, $number, $type);

        // The lines genuinely carry no project_cost_id.
        $this->assertNull($transaction->lines()->first()->project_cost_id);

        $cost = $this->projectCost($project);

        $budget = ProjectCostBudget::create([
            'project_cost_id' => $cost->id,
            'original_amount' => $amount,
            'amount_after_deductions' => $amount,
            'source_currency_id' => $beneficiary->currency_id,
            'disbursement_currency_id' => $beneficiary->currency_id,
            'final_amount' => $amount,
        ]);

        ProjectCostBudgetsPayment::create([
            'project_cost_budget_id' => $budget->id,
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'currency_id' => $beneficiary->currency_id,
            'date' => substr($time, 0, 10),
        ]);

        return $transaction;
    }

    private function projectCost(Project $project): ProjectCost
    {
        return ProjectCost::create([
            'project_id' => $project->id,
            'account_type_id' => $this->individualsAccountType()->id,
            'amount' => 10000,
            'currency_id' => $this->seedShekelCurrency()->id,
        ]);
    }

    /** An ordinary OMS Account that is NOT mapped to any Muwakha family. */
    private function counterpartyAccount(int $currencyId): Account
    {
        return Account::firstOrCreate(
            ['account_code' => 'SRC-'.$currencyId],
            [
                'name' => 'حساب المصدر '.$currencyId,
                'account_type_id' => $this->individualsAccountType()->id,
                'bank_type_id' => $this->seedBankType()->id,
                'currency_id' => $currencyId,
                'is_active' => true,
            ],
        );
    }

    private function fiscalYear(): FiscalYear
    {
        return FiscalYear::firstOrCreate(
            ['name' => '2026'],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true],
        );
    }

    private function transactionType(string $name): TransactionType
    {
        return TransactionType::firstOrCreate(['name' => $name]);
    }

    // ── users ────────────────────────────────────────────────────────────

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    /**
     * A user holding exactly the approved default permission set of one role —
     * so the authorization tests assert the REAL matrix rather than a
     * hand-picked permission list.
     */
    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['is_active' => true]);

        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $role->syncPermissions(PermissionRegistry::defaultPermissionsForRole($roleName));
        $user->assignRole($role);

        return $user->fresh();
    }

    // ── assertions helpers ───────────────────────────────────────────────

    /**
     * Transaction numbers in report order, flattened across currency groups.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function numbersIn(array $report): array
    {
        $numbers = [];

        foreach ($report['currency_groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $numbers[] = $row['transaction_number'];
            }
        }

        return $numbers;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderExcel(array $report): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $response = app(MuwakhaFamilyAccountStatementExcelExportService::class)->stream($report);

        $this->assertInstanceOf(StreamedResponse::class, $response);   // #46

        ob_start();
        $response->sendContent();
        $binary = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'mfs').'.xlsx';
        file_put_contents($path, $binary);

        try {
            return SpreadsheetIOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    private function sheetText(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): string
    {
        $text = '';

        foreach ($sheet->toArray(null, true, false, false) as $row) {
            $text .= implode(' | ', array_map(static fn ($cell): string => (string) $cell, $row))."\n";
        }

        return $text;
    }

    private function wordDocumentXml(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();
        $binary = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'mfs').'.docx';
        file_put_contents($path, $binary);

        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path) === true, 'The Word export must be a readable .docx archive.');
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            return $xml;
        } finally {
            @unlink($path);
        }
    }

    private function stripTags(string $xml): string
    {
        return html_entity_decode(strip_tags(str_replace('<', ' <', $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
