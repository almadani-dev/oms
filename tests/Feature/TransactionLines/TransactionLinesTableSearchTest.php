<?php

namespace Tests\Feature\TransactionLines;

use App\Filament\Resources\TransactionLines\Pages\ListTransactionLines;
use App\Models\Account;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Global search and filter coverage for "سطور المعاملات".
 *
 * The page is a read-only audit list, so every assertion here is about which
 * rows the database returns for a given search term or filter — never about
 * amounts, balances or double-entry, none of which this feature touches.
 *
 * The fixture is built so that each searchable field is the *only* thing that
 * can match its probe term: bank type, classification, transaction type,
 * account code, currency name and the line role each get a term that appears
 * nowhere else in the dataset. A test that passes by accident because the term
 * also happened to sit in a description would prove nothing.
 */
class TransactionLinesTableSearchTest extends TestCase
{
    use IntegrityTestFixtures;

    private TransactionLine $bankLine;

    private TransactionLine $cashLine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAsSuperAdmin();
        $this->seedFixture();
    }

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Two lines that differ in every searchable dimension, plus a third whose
     * account has no bank type at all.
     */
    private function seedFixture(): void
    {
        $fiscalYear = FiscalYear::create([
            'name'       => 'سنة مالية',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_active'  => true,
        ]);

        $usd = $this->makeCurrency(['name' => 'دولار امريكي', 'code' => 'USD']);
        $ils = $this->makeCurrency(['name' => 'شيكل اسرائيلي', 'code' => 'ILS']);

        $palestineBank = BankType::create(['name' => 'بنك فلسطين']);
        $cash          = BankType::create(['name' => 'كاش']);

        $bankAccount = $this->makeAccount($usd, [
            'account_code' => 'ACC-408008',
            'name'         => 'حساب البنك',
            'bank_type_id' => $palestineBank->id,
        ]);
        $cashAccount = $this->makeAccount($ils, [
            'account_code' => 'ACC-001',
            'name'         => 'حساب الصندوق',
            'bank_type_id' => $cash->id,
        ]);
        $unbankedAccount = $this->makeAccount($usd, [
            'account_code' => 'ACC-999',
            'name'         => 'حساب بلا بنك',
            'bank_type_id' => null,
        ]);

        $aidSuper   = TransactionSuperType::create(['name' => 'مساعدات']);
        $adminSuper = TransactionSuperType::create(['name' => 'مصاريف ادارية']);

        $aidType = TransactionType::create([
            'name'                      => 'مساعدة زواج',
            'transaction_super_type_id' => $aidSuper->id,
        ]);
        $salaryType = TransactionType::create([
            'name'                      => 'رواتب',
            'transaction_super_type_id' => $adminSuper->id,
        ]);

        $aidTransaction = Transaction::create([
            'fiscal_year_id'      => $fiscalYear->id,
            'transaction_type_id' => $aidType->id,
            'transaction_number'  => 'GEN-2026-0001',
            'transaction_time'    => now(),
            'reference'           => 'REF-ALPHA',
            'description'         => 'بيان معاملة المساعدة',
            'notes'               => 'ملاحظة المعاملة عن شهر محدد',
        ]);
        $salaryTransaction = Transaction::create([
            'fiscal_year_id'      => $fiscalYear->id,
            'transaction_type_id' => $salaryType->id,
            'transaction_number'  => 'PAY-2026-0001',
            'transaction_time'    => now(),
            'reference'           => 'REF-BETA',
            'description'         => 'بيان معاملة الرواتب',
        ]);

        $this->bankLine = $this->makeLine($aidTransaction, $bankAccount, $usd, [
            'amount_currency' => 1500,
            'debit_base'      => 1500,
            'description'     => 'وصف سطر المساعدة',
            'notes'           => 'ملاحظة السطر الاولى',
            'line_role'       => 'beneficiary',
        ]);

        $this->cashLine = $this->makeLine($salaryTransaction, $cashAccount, $ils, [
            'amount_currency' => 2700,
            'credit_base'     => 2700,
            'description'     => 'وصف سطر الراتب',
            'notes'           => 'ملاحظة السطر الثانية',
            'line_role'       => 'expense',
        ]);

        // Third line: account deliberately has no bank type.
        $this->makeLine($salaryTransaction, $unbankedAccount, $usd, [
            'amount_currency' => 55,
            'description'     => 'وصف سطر بلا بنك',
        ]);
    }

    /**
     * @param  array<int, TransactionLine>  $expected
     * @param  array<int, TransactionLine>  $notExpected
     */
    private function assertSearchFinds(string $term, array $expected, array $notExpected): void
    {
        Livewire::test(ListTransactionLines::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($notExpected);
    }

    public function test_search_matches_bank_type_name_through_the_account(): void
    {
        $this->assertSearchFinds('فلسطين', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_a_cash_bank_type(): void
    {
        $this->assertSearchFinds('كاش', [$this->cashLine], [$this->bankLine]);
    }

    public function test_search_matches_transaction_number(): void
    {
        $this->assertSearchFinds('GEN-2026-0001', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_transaction_reference(): void
    {
        $this->assertSearchFinds('REF-ALPHA', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_transaction_description(): void
    {
        $this->assertSearchFinds('الرواتب', [$this->cashLine], [$this->bankLine]);
    }

    /**
     * transactions.notes is user-entered ملاحظات from the financial create flows
     * (and is surfaced in the comprehensive report), so it is genuine content —
     * unlike transaction_lines.notes, which some flows fill with LINE_* tags.
     */
    public function test_search_matches_transaction_notes(): void
    {
        $this->assertSearchFinds('شهر', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_classification_name(): void
    {
        $this->assertSearchFinds('مساعدات', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_transaction_type_name(): void
    {
        $this->assertSearchFinds('زواج', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_account_code(): void
    {
        $this->assertSearchFinds('408008', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_account_name(): void
    {
        $this->assertSearchFinds('الصندوق', [$this->cashLine], [$this->bankLine]);
    }

    public function test_search_matches_currency_code(): void
    {
        $this->assertSearchFinds('ILS', [$this->cashLine], [$this->bankLine]);
    }

    public function test_search_matches_currency_name(): void
    {
        $this->assertSearchFinds('اسرائيلي', [$this->cashLine], [$this->bankLine]);
    }

    public function test_search_matches_line_description(): void
    {
        $this->assertSearchFinds('المساعدة', [$this->bankLine], [$this->cashLine]);
    }

    /**
     * `notes` is hidden by default, which is exactly why global search is
     * declared on the table rather than on the column: a hidden column is
     * skipped when Filament builds the search constraint.
     */
    public function test_search_matches_line_notes_even_though_the_column_is_hidden_by_default(): void
    {
        $this->assertSearchFinds('الثانية', [$this->cashLine], [$this->bankLine]);
    }

    /**
     * line_role stores 'beneficiary' but the table renders 'مستفيد'. Searching
     * what is on screen has to work.
     */
    public function test_search_matches_the_arabic_line_role_label_not_just_the_stored_value(): void
    {
        $this->assertSearchFinds('مستفيد', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_an_exact_amount(): void
    {
        $this->assertSearchFinds('1500', [$this->bankLine], [$this->cashLine]);
    }

    public function test_search_matches_an_amount_typed_with_display_separators(): void
    {
        $this->assertSearchFinds('2,700.00', [$this->cashLine], [$this->bankLine]);
    }

    public function test_search_that_matches_nothing_returns_no_rows(): void
    {
        Livewire::test(ListTransactionLines::class)
            ->searchTable('zzz-no-such-value')
            ->assertCanNotSeeTableRecords([$this->bankLine, $this->cashLine]);
    }

    public function test_bank_type_filter_selects_only_lines_on_that_bank_type(): void
    {
        $palestineBank = BankType::where('name', 'بنك فلسطين')->firstOrFail();

        Livewire::test(ListTransactionLines::class)
            ->filterTable('bank_type_id', $palestineBank->id)
            ->assertCanSeeTableRecords([$this->bankLine])
            ->assertCanNotSeeTableRecords([$this->cashLine]);
    }

    /**
     * An account with bank_type_id = NULL must not be swept into some bank
     * type: filtering by every bank type in turn can never surface it.
     */
    public function test_bank_type_filter_never_claims_an_account_with_no_bank_type(): void
    {
        $unbankedLine = TransactionLine::whereHas(
            'account',
            fn ($query) => $query->whereNull('bank_type_id'),
        )->firstOrFail();

        foreach (BankType::pluck('id') as $bankTypeId) {
            Livewire::test(ListTransactionLines::class)
                ->filterTable('bank_type_id', $bankTypeId)
                ->assertCanNotSeeTableRecords([$unbankedLine]);
        }
    }

    public function test_classification_filter_selects_through_transaction_and_type(): void
    {
        $aidSuper = TransactionSuperType::where('name', 'مساعدات')->firstOrFail();

        Livewire::test(ListTransactionLines::class)
            ->filterTable('transaction_super_type_id', $aidSuper->id)
            ->assertCanSeeTableRecords([$this->bankLine])
            ->assertCanNotSeeTableRecords([$this->cashLine]);
    }

    public function test_transaction_type_filter_selects_through_the_transaction(): void
    {
        $aidType = TransactionType::where('name', 'مساعدة زواج')->firstOrFail();

        Livewire::test(ListTransactionLines::class)
            ->filterTable('transaction_type_id', $aidType->id)
            ->assertCanSeeTableRecords([$this->bankLine])
            ->assertCanNotSeeTableRecords([$this->cashLine]);
    }

    public function test_currency_filter_selects_only_that_currency(): void
    {
        $ils = Currency::where('code', 'ILS')->firstOrFail();

        Livewire::test(ListTransactionLines::class)
            ->filterTable('currency_id', $ils->id)
            ->assertCanSeeTableRecords([$this->cashLine])
            ->assertCanNotSeeTableRecords([$this->bankLine]);
    }

    public function test_line_role_filter_selects_only_that_role(): void
    {
        Livewire::test(ListTransactionLines::class)
            ->filterTable('line_role', 'beneficiary')
            ->assertCanSeeTableRecords([$this->bankLine])
            ->assertCanNotSeeTableRecords([$this->cashLine]);
    }

    public function test_account_filter_selects_only_that_account(): void
    {
        $bankAccount = Account::where('account_code', 'ACC-408008')->firstOrFail();

        Livewire::test(ListTransactionLines::class)
            ->filterTable('account_id', $bankAccount->id)
            ->assertCanSeeTableRecords([$this->bankLine])
            ->assertCanNotSeeTableRecords([$this->cashLine]);
    }

    /**
     * The type list is narrowed by whichever classification is being edited in
     * the filter form, so a type from another classification can never be
     * offered — let alone chosen by mistake.
     */
    public function test_transaction_type_options_are_narrowed_by_the_chosen_classification(): void
    {
        $aidSuper = TransactionSuperType::where('name', 'مساعدات')->firstOrFail();

        $component = Livewire::test(ListTransactionLines::class)
            ->filterTable('transaction_super_type_id', $aidSuper->id);

        $options = $component->instance()
            ->getTable()
            ->getFilter('transaction_type_id')
            ->getOptions();

        $this->assertSame(['مساعدة زواج'], array_values($options));
        $this->assertNotContains('رواتب', $options);
    }

    public function test_all_transaction_types_are_offered_when_no_classification_is_chosen(): void
    {
        $options = Livewire::test(ListTransactionLines::class)
            ->instance()
            ->getTable()
            ->getFilter('transaction_type_id')
            ->getOptions();

        $this->assertContains('مساعدة زواج', $options);
        $this->assertContains('رواتب', $options);
    }

    /**
     * Search must reach the whole table, not just the rows already on screen.
     */
    public function test_search_reaches_rows_beyond_the_current_page(): void
    {
        $component = Livewire::test(ListTransactionLines::class)
            ->set('tableRecordsPerPage', 1)
            ->searchTable('فلسطين');

        $component->assertCanSeeTableRecords([$this->bankLine]);

        $this->assertSame(
            1,
            $component->instance()->getTableRecords()->total(),
            'Search should be applied by the database across all pages.',
        );
    }

    /**
     * The whole point of doing this in SQL: rendering a searched page must not
     * fan out into one query per row.
     */
    public function test_a_searched_page_issues_no_per_row_queries(): void
    {
        $component = Livewire::test(ListTransactionLines::class)->searchTable('حساب');

        $records = $component->instance()->getTableRecords();

        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach ($records as $record) {
            $record->transaction?->transaction_number;
            $record->account?->name;
            $record->account?->bankType?->name;
            $record->currency?->code;
        }

        $this->assertSame(
            [],
            DB::getQueryLog(),
            'Reading the displayed relationships should hit no database at all — they are eager loaded.',
        );
    }

    public function test_soft_deleted_lines_stay_out_of_search_results(): void
    {
        $this->cashLine->delete();

        $this->assertSearchFinds('الصندوق', [], [$this->cashLine]);
    }
}
