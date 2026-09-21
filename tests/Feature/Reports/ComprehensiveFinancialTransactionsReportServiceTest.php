<?php

namespace Tests\Feature\Reports;

use App\Models\BankType;
use App\Models\Currency;
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
