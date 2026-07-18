<?php

namespace Tests\Feature\GeneralExchanges;

use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Representative deduction/FX workflow: proves the FinancialAmountGuard
 * rejects an invalid combined percentage and an invalid FX rate before any
 * database mutation, and that valid values still complete successfully.
 * Uses the same schema-only SQLite approach as ExecutionPaymentCreditAccountTest.
 */
class GeneralExchangeAmountValidationTest extends TestCase
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

        $makeAccount = fn (string $name, float $balance = 0) => Account::create([
            'account_code' => $name, 'name' => $name, 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => $balance, 'is_active' => true,
        ]);

        $sourceAccount      = $makeAccount('مصدر', 5000);
        $adminAccount       = $makeAccount('إداري');
        $transferAccount    = $makeAccount('تحويل');
        $destinationAccount = $makeAccount('وجهة');

        $fiscalYear      = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType     = PartnerType::create(['name' => 'نوع شريك']);
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        return compact('currency', 'sourceAccount', 'adminAccount', 'transferAccount', 'destinationAccount', 'fiscalYear', 'transactionType', 'partner');
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'original_amount'            => 1000,
            'source_currency_id'         => $fx['currency']->id,
            'administrative_percentage'  => 10,
            'transfer_percentage'        => 10,
            'disbursement_currency_id'   => $fx['currency']->id,
            'fx_rate'                    => 1,
            'transaction_super_type_id'  => null,
            'transaction_type_id'        => $fx['transactionType']->id,
            'fiscal_year_id'             => $fx['fiscalYear']->id,
            'partner_id'                 => $fx['partner']->id,
            'date'                       => '2026-07-18',
            'notes'                      => null,
            'source_account_id'          => $fx['sourceAccount']->id,
            'admin_account_id'           => $fx['adminAccount']->id,
            'transfer_account_id'        => $fx['transferAccount']->id,
            'destination_account_id'     => $fx['destinationAccount']->id,
            'exchange_image'             => null,
        ], $overrides);
    }

    private function invokeCreate(array $data): GeneralExchange
    {
        $page   = new CreateGeneralExchange();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    public function test_create_rejects_combined_percentages_of_100_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        $transactionsBefore = Transaction::count();
        $linesBefore        = TransactionLine::count();

        try {
            $this->invokeCreate($this->baseData($fx, [
                'administrative_percentage' => 60,
                'transfer_percentage'       => 40,
            ]));
            $this->fail('Expected a ValidationException for combined percentages of 100.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transfer_percentage', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame(0, GeneralExchange::count());
        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }

    public function test_create_rejects_zero_fx_rate_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        try {
            $this->invokeCreate($this->baseData($fx, ['fx_rate' => 0]));
            $this->fail('Expected a ValidationException for a zero FX rate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fx_rate', $e->errors());
        }

        $this->assertSame(0, GeneralExchange::count());
    }

    public function test_create_rejects_negative_fx_rate_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        try {
            $this->invokeCreate($this->baseData($fx, ['fx_rate' => -1]));
            $this->fail('Expected a ValidationException for a negative FX rate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fx_rate', $e->errors());
        }

        $this->assertSame(0, GeneralExchange::count());
    }

    public function test_create_accepts_valid_percentages_and_fx_rate(): void
    {
        $fx       = $this->baseFixture();
        $exchange = $this->invokeCreate($this->baseData($fx));

        // original 1000, admin 10% (100), transfer 10% (100) -> after deductions 800, fx 1 -> final 800
        $this->assertEquals(800, (float) $exchange->final_amount);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance); // 5000 - 1000
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
    }
}
