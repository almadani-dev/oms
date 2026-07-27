<?php

namespace Tests\Feature\Commands;

use App\Services\Integrity\FinancialIntegrityChecker;
use App\Services\Integrity\IntegrityCheckReport;
use Illuminate\Support\Facades\DB;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

class CheckFinancialIntegrityCommandTest extends TestCase
{
    use IntegrityTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
    }

    public function test_clean_database_exits_zero(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency, ['current_balance' => 100]);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency, [
            'debit_base' => 100, 'credit_base' => 0, 'line_role' => 'expense',
        ]);
        $account2 = $this->makeAccount($currency, ['current_balance' => -100]);
        $this->makeLine($transaction, $account2, $currency, [
            'debit_base' => 0, 'credit_base' => 100, 'line_role' => 'source',
        ]);

        $this->artisan('oms:check-financial-integrity')
            ->assertExitCode(0)
            ->expectsOutputToContain('Result: OK');
    }

    public function test_violation_exits_one(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();
        $line = $this->makeLine($transaction, $account, $currency);

        DB::table('accounts')->where('id', $account->id)->delete();

        $this->artisan('oms:check-financial-integrity')
            ->assertExitCode(1)
            ->expectsOutputToContain('VIOLATIONS FOUND');
    }

    public function test_runtime_failure_exits_two(): void
    {
        $this->mock(FinancialIntegrityChecker::class, function ($mock) {
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('simulated checker failure'));
        });

        $this->artisan('oms:check-financial-integrity')
            ->assertExitCode(2);
    }

    public function test_json_mode_produces_valid_bounded_json(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction();
        $line = $this->makeLine($transaction, $account, $currency);
        DB::table('accounts')->where('id', $account->id)->delete();

        $exitCode = \Illuminate\Support\Facades\Artisan::call('oms:check-financial-integrity', ['--json' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $exitCode);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('VIOLATIONS_FOUND', $decoded['result']);
        $this->assertNotEmpty($decoded['violations']);

        foreach ($decoded['violations'] as $violation) {
            $this->assertLessThanOrEqual(10, count($violation['sample_ids']));
        }
    }

    public function test_command_never_mutates_any_row(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency, ['current_balance' => 999]);
        $transaction = $this->makeTransaction();
        $this->makeLine($transaction, $account, $currency, [
            'debit_base' => 500, 'credit_base' => 0,
        ]);

        $beforeAccount = DB::table('accounts')->where('id', $account->id)->first();
        $beforeLineCount = DB::table('transaction_lines')->count();
        $beforeTransactionCount = DB::table('transactions')->count();

        $this->artisan('oms:check-financial-integrity')->assertExitCode(1);

        $afterAccount = DB::table('accounts')->where('id', $account->id)->first();
        $this->assertEquals($beforeAccount, $afterAccount);
        $this->assertSame($beforeLineCount, DB::table('transaction_lines')->count());
        $this->assertSame($beforeTransactionCount, DB::table('transactions')->count());
    }
}
