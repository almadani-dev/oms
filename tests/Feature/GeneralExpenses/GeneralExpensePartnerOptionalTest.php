<?php

namespace Tests\Feature\GeneralExpenses;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\ViewGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Schemas\GeneralExpenseForm;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\TransactionType;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * المصروفات العامة لا تتطلب جهة / مستفيداً.
 *
 * Proves the approved business decision end-to-end:
 *
 *  - the beneficiary field no longer exists on the Create/Edit form, so a
 *    submitted payload carries no `partner_id` key at all;
 *  - a NEW general expense therefore stores NULL in BOTH general_expenses
 *    and its transaction, and its two lines are still balanced;
 *  - editing a HISTORICAL expense that already has a beneficiary NEVER
 *    clears it, because handleRecordUpdate() reads the value from the record
 *    itself rather than from submitted (or hidden) form state;
 *  - the two tables stay byte-identical in both directions.
 *
 * Uses the same schema-only SQLite bootstrap as BalanceGuardIntegrationTest.
 */
class GeneralExpensePartnerOptionalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/' . basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path'     => $paths,
            '--realpath' => false,
            '--force'    => true,
        ]);

        $this->actingAs(User::factory()->create());
    }

    private function baseFixture(): array
    {
        $currency    = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType    = BankType::create(['name' => 'نوع بنك']);

        $debitAccount  = Account::create([
            'account_code' => 'مدين', 'name' => 'مدين', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 0, 'is_active' => true,
        ]);
        $creditAccount = Account::create([
            'account_code' => 'دائن', 'name' => 'دائن', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 5000, 'is_active' => true,
        ]);

        $fiscalYear      = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType     = PartnerType::create(['name' => 'نوع شريك']);
        $partner         = Partner::create(['name' => 'شريك تاريخي', 'partner_type_id' => $partnerType->id]);

        return compact('currency', 'accountType', 'bankType', 'debitAccount', 'creditAccount', 'fiscalYear', 'transactionType', 'partner');
    }

    /**
     * The payload the CURRENT form produces: no `partner_id` key whatsoever,
     * because the component was removed. Overrides may add one back to
     * simulate a legacy record created while the field still existed.
     */
    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'amount'                     => 250,
            'currency_id'                => $fx['currency']->id,
            'date'                       => '2026-07-18',
            'transaction_super_type_id'  => null,
            'transaction_type_id'        => $fx['transactionType']->id,
            'fiscal_year_id'             => $fx['fiscalYear']->id,
            'description'                => null,
            'notes'                      => null,
            'debit_account_id'           => $fx['debitAccount']->id,
            'debit_account_type_id'      => $fx['debitAccount']->account_type_id,
            'debit_bank_type_id'         => $fx['debitAccount']->bank_type_id,
            'credit_account_id'          => $fx['creditAccount']->id,
            'credit_account_type_id'     => $fx['creditAccount']->account_type_id,
            'credit_bank_type_id'        => $fx['creditAccount']->bank_type_id,
            'expense_image'              => null,
        ], $overrides);
    }

    private function invokeCreate(array $data): GeneralExpense
    {
        $page   = new CreateGeneralExpense();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(GeneralExpense $record): array
    {
        $page         = new EditGeneralExpense();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        return $method->invoke($page, []);
    }

    private function invokeUpdate(GeneralExpense $record, array $data): GeneralExpense
    {
        $page   = new EditGeneralExpense();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    /**
     * Reads the raw column straight from the database, bypassing the model,
     * so a relation or accessor can never mask the stored value.
     */
    private function rawPartnerIds(GeneralExpense $expense): array
    {
        return [
            'expense'     => DB::table('general_expenses')->where('id', $expense->id)->value('partner_id'),
            'transaction' => DB::table('transactions')->where('id', $expense->transaction_id)->value('partner_id'),
        ];
    }

    /* =====================================================
     | FORM - the field is gone
     ===================================================== */

    public function test_the_beneficiary_field_no_longer_exists_on_the_form(): void
    {
        $components = GeneralExpenseForm::configure(Schema::make(new CreateGeneralExpense))->getFlatComponents();

        $names = array_filter(array_map(
            fn ($component) => method_exists($component, 'getName') ? $component->getName() : null,
            $components,
        ));

        $this->assertNotContains('partner_id', $names, 'الجهة / المستفيد must no longer be a form component.');

        // Guard against collateral damage: the neighbouring fields survive.
        foreach (['amount', 'currency_id', 'date', 'transaction_type_id', 'fiscal_year_id'] as $expected) {
            $this->assertContains($expected, $names, "Form field [{$expected}] must be untouched.");
        }
    }

    /* =====================================================
     | CREATE - new records store NULL
     ===================================================== */

    public function test_create_without_a_beneficiary_succeeds_and_stores_null_in_both_tables(): void
    {
        $fx = $this->baseFixture();

        $data = $this->baseData($fx);
        $this->assertArrayNotHasKey('partner_id', $data, 'The current form payload must carry no partner_id key.');

        $expense = $this->invokeCreate($data);

        $this->assertNotNull($expense->id);

        $raw = $this->rawPartnerIds($expense);
        $this->assertNull($raw['expense'], 'general_expenses.partner_id must be NULL for a new expense.');
        $this->assertNull($raw['transaction'], 'transactions.partner_id must be NULL for a new expense.');
    }

    public function test_a_null_beneficiary_still_produces_two_balanced_lines(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $lines = $expense->transaction->lines()->get();

        $this->assertCount(2, $lines);
        $this->assertEquals(250, (float) $lines->sum('debit_base'));
        $this->assertEquals(250, (float) $lines->sum('credit_base'));
        $this->assertEquals($lines->sum('debit_base'), $lines->sum('credit_base'));
    }

    public function test_account_balances_are_identical_with_and_without_a_beneficiary(): void
    {
        // With a beneficiary (a legacy-shaped payload).
        $fx      = $this->baseFixture();
        $this->invokeCreate($this->baseData($fx, ['partner_id' => $fx['partner']->id]));

        $withPartner = [
            'debit'  => (float) $fx['debitAccount']->fresh()->current_balance,
            'credit' => (float) $fx['creditAccount']->fresh()->current_balance,
        ];

        // Without a beneficiary, on a completely fresh fixture.
        $this->refreshDatabaseForSecondScenario();

        $fx2 = $this->baseFixture();
        $this->invokeCreate($this->baseData($fx2));

        $withoutPartner = [
            'debit'  => (float) $fx2['debitAccount']->fresh()->current_balance,
            'credit' => (float) $fx2['creditAccount']->fresh()->current_balance,
        ];

        $this->assertSame($withPartner, $withoutPartner, 'Beneficiary presence must not affect any balance.');
        $this->assertSame(250.0, $withoutPartner['debit']);
        $this->assertSame(4750.0, $withoutPartner['credit']);
    }

    /* =====================================================
     | EDIT - historical values are preserved
     ===================================================== */

    public function test_editing_a_historical_expense_preserves_its_beneficiary_on_both_tables(): void
    {
        $fx = $this->baseFixture();

        // A legacy record, created while the form still had the field.
        $expense = $this->invokeCreate($this->baseData($fx, ['partner_id' => $fx['partner']->id]));

        $before = $this->rawPartnerIds($expense);
        $this->assertSame($fx['partner']->id, (int) $before['expense']);
        $this->assertSame($fx['partner']->id, (int) $before['transaction']);

        // Edit an UNRELATED field through the current (field-less) form.
        $hydrated = $this->invokeMutateBeforeFill($expense->fresh());
        $this->assertArrayNotHasKey('partner_id', $hydrated, 'The edit form must not prefill hidden partner state.');

        $hydrated['amount']      = 600;
        $hydrated['description'] = 'فاتورة كهرباء';

        $updated = $this->invokeUpdate($expense->fresh(), $hydrated);

        $this->assertEquals(600, (float) $updated->amount);

        $after = $this->rawPartnerIds($updated);
        $this->assertSame($fx['partner']->id, (int) $after['expense'], 'Historical beneficiary was silently erased from general_expenses.');
        $this->assertSame($fx['partner']->id, (int) $after['transaction'], 'Historical beneficiary was silently erased from transactions.');
        $this->assertSame($before, $after, 'Both tables must be unchanged and still synchronized.');
    }

    public function test_a_new_null_expense_stays_null_after_an_edit(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $hydrated           = $this->invokeMutateBeforeFill($expense->fresh());
        $hydrated['amount'] = 410;

        $updated = $this->invokeUpdate($expense->fresh(), $hydrated);

        $this->assertEquals(410, (float) $updated->amount);

        $after = $this->rawPartnerIds($updated);
        $this->assertNull($after['expense'], 'A NULL beneficiary must stay NULL on general_expenses.');
        $this->assertNull($after['transaction'], 'A NULL beneficiary must stay NULL on transactions.');
    }

    public function test_an_edit_cannot_inject_a_beneficiary_through_submitted_data(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        // Even a payload that still carries partner_id (a stale client, a
        // replayed request) must not write it: the record is the only source.
        $hydrated                 = $this->invokeMutateBeforeFill($expense->fresh());
        $hydrated['amount']       = 250;
        $hydrated['partner_id']   = $fx['partner']->id;

        $updated = $this->invokeUpdate($expense->fresh(), $hydrated);

        $after = $this->rawPartnerIds($updated);
        $this->assertNull($after['expense'], 'Submitted partner_id must be ignored on general_expenses.');
        $this->assertNull($after['transaction'], 'Submitted partner_id must be ignored on transactions.');
    }

    public function test_editing_a_historical_expense_keeps_the_two_lines_balanced(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx, ['partner_id' => $fx['partner']->id]));

        $hydrated           = $this->invokeMutateBeforeFill($expense->fresh());
        $hydrated['amount'] = 600;

        $updated = $this->invokeUpdate($expense->fresh(), $hydrated);

        $lines = $updated->transaction->lines()->get();

        $this->assertCount(2, $lines);
        $this->assertEquals(600, (float) $lines->sum('debit_base'));
        $this->assertEquals($lines->sum('debit_base'), $lines->sum('credit_base'));
        $this->assertEquals(600, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4400, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    /* =====================================================
     | VIEW - NULL renders as "—"
     ===================================================== */

    public function test_the_view_page_renders_a_dash_placeholder_for_a_null_beneficiary(): void
    {
        $page = new ViewGeneralExpense;

        $entry = collect($page->infolist(Schema::make($page))->getFlatComponents())
            ->first(fn ($component) => $component instanceof TextEntry && $component->getName() === 'partner.name');

        $this->assertNotNull($entry, 'The beneficiary entry must remain on the view page for historical records.');
        $this->assertSame('—', $entry->getPlaceholder());
    }

    public function test_the_view_state_resolves_to_null_for_a_new_expense_and_to_the_name_for_a_historical_one(): void
    {
        $fx = $this->baseFixture();

        $new        = $this->invokeCreate($this->baseData($fx))->fresh();
        $historical = $this->invokeCreate($this->baseData($fx, ['partner_id' => $fx['partner']->id]))->fresh();

        // The exact fallback the table column and the infolist entry both use.
        $state = fn (GeneralExpense $r) => $r->partner?->name ?? $r->transaction?->partner?->name;

        $this->assertNull($state($new), 'A new expense must resolve to NULL so the "—" placeholder renders.');
        $this->assertSame('شريك تاريخي', $state($historical), 'A historical expense must still display its beneficiary.');
    }

    /**
     * Rebuilds an empty schema so a second, independent scenario can run
     * inside one test without leaking rows from the first.
     */
    private function refreshDatabaseForSecondScenario(): void
    {
        foreach (['transaction_lines', 'transactions', 'general_expenses', 'accounts', 'currencies', 'accounts_type', 'bank_types', 'fiscal_years', 'transactions_types', 'partners', 'partners_types'] as $table) {
            DB::table($table)->delete();
        }
    }
}
