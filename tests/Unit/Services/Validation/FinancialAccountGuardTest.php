<?php

namespace Tests\Unit\Services\Validation;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Services\Validation\FinancialAccountGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as ExecutionPaymentCreditAccountTest:
 * every real migration is applied, per test, to a fresh :memory: connection,
 * except the two MySQL-only raw-SQL migrations.
 */
class FinancialAccountGuardTest extends TestCase
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
    }

    private ?Currency $defaultCurrency = null;

    private ?AccountType $defaultAccountType = null;

    private ?BankType $defaultBankType = null;

    /**
     * Reused across calls within a test (unless explicitly overridden) so that
     * repeated makeAccount() calls in the same test don't collide on the
     * unique currencies.code column.
     */
    private function makeAccount(array $overrides = []): Account
    {
        $currency    = $overrides['currency'] ?? ($this->defaultCurrency ??= Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']));
        $accountType = $overrides['account_type'] ?? ($this->defaultAccountType ??= AccountType::create(['name' => 'نوع حساب']));
        $bankType    = $overrides['bank_type'] ?? ($this->defaultBankType ??= BankType::create(['name' => 'نوع بنك']));

        return Account::create([
            'account_code'    => 'حساب-' . uniqid(),
            'name'            => 'حساب تجريبي',
            'account_type_id' => $accountType->id,
            'bank_type_id'    => $bankType->id,
            'currency_id'     => $currency->id,
            'current_balance' => 0,
            'is_active'       => $overrides['is_active'] ?? true,
        ]);
    }

    // ---- assertAccountMatches --------------------------------------------

    public function test_nonexistent_account_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        FinancialAccountGuard::assertAccountMatches(999999, 1, 1, 1, 'debit_account_id', 'الحساب المدين');
    }

    public function test_soft_deleted_account_is_rejected(): void
    {
        $account = $this->makeAccount();
        $account->delete();

        try {
            FinancialAccountGuard::assertAccountMatches(
                $account->id, $account->account_type_id, $account->bank_type_id, $account->currency_id,
                'debit_account_id', 'الحساب المدين'
            );
            $this->fail('Expected a ValidationException for a soft-deleted account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }
    }

    public function test_account_type_mismatch_is_rejected(): void
    {
        $account       = $this->makeAccount();
        $otherType     = AccountType::create(['name' => 'نوع آخر']);

        try {
            FinancialAccountGuard::assertAccountMatches(
                $account->id, $otherType->id, $account->bank_type_id, $account->currency_id,
                'debit_account_id', 'الحساب المدين'
            );
            $this->fail('Expected a ValidationException for an account-type mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }
    }

    public function test_bank_type_mismatch_is_rejected(): void
    {
        $account   = $this->makeAccount();
        $otherBank = BankType::create(['name' => 'بنك آخر']);

        try {
            FinancialAccountGuard::assertAccountMatches(
                $account->id, $account->account_type_id, $otherBank->id, $account->currency_id,
                'debit_account_id', 'الحساب المدين'
            );
            $this->fail('Expected a ValidationException for a bank-type mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $account      = $this->makeAccount();
        $otherCurrency = Currency::create(['name' => 'EUR', 'code' => 'EUR', 'symbol' => 'EUR']);

        try {
            FinancialAccountGuard::assertAccountMatches(
                $account->id, $account->account_type_id, $account->bank_type_id, $otherCurrency->id,
                'debit_account_id', 'الحساب المدين'
            );
            $this->fail('Expected a ValidationException for a currency mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }
    }

    public function test_inactive_account_is_rejected_when_active_required(): void
    {
        $account = $this->makeAccount(['is_active' => false]);

        try {
            FinancialAccountGuard::assertAccountMatches(
                $account->id, $account->account_type_id, $account->bank_type_id, $account->currency_id,
                'debit_account_id', 'الحساب المدين', true
            );
            $this->fail('Expected a ValidationException for an inactive account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }
    }

    public function test_inactive_account_is_accepted_when_active_not_required(): void
    {
        $account = $this->makeAccount(['is_active' => false]);

        $result = FinancialAccountGuard::assertAccountMatches(
            $account->id, $account->account_type_id, $account->bank_type_id, $account->currency_id,
            'debit_account_id', 'الحساب المدين', false
        );

        $this->assertSame($account->id, $result->id);
    }

    public function test_fully_valid_account_is_accepted(): void
    {
        $account = $this->makeAccount();

        $result = FinancialAccountGuard::assertAccountMatches(
            $account->id, $account->account_type_id, $account->bank_type_id, $account->currency_id,
            'debit_account_id', 'الحساب المدين'
        );

        $this->assertSame($account->id, $result->id);
    }

    // ---- assertAccounts (batch) ------------------------------------------

    public function test_assert_accounts_rejects_on_first_invalid_spec_with_its_own_field(): void
    {
        $good = $this->makeAccount();
        $bad  = $this->makeAccount(['is_active' => false]);

        try {
            FinancialAccountGuard::assertAccounts([
                'debit' => [
                    'account_id' => $good->id, 'account_type_id' => $good->account_type_id,
                    'bank_type_id' => $good->bank_type_id, 'currency_id' => $good->currency_id,
                    'field' => 'debit_account_id', 'label' => 'الحساب المدين',
                ],
                'credit' => [
                    'account_id' => $bad->id, 'account_type_id' => $bad->account_type_id,
                    'bank_type_id' => $bad->bank_type_id, 'currency_id' => $bad->currency_id,
                    'field' => 'credit_account_id', 'label' => 'الحساب الدائن',
                ],
            ]);
            $this->fail('Expected a ValidationException for the inactive credit account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }
    }

    public function test_assert_accounts_returns_verified_accounts_keyed_by_role(): void
    {
        $debit  = $this->makeAccount();
        $credit = $this->makeAccount();

        $result = FinancialAccountGuard::assertAccounts([
            'debit' => [
                'account_id' => $debit->id, 'account_type_id' => $debit->account_type_id,
                'bank_type_id' => $debit->bank_type_id, 'currency_id' => $debit->currency_id,
                'field' => 'debit_account_id', 'label' => 'الحساب المدين',
            ],
            'credit' => [
                'account_id' => $credit->id, 'account_type_id' => $credit->account_type_id,
                'bank_type_id' => $credit->bank_type_id, 'currency_id' => $credit->currency_id,
                'field' => 'credit_account_id', 'label' => 'الحساب الدائن',
            ],
        ]);

        $this->assertSame($debit->id, $result['debit']->id);
        $this->assertSame($credit->id, $result['credit']->id);
    }

    /**
     * Approved business rule: the same account is allowed on both sides of an
     * operation - assertAccounts must not reject a spec set where two roles
     * share the same account_id.
     */
    public function test_assert_accounts_accepts_the_same_account_on_both_sides(): void
    {
        $account = $this->makeAccount();

        $result = FinancialAccountGuard::assertAccounts([
            'debit' => [
                'account_id' => $account->id, 'account_type_id' => $account->account_type_id,
                'bank_type_id' => $account->bank_type_id, 'currency_id' => $account->currency_id,
                'field' => 'debit_account_id', 'label' => 'الحساب المدين',
            ],
            'credit' => [
                'account_id' => $account->id, 'account_type_id' => $account->account_type_id,
                'bank_type_id' => $account->bank_type_id, 'currency_id' => $account->currency_id,
                'field' => 'credit_account_id', 'label' => 'الحساب الدائن',
            ],
        ]);

        $this->assertSame($account->id, $result['debit']->id);
        $this->assertSame($account->id, $result['credit']->id);
    }

    // ---- requireActiveOnChange --------------------------------------------

    public function test_require_active_on_change_is_false_when_unchanged(): void
    {
        $this->assertFalse(FinancialAccountGuard::requireActiveOnChange(5, 5));
        $this->assertFalse(FinancialAccountGuard::requireActiveOnChange(5, '5'));
    }

    public function test_require_active_on_change_is_true_when_changed(): void
    {
        $this->assertTrue(FinancialAccountGuard::requireActiveOnChange(5, 6));
        $this->assertTrue(FinancialAccountGuard::requireActiveOnChange(null, 6));
    }
}
