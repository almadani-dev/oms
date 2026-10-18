<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionRebuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionVerifier;
use Illuminate\Support\Facades\DB;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * The rebuild's month enumeration must cover every calendar month between
 * the earliest and latest projection line, whatever the app timezone does
 * in between. A year-0025 transaction makes an Asia/Jerusalem cursor cross
 * the 1994-04-01 midnight DST gap, which used to drop the latest month.
 */
class ComprehensiveReportProjectionMonthRangeTest extends TestCase
{
    use IntegrityTestFixtures;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        $this->originalTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Jerusalem']);
        date_default_timezone_set('Asia/Jerusalem');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    /**
     * Balanced two-line transaction: $amount debit on $debit, credit on $credit.
     */
    private function seedTransaction(string $time, int $typeId, Currency $currency, Account $debit, Account $credit, float $amount, string $number): void
    {
        $tx = $this->makeTransaction(['transaction_time' => $time, 'transaction_type_id' => $typeId, 'transaction_number' => $number]);
        $this->makeLine($tx, $debit, $currency, ['debit_base' => $amount, 'credit_base' => 0]);
        $this->makeLine($tx, $credit, $currency, ['debit_base' => 0, 'credit_base' => $amount]);
    }

    /**
     * Empties the projection so the rebuild alone must produce every row,
     * as on a server whose live writes never touched those months.
     */
    private function wipeProjection(): void
    {
        DB::table(ComprehensiveReportProjection::LINES)->delete();
        DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->delete();
        DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS)->delete();
    }

    /**
     * @return array<int, mixed>|null [line_count, debit_total, credit_total]
     */
    private function totals(string $month, int $typeId, int $currencyId, int $accountTypeId): ?array
    {
        $row = DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)
            ->where('month', $month)
            ->where('transaction_type_id', $typeId)
            ->where('currency_id', $currencyId)
            ->where('account_type_id', $accountTypeId)
            ->first();

        return $row === null ? null : [
            (int) $row->line_count,
            ComprehensiveReportProjection::money($row->debit_total),
            ComprehensiveReportProjection::money($row->credit_total),
        ];
    }

    private function transactionCount(string $month, int $typeId): int
    {
        return (int) DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS)
            ->where('month', $month)
            ->where('transaction_type_id', $typeId)
            ->sum('transaction_count');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertVerifiedClean(array $result): void
    {
        $this->assertSame(0, $result['line_differences'], implode("\n", $result['samples']));
        $this->assertSame(0, $result['total_differences'], implode("\n", $result['samples']));
        $this->assertSame(0, $result['transaction_count_differences'], implode("\n", $result['samples']));
        $this->assertSame(0, $result['differences'], implode("\n", $result['samples']));
    }

    public function test_a_year_0025_transaction_does_not_drop_the_latest_month_in_asia_jerusalem(): void
    {
        $this->assertSame('Asia/Jerusalem', date_default_timezone_get());

        $currency = $this->makeCurrency(['name' => 'شيكل', 'code' => 'ILS']);
        $debitType = AccountType::create(['name' => 'نوع حساب مدين'])->id;
        $creditType = AccountType::create(['name' => 'نوع حساب دائن'])->id;
        $debit = $this->makeAccount($currency, ['account_type_id' => $debitType]);
        $credit = $this->makeAccount($currency, ['account_type_id' => $creditType]);
        $historicalType = $this->makeTransactionType()->id;
        $aprilType = $this->makeTransactionType()->id;

        // The historical date stays as it is: the rebuild must cope with it.
        $this->seedTransaction('0025-05-22 00:00:00', $historicalType, $currency, $debit, $credit, 50, 'GEN-0025-0001');
        $this->seedTransaction('2026-04-14 10:30:00', $aprilType, $currency, $debit, $credit, 337, 'GEN-2026-0001');

        $this->wipeProjection();
        $stats = app(ComprehensiveReportProjectionRebuilder::class)->rebuild();

        // 0025-05 .. 2026-04 inclusive: (2026 - 25) * 12 months.
        $this->assertSame(24012, $stats['months']);
        $this->assertTrue(app(ComprehensiveReportProjectionState::class)->isReady());

        // The latest month's aggregates exist.
        $this->assertSame([1, '337.00', '0.00'], $this->totals('2026-04-01', $aprilType, $currency->id, $debitType));
        $this->assertSame([1, '0.00', '337.00'], $this->totals('2026-04-01', $aprilType, $currency->id, $creditType));
        $this->assertSame(1, $this->transactionCount('2026-04-01', $aprilType));

        // So do the earliest month's.
        $this->assertSame([1, '50.00', '0.00'], $this->totals('0025-05-01', $historicalType, $currency->id, $debitType));
        $this->assertSame([1, '0.00', '50.00'], $this->totals('0025-05-01', $historicalType, $currency->id, $creditType));
        $this->assertSame(1, $this->transactionCount('0025-05-01', $historicalType));

        // Nothing else: only the two months holding lines have aggregates.
        $this->assertSame(['0025-05-01', '2026-04-01'], DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->distinct()->orderBy('month')->pluck('month')->map(fn ($m) => substr((string) $m, 0, 10))->all());
        $this->assertSame(['0025-05-01', '2026-04-01'], DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS)->distinct()->orderBy('month')->pluck('month')->map(fn ($m) => substr((string) $m, 0, 10))->all());

        // The historical transaction itself was not touched.
        $this->assertSame('0025-05-22 00:00:00', ComprehensiveReportProjection::time(DB::table('transactions')->where('transaction_number', 'GEN-0025-0001')->value('transaction_time')));

        $this->assertVerifiedClean(app(ComprehensiveReportProjectionVerifier::class)->verify());
    }

    public function test_a_normal_range_still_enumerates_every_month_including_empty_ones(): void
    {
        $currency = $this->makeCurrency(['name' => 'دولار', 'code' => 'USD']);
        $debitType = AccountType::create(['name' => 'نوع حساب مدين'])->id;
        $creditType = AccountType::create(['name' => 'نوع حساب دائن'])->id;
        $debit = $this->makeAccount($currency, ['account_type_id' => $debitType]);
        $credit = $this->makeAccount($currency, ['account_type_id' => $creditType]);
        $type = $this->makeTransactionType()->id;

        // A year boundary, an empty month (2026-02) and the last second of
        // the latest month; 2026-03 also holds Israel's DST start.
        $this->seedTransaction('2025-12-31 23:59:59', $type, $currency, $debit, $credit, 10, 'N-1');
        $this->seedTransaction('2026-01-01 00:00:00', $type, $currency, $debit, $credit, 20, 'N-2');
        $this->seedTransaction('2026-03-27 12:00:00', $type, $currency, $debit, $credit, 30, 'N-3');
        $this->seedTransaction('2026-04-30 23:59:59', $type, $currency, $debit, $credit, 40, 'N-4');

        $this->wipeProjection();
        $stats = app(ComprehensiveReportProjectionRebuilder::class)->rebuild();

        // 2025-12, 2026-01, 2026-02 (empty), 2026-03, 2026-04.
        $this->assertSame(5, $stats['months']);

        $this->assertSame([1, '10.00', '0.00'], $this->totals('2025-12-01', $type, $currency->id, $debitType));
        $this->assertSame([1, '20.00', '0.00'], $this->totals('2026-01-01', $type, $currency->id, $debitType));
        $this->assertNull($this->totals('2026-02-01', $type, $currency->id, $debitType));
        $this->assertSame([1, '30.00', '0.00'], $this->totals('2026-03-01', $type, $currency->id, $debitType));
        $this->assertSame([1, '0.00', '40.00'], $this->totals('2026-04-01', $type, $currency->id, $creditType));
        $this->assertSame(1, $this->transactionCount('2026-04-01', $type));

        $this->assertVerifiedClean(app(ComprehensiveReportProjectionVerifier::class)->verify());
    }
}
