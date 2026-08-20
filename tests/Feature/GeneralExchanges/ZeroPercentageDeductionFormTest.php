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
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The General Exchange mirror of
 * Tests\Feature\ProjectCostBudgetsPayments\ZeroPercentageDeductionFormTest.
 *
 * Every other EXT test drives the page methods through reflection, which
 * never builds the form schema — so without this class the restructured
 * four-card `الحسابات` section, the conditional `required()` rules and the
 * percentage-driven clearing would be entirely unrendered by the suite.
 */
class ZeroPercentageDeductionFormTest extends TestCase
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

        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        URL::forceRootUrl('http://localhost');

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($user);
    }

    private function fixture(): array
    {
        $currency    = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType    = BankType::create(['name' => 'نوع بنك']);

        $makeAccount = fn (string $name, float $balance = 0) => Account::create([
            'account_code' => $name, 'name' => $name, 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id,
            'current_balance' => $balance, 'is_active' => true,
        ]);

        $sourceAccount      = $makeAccount('مصدر', 5000);
        $adminAccount       = $makeAccount('إداري');
        $transferAccount    = $makeAccount('تحويل');
        $destinationAccount = $makeAccount('وجهة');

        $fiscalYear      = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $superType       = TransactionSuperType::create(['name' => 'تصنيف معاملة']);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة', 'transaction_super_type_id' => $superType->id]);
        $partnerType     = PartnerType::create(['name' => 'نوع شريك']);
        $partner         = Partner::create(['name' => 'شريك', 'partner_type_id' => $partnerType->id]);

        return compact(
            'currency', 'sourceAccount', 'adminAccount', 'transferAccount', 'destinationAccount',
            'fiscalYear', 'superType', 'transactionType', 'partner'
        );
    }

    private function formState(array $fx, array $overrides = []): array
    {
        return array_merge([
            'original_amount'             => 1000,
            'source_currency_id'          => $fx['currency']->id,
            'administrative_percentage'   => 10,
            'transfer_percentage'         => 10,
            'disbursement_currency_id'    => $fx['currency']->id,
            'fx_rate'                     => 1,
            'source_account_type_id'      => $fx['sourceAccount']->account_type_id,
            'source_bank_type_id'         => $fx['sourceAccount']->bank_type_id,
            'source_account_id'           => $fx['sourceAccount']->id,
            'admin_account_type_id'       => $fx['adminAccount']->account_type_id,
            'admin_bank_type_id'          => $fx['adminAccount']->bank_type_id,
            'admin_account_id'            => $fx['adminAccount']->id,
            'transfer_account_type_id'    => $fx['transferAccount']->account_type_id,
            'transfer_bank_type_id'       => $fx['transferAccount']->bank_type_id,
            'transfer_account_id'         => $fx['transferAccount']->id,
            'destination_account_type_id' => $fx['destinationAccount']->account_type_id,
            'destination_bank_type_id'    => $fx['destinationAccount']->bank_type_id,
            'destination_account_id'      => $fx['destinationAccount']->id,
            'transaction_super_type_id'   => $fx['superType']->id,
            'transaction_type_id'         => $fx['transactionType']->id,
            'fiscal_year_id'              => $fx['fiscalYear']->id,
            'partner_id'                  => $fx['partner']->id,
            'date'                        => '2026-07-18',
        ], $overrides);
    }

    public function test_the_form_renders(): void
    {
        Livewire::test(CreateGeneralExchange::class)->assertOk();
    }

    public function test_setting_a_percentage_to_zero_clears_only_its_own_account_cascade(): void
    {
        $fx = $this->fixture();

        Livewire::test(CreateGeneralExchange::class)
            ->fillForm($this->formState($fx))
            ->fillForm(['administrative_percentage' => 0])
            ->assertFormSet([
                'admin_account_type_id' => null,
                'admin_bank_type_id'    => null,
                'admin_account_id'      => null,
            ])
            ->assertFormSet([
                'source_account_id'      => $fx['sourceAccount']->id,
                'transfer_account_id'    => $fx['transferAccount']->id,
                'destination_account_id' => $fx['destinationAccount']->id,
            ]);
    }

    public function test_raising_a_percentage_back_above_zero_does_not_restore_the_previous_account(): void
    {
        $fx = $this->fixture();

        Livewire::test(CreateGeneralExchange::class)
            ->fillForm($this->formState($fx))
            ->fillForm(['transfer_percentage' => 0])
            ->assertFormSet(['transfer_account_id' => null])
            ->fillForm(['transfer_percentage' => 5])
            ->assertFormSet([
                'transfer_account_type_id' => null,
                'transfer_bank_type_id'    => null,
                'transfer_account_id'      => null,
            ]);
    }

    public function test_the_calculate_action_still_reports_a_zero_deduction_amount_as_zero(): void
    {
        $fx = $this->fixture();

        Livewire::test(CreateGeneralExchange::class)
            ->fillForm($this->formState($fx, ['administrative_percentage' => 0]))
            ->callFormComponentAction('deduction_calculator', 'calculate')
            ->assertFormSet([
                'administrative_amount'   => '0.00',
                'transfer_amount'         => '100.00',
                'amount_after_deductions' => '900.00',
                'final_amount'            => '900.00',
            ]);
    }

    public function test_a_real_submission_with_zero_percentages_and_no_deduction_accounts_saves(): void
    {
        $fx = $this->fixture();

        Livewire::test(CreateGeneralExchange::class)
            ->fillForm($this->formState($fx, [
                'administrative_percentage' => 0,
                'transfer_percentage'       => 0,
                'admin_account_type_id'     => null,
                'admin_bank_type_id'        => null,
                'admin_account_id'          => null,
                'transfer_account_type_id'  => null,
                'transfer_bank_type_id'     => null,
                'transfer_account_id'       => null,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $exchange = GeneralExchange::firstOrFail();

        $this->assertSame(2, $exchange->transaction->lines()->count());
        $this->assertSame(
            ['destination', 'source'],
            $exchange->transaction->lines()->pluck('line_role')->sort()->values()->all(),
        );
        $this->assertEquals(1000, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
    }

    public function test_a_real_submission_with_a_positive_percentage_still_requires_its_account(): void
    {
        $fx = $this->fixture();

        Livewire::test(CreateGeneralExchange::class)
            ->fillForm($this->formState($fx, [
                'transfer_percentage'      => 10,
                'transfer_account_type_id' => null,
                'transfer_bank_type_id'    => null,
                'transfer_account_id'      => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['transfer_account_type_id', 'transfer_bank_type_id', 'transfer_account_id']);

        $this->assertSame(0, GeneralExchange::count());
        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }
}
