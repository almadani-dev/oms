<?php

namespace Tests\Feature\GeneralExpenses;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExpense;
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
 * Representative "simple amount" workflow: proves the FinancialAmountGuard
 * rejects an invalid submitted amount before any database mutation, and that
 * a valid amount still completes successfully. Uses the same schema-only
 * SQLite approach as ExecutionPaymentCreditAccountTest.
 */
class GeneralExpenseAmountValidationTest extends TestCase
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
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        return compact('currency', 'debitAccount', 'creditAccount', 'fiscalYear', 'transactionType', 'partner');
    }

    private function baseData(array $fx, float $amount): array
    {
        return [
            'amount'                     => $amount,
            'currency_id'                => $fx['currency']->id,
            'partner_id'                 => $fx['partner']->id,
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
        ];
    }

    private function invokeCreate(array $data): GeneralExpense
    {
        $page   = new CreateGeneralExpense();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    public function test_create_rejects_zero_amount_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        $transactionsBefore = Transaction::count();
        $linesBefore        = TransactionLine::count();

        try {
            $this->invokeCreate($this->baseData($fx, 0));
            $this->fail('Expected a ValidationException for a zero amount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame(0, GeneralExpense::count());
        $this->assertEquals(0, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(5000, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    public function test_create_rejects_negative_amount_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        try {
            $this->invokeCreate($this->baseData($fx, -50));
            $this->fail('Expected a ValidationException for a negative amount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(0, GeneralExpense::count());
    }

    public function test_create_accepts_valid_amount(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx, 250));

        $this->assertEquals(250, (float) $expense->amount);
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
    }
}
