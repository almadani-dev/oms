<?php

namespace Tests\Feature\Reports;

use App\Models\BankType;
use App\Models\Currency;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Covers the multi-account filter, the line-level bank type, and the
 * structured all-sources notes collection added to
 * "تقرير الحركات المالية الشامل".
 *
 * Every assertion about money here exists to prove the presentation changes
 * did NOT move a number: debit/credit totals and per-currency grouping must
 * be byte-identical before and after the notes/bank-type work.
 */
class ComprehensiveFinancialTransactionsReportServiceTest extends TestCase
{
    use IntegrityTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
    }

    private function service(): ComprehensiveFinancialTransactionsReportService
    {
        return new ComprehensiveFinancialTransactionsReportService();
    }

    private function bankType(string $name): BankType
    {
        return BankType::create(['name' => $name]);
    }

    /**
     * One balanced transaction across two accounts that sit on two different
     * bank types — the shape every bank-type assertion below relies on.
     *
     * @return array<string, mixed>
     */
    private function twoBankTypeFixture(): array
    {
        $currency = $this->makeCurrency();

        $debitAccount = $this->makeAccount($currency, [
            'name' => 'حساب البنك الأول',
            'bank_type_id' => $this->bankType('بنك تجاري')->id,
        ]);

        $creditAccount = $this->makeAccount($currency, [
            'name' => 'حساب البنك الثاني',
            'bank_type_id' => $this->bankType('محفظة إلكترونية')->id,
        ]);

        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);

        $this->makeLine($transaction, $debitAccount, $currency, ['debit_base' => 300, 'credit_base' => 0]);
        $this->makeLine($transaction, $creditAccount, $currency, ['debit_base' => 0, 'credit_base' => 300]);

        return compact('currency', 'debitAccount', 'creditAccount', 'transaction');
    }

    // =========================================================
    // account filter: none / one / many
    // =========================================================

    public function test_empty_account_ids_means_all_accounts(): void
    {
        $this->twoBankTypeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertCount(2, $result['rows']);
        $this->assertArrayNotHasKey('الحساب', $result['filter_labels']);
    }

    public function test_single_account_id_filters_to_that_account_only(): void
    {
        ['debitAccount' => $debitAccount] = $this->twoBankTypeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31', [], null, null, [$debitAccount->id]);

        $this->assertCount(1, $result['rows']);
        $this->assertStringContainsString('حساب البنك الأول', $result['rows'][0]['account']);
        $this->assertSame(300.0, $result['currency_summaries'][0]['total_debit']);
        $this->assertSame(0.0, $result['currency_summaries'][0]['total_credit']);
    }

    public function test_multiple_account_ids_return_both_accounts_with_combined_totals(): void
    {
        [
            'debitAccount' => $debitAccount,
            'creditAccount' => $creditAccount,
        ] = $this->twoBankTypeFixture();

        $result = $this->service()->generate(
            '2026-07-01',
            '2026-07-31',
            [],
            null,
            null,
            [$debitAccount->id, $creditAccount->id],
        );

        $this->assertCount(2, $result['rows']);
        $this->assertSame(300.0, $result['currency_summaries'][0]['total_debit']);
        $this->assertSame(300.0, $result['currency_summaries'][0]['total_credit']);
        $this->assertTrue($result['currency_summaries'][0]['is_balanced']);
    }

    public function test_selecting_every_account_matches_the_unfiltered_report_exactly(): void
    {
        [
            'debitAccount' => $debitAccount,
            'creditAccount' => $creditAccount,
        ] = $this->twoBankTypeFixture();

        $all = $this->service()->generate('2026-07-01', '2026-07-31');
        $explicit = $this->service()->generate(
            '2026-07-01',
            '2026-07-31',
            [],
            null,
            null,
            [$debitAccount->id, $creditAccount->id],
        );

        $this->assertSame($all['currency_summaries'], $explicit['currency_summaries']);
        $this->assertSame($all['category_summaries'], $explicit['category_summaries']);
        $this->assertSame($all['transaction_count'], $explicit['transaction_count']);
        $this->assertSame($all['line_count'], $explicit['line_count']);
    }

    public function test_multiple_account_filter_label_joins_with_the_arabic_comma(): void
    {
        [
            'debitAccount' => $debitAccount,
            'creditAccount' => $creditAccount,
        ] = $this->twoBankTypeFixture();

        $result = $this->service()->generate(
            '2026-07-01',
            '2026-07-31',
            [],
            null,
            null,
            [$debitAccount->id, $creditAccount->id],
        );

        $label = $result['filter_labels']['الحساب'];

        $this->assertStringContainsString('، ', $label);
        $this->assertStringContainsString('حساب البنك الأول', $label);
        $this->assertStringContainsString('حساب البنك الثاني', $label);
    }

    // =========================================================
    // bank type
    // =========================================================

    public function test_each_line_carries_its_own_account_bank_type(): void
    {
        $this->twoBankTypeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $byAccount = collect($result['rows'])->keyBy(
            fn (array $row) => str_contains($row['account'], 'الأول') ? 'first' : 'second',
        );

        $this->assertSame('بنك تجاري', $byAccount['first']['bank_type']);
        $this->assertSame('محفظة إلكترونية', $byAccount['second']['bank_type']);
    }

    /**
     * A single transaction routinely spans accounts with different bank
     * types, so the report must never collapse them to one value.
     */
    public function test_one_transaction_can_show_two_different_bank_types(): void
    {
        ['transaction' => $transaction] = $this->twoBankTypeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $bankTypes = collect($result['rows'])
            ->where('transaction_id', $transaction->id)
            ->pluck('bank_type')
            ->all();

        $this->assertCount(2, $bankTypes);
        $this->assertSame(['بنك تجاري', 'محفظة إلكترونية'], $bankTypes);
    }

    public function test_null_bank_type_renders_as_a_dash(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency); // no bank_type_id
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 50]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertSame('—', $result['rows'][0]['bank_type']);
    }

    public function test_soft_deleted_bank_type_still_labels_historical_lines(): void
    {
        $currency = $this->makeCurrency();
        $bankType = $this->bankType('بنك مغلق');
        $account = $this->makeAccount($currency, ['bank_type_id' => $bankType->id]);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 50]);

        $bankType->delete();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertSame('بنك مغلق', $result['rows'][0]['bank_type']);
    }

    // =========================================================
    // notes
    // =========================================================

    public function test_transaction_and_line_notes_are_collected_with_their_source_labels(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction([
            'transaction_time' => '2026-07-10 10:00:00',
            'description' => 'وصف العملية',
            'notes' => 'ملاحظة على المعاملة',
        ]);
        $this->makeLine($transaction, $account, $currency, [
            'debit_base' => 50,
            'notes' => 'ملاحظة على سطر القيد',
        ]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $notes = collect($result['rows'][0]['notes']);

        $this->assertSame('ملاحظة على المعاملة', $notes->firstWhere('label', 'ملاحظات المعاملة')['text']);
        $this->assertSame('ملاحظة على سطر القيد', $notes->firstWhere('label', 'ملاحظات سطر القيد')['text']);
        $this->assertSame('transaction', $notes->firstWhere('label', 'ملاحظات المعاملة')['scope']);
        $this->assertSame('line', $notes->firstWhere('label', 'ملاحظات سطر القيد')['scope']);
    }

    /**
     * accounts is INNER joined on tl.account_id and already filtered by
     * whereNull('a.deleted_at'), so every line has exactly one live account —
     * its notes are reliably linked and cost no extra query.
     */
    public function test_account_notes_are_collected_per_line(): void
    {
        $currency = $this->makeCurrency();
        $debitAccount = $this->makeAccount($currency, ['name' => 'حساب أ', 'notes' => 'ملاحظة الحساب أ']);
        $creditAccount = $this->makeAccount($currency, ['name' => 'حساب ب', 'notes' => 'ملاحظة الحساب ب']);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);

        $this->makeLine($transaction, $debitAccount, $currency, ['debit_base' => 40]);
        $this->makeLine($transaction, $creditAccount, $currency, ['credit_base' => 40]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        // Each line carries ITS OWN account's notes, not the transaction's.
        $first = collect($result['rows'][0]['notes'])->firstWhere('label', 'ملاحظات الحساب');
        $second = collect($result['rows'][1]['notes'])->firstWhere('label', 'ملاحظات الحساب');

        $this->assertSame('ملاحظة الحساب أ', $first['text']);
        $this->assertSame('ملاحظة الحساب ب', $second['text']);
        $this->assertSame('line', $first['scope']);
    }

    public function test_account_notes_add_no_extra_query(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency, ['notes' => 'ملاحظة الحساب']);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 10]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // accounts is already joined, so account notes cost nothing: the run
        // stays at the documented bound of 1 main query + 5 source-note
        // pre-fetches, and none of those 5 touches `accounts`.
        $this->assertCount(6, $log);

        $accountQueries = collect($log)
            ->filter(fn (array $entry) => str_contains($entry['query'], 'from "accounts"'))
            ->count();

        $this->assertSame(0, $accountQueries, 'Account notes must not add a query of their own.');
        $this->assertNotNull(collect($result['rows'][0]['notes'])->firstWhere('label', 'ملاحظات الحساب'));
    }

    /**
     * bank_types.notes describes the lookup row, not the movement, and is
     * deliberately excluded from the notes collection.
     */
    public function test_bank_type_notes_are_never_collected(): void
    {
        $currency = $this->makeCurrency();
        $bankType = BankType::create(['name' => 'بنك', 'notes' => 'ملاحظة نوع البنك']);
        $account = $this->makeAccount($currency, ['bank_type_id' => $bankType->id]);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 10]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        foreach ($result['rows'][0]['notes'] as $note) {
            $this->assertStringNotContainsString('ملاحظة نوع البنك', $note['text']);
        }

        $this->assertSame('بنك', $result['rows'][0]['bank_type']);
    }

    public function test_empty_notes_produce_no_entries(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction([
            'transaction_time' => '2026-07-10 10:00:00',
            'notes' => '   ',
        ]);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 50, 'notes' => null]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertSame([], $result['rows'][0]['notes']);
    }

    public function test_transaction_description_is_never_merged_into_notes(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction([
            'transaction_time' => '2026-07-10 10:00:00',
            'description' => 'وصف العملية المالية',
        ]);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 50]);

        $row = $this->service()->generate('2026-07-01', '2026-07-31')['rows'][0];

        $this->assertSame('وصف العملية المالية', $row['transaction_description']);
        $this->assertArrayNotHasKey('description', $row);

        foreach ($row['notes'] as $note) {
            $this->assertStringNotContainsString('وصف العملية المالية', $note['text']);
        }
    }

    public function test_source_record_notes_are_attached_without_duplicating_lines(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $projectCostId = $this->makeProjectCost($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 120]);

        // TWO receipts on the SAME transaction: a naive LEFT JOIN would turn
        // the single line into two rows and double the reported debit.
        $this->makeReceipt($transaction->id, $projectCostId, 'ملاحظة الاستلام الأولى');
        $this->makeReceipt($transaction->id, $projectCostId, 'ملاحظة الاستلام الثانية');

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertCount(1, $result['rows']);
        $this->assertSame(1, $result['line_count']);
        $this->assertSame(120.0, $result['currency_summaries'][0]['total_debit']);

        $texts = collect($result['rows'][0]['notes'])
            ->where('label', 'ملاحظات المبلغ المستلم')
            ->pluck('text')
            ->all();

        $this->assertSame(['ملاحظة الاستلام الأولى', 'ملاحظة الاستلام الثانية'], $texts);
    }

    /**
     * Two different records holding the SAME text must stay as two entries —
     * a note's origin is never collapsed away.
     */
    public function test_identical_note_text_from_two_records_keeps_both_entries(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $projectCostId = $this->makeProjectCost($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 10]);

        $this->makeReceipt($transaction->id, $projectCostId, 'نفس النص');
        $this->makeReceipt($transaction->id, $projectCostId, 'نفس النص');

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertCount(2, collect($result['rows'][0]['notes'])->where('text', 'نفس النص'));
    }

    public function test_soft_deleted_source_records_contribute_no_notes(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $projectCostId = $this->makeProjectCost($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 10]);

        $receiptId = $this->makeReceipt($transaction->id, $projectCostId, 'ملاحظة محذوفة');
        DB::table('project_cost_receipts')->where('id', $receiptId)->update(['deleted_at' => now()]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertSame([], $result['rows'][0]['notes']);
    }

    /**
     * The whole point of the pre-fetch design: the number of queries must not
     * grow with the number of transactions in the period.
     */
    public function test_note_prefetch_is_bounded_and_never_becomes_n_plus_1(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $projectCostId = $this->makeProjectCost($currency);

        foreach (range(1, 6) as $index) {
            $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
            $this->makeLine($transaction, $account, $currency, ['debit_base' => 10]);
            $this->makeReceipt($transaction->id, $projectCostId, "ملاحظة {$index}");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(6, $result['rows']);
        // 1 main lines query + at most 5 bounded source-note prefetches.
        $this->assertLessThanOrEqual(6, $queryCount);
    }

    public function test_no_source_note_queries_run_when_the_period_is_empty(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $result['rows']);
        $this->assertSame(1, $queryCount);
    }

    // =========================================================
    // category grouping key (drives the expand/collapse UI)
    // =========================================================

    public function test_category_summaries_expose_a_stable_key_matching_their_rows(): void
    {
        $this->twoBankTypeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $summary = $result['category_summaries'][0];

        $this->assertArrayHasKey('key', $summary);
        $this->assertNotSame('', $summary['key']);

        // The UI filters by category_key, not display text — this is the exact
        // expression the expanded classification block uses.
        $categoryRows = array_filter(
            $result['rows'],
            fn (array $row) => $row['category_key'] === $summary['key'],
        );

        $this->assertCount($summary['line_count'], $categoryRows);
    }

    public function test_category_key_is_derived_from_the_super_type_id(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);

        $superType = DB::table('transaction_super_types')->insertGetId([
            'name' => 'تصنيف',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $typeId = DB::table('transactions_types')->insertGetId([
            'name' => 'نوع',
            'transaction_super_type_id' => $superType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = $this->makeTransaction([
            'transaction_time' => '2026-07-10 10:00:00',
            'transaction_type_id' => $typeId,
        ]);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 10]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertSame('id-'.$superType, $result['rows'][0]['category_key']);
        $this->assertSame('id-'.$superType, $result['category_summaries'][0]['key']);
    }

    public function test_unclassified_lines_share_the_explicit_none_key(): void
    {
        // makeTransactionType() creates a type with no super type.
        $this->twoBankTypeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertSame('none', $result['category_summaries'][0]['key']);
        $this->assertSame('غير محدد', $result['category_summaries'][0]['name']);
        $this->assertSame('none', $result['rows'][0]['category_key']);
    }

    /**
     * Neither transaction_super_types.name nor transactions_types.name has a
     * unique constraint or a unique validation rule, so two distinct
     * classifications can share a display name. Keying buckets by ID keeps
     * them as two rows; keying by name would silently merge them.
     */
    public function test_two_classifications_sharing_a_name_stay_two_buckets(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);

        $keys = [];

        foreach ([100.0, 250.0] as $amount) {
            $superId = DB::table('transaction_super_types')->insertGetId([
                'name' => 'اسم مكرر',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $typeId = DB::table('transactions_types')->insertGetId([
                'name' => 'نوع '.$superId,
                'transaction_super_type_id' => $superId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transaction = $this->makeTransaction([
                'transaction_time' => '2026-07-10 10:00:00',
                'transaction_type_id' => $typeId,
            ]);
            $this->makeLine($transaction, $account, $currency, ['debit_base' => $amount]);

            $keys[] = 'id-'.$superId;
        }

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        $this->assertCount(2, $result['category_summaries']);
        $this->assertSame($keys, array_column($result['category_summaries'], 'key'));
        $this->assertSame(['اسم مكرر', 'اسم مكرر'], array_column($result['category_summaries'], 'name'));

        // Each bucket keeps its own total instead of being collapsed into 350.
        $this->assertSame(100.0, $result['category_summaries'][0]['currencies'][0]['total_debit']);
        $this->assertSame(250.0, $result['category_summaries'][1]['currencies'][0]['total_debit']);

        // And filtering rows by key yields exactly that bucket's lines.
        foreach ($result['category_summaries'] as $summary) {
            $rows = array_filter(
                $result['rows'],
                fn (array $row) => $row['category_key'] === $summary['key'],
            );
            $this->assertCount($summary['line_count'], $rows);
        }

        // The grand per-currency total is unaffected by how buckets are keyed.
        $this->assertSame(350.0, $result['currency_summaries'][0]['total_debit']);
    }

    /**
     * The type-summary buckets and row['type_key'] are two halves of the same
     * contract: the UI expands a transaction type by filtering the rows it
     * already holds on this key, so every bucket must map onto exactly its
     * own lines — including when two types share a display name.
     */
    public function test_type_summaries_expose_a_stable_key_matching_their_rows(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);

        $superId = DB::table('transaction_super_types')->insertGetId([
            'name' => 'تصنيف مشترك',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keys = [];

        foreach ([100.0, 250.0] as $amount) {
            $typeId = DB::table('transactions_types')->insertGetId([
                'name' => 'نوع مكرر',
                'transaction_super_type_id' => $superId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transaction = $this->makeTransaction([
                'transaction_time' => '2026-07-10 10:00:00',
                'transaction_type_id' => $typeId,
            ]);
            $this->makeLine($transaction, $account, $currency, ['debit_base' => $amount]);

            $keys[] = 'id-'.$typeId;
        }

        $result = $this->service()->generate('2026-07-01', '2026-07-31');

        // Same name, two buckets — nothing merged on display text.
        $this->assertCount(2, $result['type_summaries']);
        $this->assertSame($keys, array_column($result['type_summaries'], 'key'));
        $this->assertSame(['نوع مكرر', 'نوع مكرر'], array_column($result['type_summaries'], 'name'));

        // Each bucket keeps its own total instead of being collapsed into 350.
        $this->assertSame(100.0, $result['type_summaries'][0]['currencies'][0]['total_debit']);
        $this->assertSame(250.0, $result['type_summaries'][1]['currencies'][0]['total_debit']);

        // And filtering rows by key yields exactly that bucket's lines.
        foreach ($result['type_summaries'] as $summary) {
            $rows = array_filter(
                $result['rows'],
                fn (array $row) => $row['type_key'] === $summary['key'],
            );
            $this->assertCount($summary['line_count'], $rows);
        }

        // Both classifications still roll up under the one shared super type,
        // and the grand per-currency total is untouched by any of this.
        $this->assertCount(1, $result['category_summaries']);
        $this->assertSame(350.0, $result['currency_summaries'][0]['total_debit']);
    }

    // =========================================================
    // helpers
    // =========================================================

    private function makeProjectCost(Currency $currency): int
    {
        $superId = DB::table('projects_super')->insertGetId([
            'name' => 'مظلة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $statusId = DB::table('projects_status')->insertGetId([
            'name' => 'قيد التنفيذ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $projectId = DB::table('projects')->insertGetId([
            'name' => 'مشروع اختبار',
            'project_super_id' => $superId,
            'project_status_id' => $statusId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('projects_costs')->insertGetId([
            'project_id' => $projectId,
            'amount' => 1000,
            'currency_id' => $currency->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================
    // "طرف الحساب" (account side): all / debit / credit
    // =========================================================

    /**
     * Two accounts that each appear on BOTH sides of the ledger, so a side
     * filter has something real to narrow — with a one-sided fixture every
     * assertion below would pass by accident.
     *
     * T1 (تصنيف التحصيل):  A debit 300  /  B credit 300
     * T2 (تصنيف الصرف):    A credit 100 /  B debit 100
     *
     * Both transactions stay balanced; the side filter never changes that,
     * it only hides one appearance from the VIEW.
     *
     * @return array<string, mixed>
     */
    private function bothSidesFixture(): array
    {
        $currency = $this->makeCurrency();

        $accountA = $this->makeAccount($currency, ['name' => 'بنك فلسطين']);
        $accountB = $this->makeAccount($currency, ['name' => 'الصندوق']);

        $collectionType = TransactionType::create([
            'name' => 'نوع التحصيل',
            'transaction_super_type_id' => TransactionSuperType::create(['name' => 'تصنيف التحصيل'])->id,
        ]);

        $paymentType = TransactionType::create([
            'name' => 'نوع الصرف',
            'transaction_super_type_id' => TransactionSuperType::create(['name' => 'تصنيف الصرف'])->id,
        ]);

        $first = $this->makeTransaction([
            'transaction_time' => '2026-07-10 10:00:00',
            'transaction_type_id' => $collectionType->id,
        ]);
        $this->makeLine($first, $accountA, $currency, ['debit_base' => 300, 'credit_base' => 0]);
        $this->makeLine($first, $accountB, $currency, ['debit_base' => 0, 'credit_base' => 300]);

        $second = $this->makeTransaction([
            'transaction_time' => '2026-07-12 10:00:00',
            'transaction_type_id' => $paymentType->id,
        ]);
        $this->makeLine($second, $accountA, $currency, ['debit_base' => 0, 'credit_base' => 100]);
        $this->makeLine($second, $accountB, $currency, ['debit_base' => 100, 'credit_base' => 0]);

        return compact('currency', 'accountA', 'accountB', 'collectionType', 'paymentType');
    }

    /**
     * @param array<int, int> $accountIds
     * @return array<string, mixed>
     */
    private function generateWithSide(array $accountIds, string $side): array
    {
        return $this->service()->generate(
            '2026-07-01',
            '2026-07-31',
            [],
            null,
            null,
            $accountIds,
            null,
            null,
            $side,
        );
    }

    /**
     * [account name, debit, credit] per row — the account's generated code
     * prefix is stripped so the side assertions read as ledger appearances
     * rather than as fixture ids.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{0: string, 1: float, 2: float}>
     */
    private function sideRows(array $rows): array
    {
        return array_map(
            function (array $row): array {
                $parts = explode(' - ', (string) $row['account']);

                return [end($parts), $row['debit'], $row['credit']];
            },
            $rows,
        );
    }

    /** TEST 1 / TEST 15 — no account selected + side "all" is the untouched report. */
    public function test_no_account_selection_with_side_all_matches_the_unfiltered_report(): void
    {
        $this->bothSidesFixture();

        $baseline = $this->service()->generate('2026-07-01', '2026-07-31');
        $withSide = $this->generateWithSide([], ComprehensiveFinancialTransactionsReportService::SIDE_ALL);

        $this->assertCount(4, $baseline['rows']);
        $this->assertSame($baseline, $withSide);
        $this->assertArrayNotHasKey('طرف الحساب', $withSide['filter_labels']);
    }

    /** TEST 2 — one account, side "all": both its appearances survive. */
    public function test_single_account_with_side_all_keeps_both_debit_and_credit_rows(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $result = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_ALL);

        $this->assertSame([
            ['بنك فلسطين', 300.0, 0.0],
            ['بنك فلسطين', 0.0, 100.0],
        ], $this->sideRows($result['rows']));

        $this->assertSame(300.0, $result['currency_summaries'][0]['total_debit']);
        $this->assertSame(100.0, $result['currency_summaries'][0]['total_credit']);
        $this->assertArrayNotHasKey('طرف الحساب', $result['filter_labels']);
    }

    /** TEST 3 — side "debit" keeps only the lines whose own debit_base > 0. */
    public function test_single_account_with_side_debit_keeps_only_positive_debit_lines(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $result = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT);

        $this->assertSame([['بنك فلسطين', 300.0, 0.0]], $this->sideRows($result['rows']));
        $this->assertSame(1, $result['line_count']);

        // A one-sided view is EXPECTED to look unbalanced — the underlying
        // transactions are untouched and still balanced.
        $this->assertSame(300.0, $result['currency_summaries'][0]['total_debit']);
        $this->assertSame(0.0, $result['currency_summaries'][0]['total_credit']);

        $this->assertSame('مدين', $result['filter_labels']['طرف الحساب']);
        $this->assertSame('debit', $result['account_side']);
    }

    /** TEST 4 — side "credit" keeps only the lines whose own credit_base > 0. */
    public function test_single_account_with_side_credit_keeps_only_positive_credit_lines(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $result = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT);

        $this->assertSame([['بنك فلسطين', 0.0, 100.0]], $this->sideRows($result['rows']));
        $this->assertSame(0.0, $result['currency_summaries'][0]['total_debit']);
        $this->assertSame(100.0, $result['currency_summaries'][0]['total_credit']);
        $this->assertSame('دائن', $result['filter_labels']['طرف الحساب']);
    }

    /** Debit + credit of one account recompose that account's "all" view exactly. */
    public function test_debit_and_credit_views_together_recompose_the_all_view(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $all = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_ALL);
        $debit = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT);
        $credit = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT);

        $lineIds = fn (array $result): array => array_column($result['rows'], 'line_id');

        $recomposed = array_merge($lineIds($debit), $lineIds($credit));
        sort($recomposed);
        $expected = $lineIds($all);
        sort($expected);

        $this->assertSame($expected, $recomposed);
        $this->assertSame(
            $all['currency_summaries'][0]['total_debit'],
            $debit['currency_summaries'][0]['total_debit'],
        );
        $this->assertSame(
            $all['currency_summaries'][0]['total_credit'],
            $credit['currency_summaries'][0]['total_credit'],
        );
    }

    /** TEST 5 — one side applied to the whole selected-account set, not per account. */
    public function test_multiple_accounts_with_side_debit_keep_every_selected_account_debit_line(): void
    {
        ['accountA' => $accountA, 'accountB' => $accountB] = $this->bothSidesFixture();

        $result = $this->generateWithSide(
            [$accountA->id, $accountB->id],
            ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT,
        );

        $this->assertSame([
            ['بنك فلسطين', 300.0, 0.0],
            ['الصندوق', 100.0, 0.0],
        ], $this->sideRows($result['rows']));

        $this->assertSame(400.0, $result['currency_summaries'][0]['total_debit']);
        $this->assertSame(0.0, $result['currency_summaries'][0]['total_credit']);
    }

    /** TEST 6 — same, for the credit side. */
    public function test_multiple_accounts_with_side_credit_keep_every_selected_account_credit_line(): void
    {
        ['accountA' => $accountA, 'accountB' => $accountB] = $this->bothSidesFixture();

        $result = $this->generateWithSide(
            [$accountA->id, $accountB->id],
            ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT,
        );

        $this->assertSame([
            ['الصندوق', 0.0, 300.0],
            ['بنك فلسطين', 0.0, 100.0],
        ], $this->sideRows($result['rows']));

        $this->assertSame(0.0, $result['currency_summaries'][0]['total_debit']);
        $this->assertSame(400.0, $result['currency_summaries'][0]['total_credit']);
    }

    /**
     * TEST 7 — a side with no account selection must never filter the whole
     * report. This is the safety property the disabled UI control only
     * *helps* with; the service guarantees it on its own.
     */
    public function test_a_side_without_any_account_selection_is_forced_back_to_all(): void
    {
        $this->bothSidesFixture();

        $baseline = $this->service()->generate('2026-07-01', '2026-07-31');

        foreach ([
            ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT,
            ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT,
        ] as $side) {
            $result = $this->generateWithSide([], $side);

            $this->assertCount(4, $result['rows'], "Side {$side} must not narrow an all-accounts report.");
            $this->assertSame($baseline['rows'], $result['rows']);
            $this->assertSame('all', $result['account_side']);
            $this->assertArrayNotHasKey('طرف الحساب', $result['filter_labels']);
        }
    }

    /** An unknown/garbage side degrades to "all" rather than filtering blindly. */
    public function test_an_unknown_side_value_degrades_to_all(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $result = $this->generateWithSide([$accountA->id], 'DEBIT');

        $this->assertSame('all', $result['account_side']);
        $this->assertCount(2, $result['rows']);
    }

    /** TEST 9 — classification statistics come from the side-filtered rows. */
    public function test_classification_statistics_reflect_the_account_side_filter(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $debit = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT);

        $this->assertCount(1, $debit['category_summaries']);
        $this->assertSame('تصنيف التحصيل', $debit['category_summaries'][0]['name']);
        $this->assertSame(1, $debit['category_summaries'][0]['line_count']);
        $this->assertSame(300.0, $debit['category_summaries'][0]['currencies'][0]['total_debit']);
        $this->assertSame(0.0, $debit['category_summaries'][0]['currencies'][0]['total_credit']);

        $credit = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT);

        $this->assertCount(1, $credit['category_summaries']);
        $this->assertSame('تصنيف الصرف', $credit['category_summaries'][0]['name']);
        $this->assertSame(100.0, $credit['category_summaries'][0]['currencies'][0]['total_credit']);
    }

    /** TEST 10 — transaction-type statistics come from the same filtered rows. */
    public function test_transaction_type_statistics_reflect_the_account_side_filter(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $debit = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT);

        $this->assertCount(1, $debit['type_summaries']);
        $this->assertSame('نوع التحصيل', $debit['type_summaries'][0]['name']);
        $this->assertSame(300.0, $debit['type_summaries'][0]['currencies'][0]['total_debit']);

        $credit = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT);

        $this->assertCount(1, $credit['type_summaries']);
        $this->assertSame('نوع الصرف', $credit['type_summaries'][0]['name']);
        $this->assertSame(100.0, $credit['type_summaries'][0]['currencies'][0]['total_credit']);
    }

    /**
     * TESTS 11 + 12 — the expansion keys the UI filters $rows by must only
     * ever address rows that survived the side filter, so an expanded
     * classification/type can never reveal a hidden opposite-side line.
     */
    public function test_expansion_keys_only_address_side_filtered_rows(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $debit = $this->generateWithSide([$accountA->id], ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT);

        $categoryKey = $debit['category_summaries'][0]['key'];
        $typeKey = $debit['type_summaries'][0]['key'];

        $byCategory = array_values(array_filter($debit['rows'], fn (array $row): bool => $row['category_key'] === $categoryKey));
        $byType = array_values(array_filter($debit['rows'], fn (array $row): bool => $row['type_key'] === $typeKey));

        $this->assertSame([['بنك فلسطين', 300.0, 0.0]], $this->sideRows($byCategory));
        $this->assertSame([['بنك فلسطين', 300.0, 0.0]], $this->sideRows($byType));

        // The credit classification/type are not even present to expand.
        $this->assertSame([$categoryKey], array_column($debit['category_summaries'], 'key'));
        $this->assertSame([$typeKey], array_column($debit['type_summaries'], 'key'));
    }

    /**
     * A zero-amount line is neither a debit nor a credit appearance: the
     * filter reads the amount, never line_role or the transaction type.
     */
    public function test_a_zero_amount_line_belongs_to_neither_side(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency, ['name' => 'حساب بلا مبلغ']);

        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $account, $currency, [
            'debit_base' => 0,
            'credit_base' => 0,
            'line_role' => 'debit',
        ]);

        $all = ComprehensiveFinancialTransactionsReportService::SIDE_ALL;
        $debit = ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT;
        $credit = ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT;

        $this->assertCount(1, $this->generateWithSide([$account->id], $all)['rows']);
        $this->assertCount(0, $this->generateWithSide([$account->id], $debit)['rows']);
        $this->assertCount(0, $this->generateWithSide([$account->id], $credit)['rows']);
    }

    private function makeReceipt(int $transactionId, int $projectCostId, string $notes): int
    {
        return (int) DB::table('project_cost_receipts')->insertGetId([
            'project_cost_id' => $projectCostId,
            'transaction_id' => $transactionId,
            'amount' => 10,
            'date' => '2026-07-10',
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
