<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\TrialBalanceExcelExportService;
use App\Services\Reports\TrialBalanceReportService;
use App\Services\Reports\TrialBalanceWordExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * OMS Task 8 Section C12 coverage for the current Trial Balance design —
 * "Phase 1" per docs/DECISIONS_LOG.md (2026-07-05): single required
 * currency, period-only totals (no opening/closing balance columns exist in
 * this design, so no test asserts one). Opening/closing-balance items from
 * the task's own C12 checklist are intentionally not applicable here and are
 * documented as such rather than invented.
 */
class TrialBalanceReportServiceTest extends TestCase
{
    use IntegrityTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
    }

    private function service(): TrialBalanceReportService
    {
        return new TrialBalanceReportService();
    }

    public function test_same_currency_balanced_transaction_is_reported_correctly(): void
    {
        $currency = $this->makeCurrency();
        $debitAccount = $this->makeAccount($currency);
        $creditAccount = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);

        $this->makeLine($transaction, $debitAccount, $currency, ['debit_base' => 300, 'credit_base' => 0]);
        $this->makeLine($transaction, $creditAccount, $currency, ['debit_base' => 0, 'credit_base' => 300]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);

        $this->assertSame(300.0, $result['grand_debit']);
        $this->assertSame(300.0, $result['grand_credit']);
        $this->assertSame(0.0, $result['difference']);
        $this->assertTrue($result['is_balanced']);
        $this->assertCount(2, $result['rows']);
    }

    /**
     * The canonical valid exchange: 1000 (source currency) at rate 3
     * converts to 2999.94 (destination currency). Selecting the source
     * currency's report must show only its own 1000-side movement — never
     * 1000 combined/compared with 2999.94 as if they were the same unit.
     */
    public function test_usd_selected_report_never_includes_ils_raw_amounts(): void
    {
        [$sourceCurrency, $destinationCurrency, $accounts, $transaction] = $this->makeExchangeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $sourceCurrency->id, null, true);

        $sourceRow = collect($result['rows'])->firstWhere('account_code', $accounts['source']->account_code);
        $this->assertNotNull($sourceRow);
        $this->assertSame(1000.0, $sourceRow['total_credit']);
        $this->assertSame(0.0, $sourceRow['total_debit']);

        // The destination account (a different currency's account) must not
        // appear at all in the USD-currency report population.
        $destinationRow = collect($result['rows'])->firstWhere('account_code', $accounts['destination']->account_code);
        $this->assertNull($destinationRow);

        // Grand totals for the USD report must never contain the 2999.94
        // ILS-side figure.
        $this->assertNotEquals(2999.94, $result['grand_debit']);
    }

    public function test_ils_selected_report_never_includes_usd_raw_amounts(): void
    {
        [$sourceCurrency, $destinationCurrency, $accounts, $transaction] = $this->makeExchangeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $destinationCurrency->id, null, true);

        $destinationRow = collect($result['rows'])->firstWhere('account_code', $accounts['destination']->account_code);
        $this->assertNotNull($destinationRow);
        $this->assertSame(2999.94, $destinationRow['total_debit']);

        $sourceRow = collect($result['rows'])->firstWhere('account_code', $accounts['source']->account_code);
        $this->assertNull($sourceRow);

        $this->assertNotEquals(1000.0, $result['grand_debit']);
    }

    public function test_no_mixed_currency_arithmetic_ever_produces_a_blended_grand_total(): void
    {
        [$sourceCurrency, , $accounts] = $this->makeExchangeFixture();

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $sourceCurrency->id, null, true);

        // If the report ever summed 1000 (source) with 2999.94 (destination),
        // the grand total would be 3999.94-ish or similar — it must not be.
        $this->assertNotEqualsWithDelta(3999.94, $result['grand_debit'] + $result['grand_credit'], 0.001);
    }

    public function test_transaction_before_start_date_is_excluded(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $other = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-06-15 10:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 200, 'credit_base' => 0]);
        $this->makeLine($transaction, $other, $currency, ['debit_base' => 0, 'credit_base' => 200]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, false);

        $this->assertEquals(0.0, $result['grand_debit']);
        $this->assertCount(0, $result['rows']);
    }

    public function test_transaction_exactly_on_start_date_is_included(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $other = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-01 00:00:01']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 150, 'credit_base' => 0]);
        $this->makeLine($transaction, $other, $currency, ['debit_base' => 0, 'credit_base' => 150]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);

        $this->assertSame(150.0, $result['grand_debit']);
    }

    public function test_transaction_exactly_on_end_date_is_included(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $other = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-31 23:59:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 175, 'credit_base' => 0]);
        $this->makeLine($transaction, $other, $currency, ['debit_base' => 0, 'credit_base' => 175]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);

        $this->assertSame(175.0, $result['grand_debit']);
    }

    public function test_transaction_after_end_date_is_excluded(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $other = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-08-01 00:00:00']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 200, 'credit_base' => 0]);
        $this->makeLine($transaction, $other, $currency, ['debit_base' => 0, 'credit_base' => 200]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, false);

        $this->assertEquals(0.0, $result['grand_debit']);
    }

    public function test_account_type_filter_restricts_population_but_not_arithmetic(): void
    {
        $currency = $this->makeCurrency();
        $typeA = \App\Models\AccountType::create(['name' => 'نوع أ ' . uniqid()]);
        $typeB = \App\Models\AccountType::create(['name' => 'نوع ب ' . uniqid()]);

        $accountA = $this->makeAccount($currency, ['account_type_id' => $typeA->id]);
        $accountB = $this->makeAccount($currency, ['account_type_id' => $typeB->id]);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $accountA, $currency, ['debit_base' => 400, 'credit_base' => 0]);
        $this->makeLine($transaction, $accountB, $currency, ['debit_base' => 0, 'credit_base' => 400]);

        $unfiltered = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);
        $filtered = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, $typeA->id, true);

        $this->assertCount(2, $unfiltered['rows']);
        $this->assertCount(1, $filtered['rows']);
        // The one remaining row's own debit/credit values are unchanged by filtering.
        $this->assertSame(400.0, $filtered['rows'][0]['total_debit']);
    }

    public function test_zero_balance_accounts_excluded_by_default_and_included_when_toggled(): void
    {
        $currency = $this->makeCurrency();
        $movedAccount = $this->makeAccount($currency);
        $zeroAccount = $this->makeAccount($currency);
        $other = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $movedAccount, $currency, ['debit_base' => 100, 'credit_base' => 0]);
        $this->makeLine($transaction, $other, $currency, ['debit_base' => 0, 'credit_base' => 100]);
        // $zeroAccount has no lines at all — a genuine zero-movement account.

        $excluded = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, false);
        $included = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);

        $this->assertNull(collect($excluded['rows'])->firstWhere('account_code', $zeroAccount->account_code));
        $this->assertNotNull(collect($included['rows'])->firstWhere('account_code', $zeroAccount->account_code));
    }

    public function test_soft_deleted_lines_transactions_and_accounts_are_excluded(): void
    {
        $currency = $this->makeCurrency();
        $liveAccount = $this->makeAccount($currency);
        $deletedAccount = $this->makeAccount($currency);
        $other = $this->makeAccount($currency);

        $liveTransaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($liveTransaction, $liveAccount, $currency, ['debit_base' => 100, 'credit_base' => 0]);
        $this->makeLine($liveTransaction, $other, $currency, ['debit_base' => 0, 'credit_base' => 100]);

        // A soft-deleted transaction with lines that would otherwise count.
        $deletedTransaction = $this->makeTransaction(['transaction_time' => '2026-07-11 10:00:00']);
        $this->makeLine($deletedTransaction, $liveAccount, $currency, ['debit_base' => 999, 'credit_base' => 0]);
        $this->makeLine($deletedTransaction, $other, $currency, ['debit_base' => 0, 'credit_base' => 999]);
        $deletedTransaction->delete();

        // A soft-deleted line on an otherwise-live transaction.
        $line = $this->makeLine($liveTransaction, $liveAccount, $currency, ['debit_base' => 555, 'credit_base' => 0]);
        $line->delete();

        // A soft-deleted account: excluded from the population entirely.
        $deletedAccount->delete();

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);

        $liveRow = collect($result['rows'])->firstWhere('account_code', $liveAccount->account_code);
        $this->assertSame(100.0, $liveRow['total_debit']);
        $this->assertNull(collect($result['rows'])->firstWhere('account_code', $deletedAccount->account_code));
    }

    public function test_page_totals_and_balanced_indicator_are_mathematically_consistent(): void
    {
        $currency = $this->makeCurrency();
        $a = $this->makeAccount($currency);
        $b = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $a, $currency, ['debit_base' => 250, 'credit_base' => 0]);
        $this->makeLine($transaction, $b, $currency, ['debit_base' => 0, 'credit_base' => 250]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);

        $sumDebit = array_sum(array_column($result['rows'], 'total_debit'));
        $sumCredit = array_sum(array_column($result['rows'], 'total_credit'));

        $this->assertSame($sumDebit, $result['grand_debit']);
        $this->assertSame($sumCredit, $result['grand_credit']);
        $this->assertSame($result['grand_debit'] - $result['grand_credit'], $result['difference']);
        $this->assertTrue($result['is_balanced']);
    }

    public function test_excel_export_renders_exactly_the_values_already_computed_by_the_page(): void
    {
        $currency = $this->makeCurrency();
        $a = $this->makeAccount($currency);
        $b = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00']);
        $this->makeLine($transaction, $a, $currency, ['debit_base' => 321.55, 'credit_base' => 0]);
        $this->makeLine($transaction, $b, $currency, ['debit_base' => 0, 'credit_base' => 321.55]);

        $result = $this->service()->generate('2026-07-01', '2026-07-31', $currency->id, null, true);

        $response = (new TrialBalanceExcelExportService())->stream(
            '2026-07-01', '2026-07-31', $result['currency_label'], $result['currency_code'], null,
            true, $result['rows'], $result['grand_debit'], $result['grand_credit'],
            $result['difference'], $result['is_balanced'], $result['accounts_count'],
        );

        $content = $this->captureStreamedContent($response);

        $tmp = tempnam(sys_get_temp_dir(), 'tb') . '.xlsx';
        file_put_contents($tmp, $content);

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $values = collect($sheet->toArray())->flatten()->filter(fn ($v) => $v !== null && $v !== '')->all();
        unlink($tmp);

        $this->assertContains('321.55', $values, 'Excel export must contain the exact page grand debit value.');
    }

    public function test_word_export_accepts_only_the_precomputed_values_with_no_independent_data_access(): void
    {
        // Deliberately synthetic values with NO matching database rows at
        // all — this can only succeed if the export truly never re-queries
        // the database, only renders what it was handed (matching the
        // service's own "no recalculation, no independent data access"
        // docblock guarantee).
        $syntheticRows = [[
            'account_code' => 'SYN-1',
            'account_name' => 'حساب اختباري',
            'account_type_name' => 'نوع',
            'currency_label' => 'عملة اختبارية',
            'total_debit' => 777.77,
            'total_credit' => 777.77,
            'balance' => 0.0,
            'nature' => 'متوازن',
        ]];

        $response = (new TrialBalanceWordExportService())->stream(
            '2026-07-01', '2026-07-31', 'عملة اختبارية', 'SYN', null,
            true, $syntheticRows, 777.77, 777.77, 0.0, true, 1,
        );

        $content = $this->captureStreamedContent($response);
        $this->assertNotEmpty($content);

        $tmp = tempnam(sys_get_temp_dir(), 'tb') . '.docx';
        file_put_contents($tmp, $content);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);

        $this->assertStringContainsString('777.77', $xml);
        $this->assertStringContainsString('حساب اختباري', $xml);
    }

    /**
     * A directly-constructed service response is a raw Symfony
     * StreamedResponse, not a Laravel TestResponse — streamedContent() only
     * exists on the latter. sendContent() triggers the same real
     * content-transmission callback the browser would invoke.
     */
    private function captureStreamedContent(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    /**
     * @return array{0: \App\Models\Currency, 1: \App\Models\Currency, 2: array<string, \App\Models\Account>, 3: \App\Models\Transaction}
     */
    private function makeExchangeFixture(): array
    {
        $sourceCurrency = $this->makeCurrency(['code' => 'USD-' . uniqid()]);
        $destinationCurrency = $this->makeCurrency(['code' => 'ILS-' . uniqid()]);

        $accounts = [
            'source' => $this->makeAccount($sourceCurrency),
            'admin' => $this->makeAccount($sourceCurrency),
            'transfer' => $this->makeAccount($sourceCurrency),
            'destination' => $this->makeAccount($destinationCurrency),
        ];

        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-15 10:00:00']);

        $this->makeLine($transaction, $accounts['source'], $sourceCurrency, [
            'amount_currency' => 1000, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 1000, 'line_role' => 'source',
        ]);
        $this->makeLine($transaction, $accounts['admin'], $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'administrative_deduction',
        ]);
        $this->makeLine($transaction, $accounts['transfer'], $sourceCurrency, [
            'amount_currency' => 0.01, 'fx_rate' => 1, 'debit_base' => 0.01, 'credit_base' => 0, 'line_role' => 'transfer_fee',
        ]);
        $this->makeLine($transaction, $accounts['destination'], $destinationCurrency, [
            'amount_currency' => 2999.94, 'fx_rate' => 3, 'debit_base' => 2999.94, 'credit_base' => 0, 'line_role' => 'destination',
        ]);

        return [$sourceCurrency, $destinationCurrency, $accounts, $transaction];
    }
}
