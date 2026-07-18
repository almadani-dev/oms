<?php

namespace Tests\Feature\ProjectCostBudgetsPayments;

use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Representative dual-currency, four-account workflow (source/admin/transfer
 * in the cost currency, destination in the disbursement currency). Uses the
 * same schema-only SQLite approach as ExecutionPaymentCreditAccountTest.
 */
class ProjectCostBudgetsPaymentAccountValidationTest extends TestCase
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

        $super       = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status      = ProjectStatus::create(['name' => 'نشط']);
        $project     = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        return compact('currency', 'accountType', 'bankType', 'sourceAccount', 'adminAccount', 'transferAccount', 'destinationAccount', 'fiscalYear', 'transactionType', 'partner', 'projectCost');
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'project_cost_id'            => $fx['projectCost']->id,
            'original_amount'            => 1000,
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
            'source_account_type_id'     => $fx['sourceAccount']->account_type_id,
            'source_bank_type_id'        => $fx['sourceAccount']->bank_type_id,
            'admin_account_id'           => $fx['adminAccount']->id,
            'admin_account_type_id'      => $fx['adminAccount']->account_type_id,
            'admin_bank_type_id'         => $fx['adminAccount']->bank_type_id,
            'transfer_account_id'        => $fx['transferAccount']->id,
            'transfer_account_type_id'   => $fx['transferAccount']->account_type_id,
            'transfer_bank_type_id'      => $fx['transferAccount']->bank_type_id,
            'destination_account_id'     => $fx['destinationAccount']->id,
            'destination_account_type_id' => $fx['destinationAccount']->account_type_id,
            'destination_bank_type_id'   => $fx['destinationAccount']->bank_type_id,
            'payment_image'              => null,
        ], $overrides);
    }

    private function invokeCreate(array $data): ProjectCostBudget
    {
        $page   = new CreateProjectCostBudgetsPayment();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(ProjectCostBudget $record): array
    {
        $page         = new EditProjectCostBudgetsPayment();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        return $method->invoke($page, []);
    }

    private function invokeUpdate(ProjectCostBudget $record, array $data): ProjectCostBudget
    {
        $page   = new EditProjectCostBudgetsPayment();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    public function test_create_rejects_destination_account_in_the_source_currency(): void
    {
        $fx  = $this->baseFixture();
        $eur = Currency::create(['name' => 'EUR', 'code' => 'EUR', 'symbol' => 'EUR']);

        $data = $this->baseData($fx, ['disbursement_currency_id' => $eur->id]);
        // destination_account_id still points at the USD (source-currency) account.

        try {
            $this->invokeCreate($data);
            $this->fail('Expected a ValidationException for a destination account in the wrong currency.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('destination_account_id', $e->errors());
        }

        $this->assertSame(0, ProjectCostBudget::whereNotNull('transaction_id')->count());
        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }

    /**
     * Approved business rule: the same account may legally fill multiple roles.
     */
    public function test_create_accepts_the_same_account_for_all_four_roles(): void
    {
        $fx      = $this->baseFixture();
        $account = $fx['sourceAccount'];

        $payment = $this->invokeCreate($this->baseData($fx, [
            'admin_account_id' => $account->id, 'admin_account_type_id' => $account->account_type_id, 'admin_bank_type_id' => $account->bank_type_id,
            'transfer_account_id' => $account->id, 'transfer_account_type_id' => $account->account_type_id, 'transfer_bank_type_id' => $account->bank_type_id,
            'destination_account_id' => $account->id, 'destination_account_type_id' => $account->account_type_id, 'destination_bank_type_id' => $account->bank_type_id,
        ]));

        // -1000 (source) +100 (admin) +100 (transfer) +800 (destination) = 0 net
        $this->assertEquals(5000, (float) $account->fresh()->current_balance);
        $this->assertNotNull($payment->id);
    }

    public function test_create_rejects_inactive_source_account(): void
    {
        $fx = $this->baseFixture();
        $fx['sourceAccount']->update(['is_active' => false]);

        try {
            $this->invokeCreate($this->baseData($fx));
            $this->fail('Expected a ValidationException for an inactive source account on Create.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source_account_id', $e->errors());
        }
    }

    public function test_create_accepts_valid_accounts(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertNotNull($payment->id);
    }

    public function test_edit_allows_unchanged_historical_accounts_even_if_now_inactive(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $fx['sourceAccount']->update(['is_active' => false]);
        $fx['destinationAccount']->update(['is_active' => false]);

        $hydrated = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['notes'] = 'ملاحظة محدثة فقط';

        $updated = $this->invokeUpdate($payment->fresh(), $hydrated);

        $this->assertSame('ملاحظة محدثة فقط', $updated->notes);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_edit_rejects_switching_to_a_newly_selected_inactive_account(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $inactiveSource = Account::create([
            'account_code' => 'مصدر-غير-نشط', 'name' => 'مصدر غير نشط', 'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id, 'currency_id' => $fx['currency']->id,
            'current_balance' => 0, 'is_active' => false,
        ]);

        $hydrated = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['source_account_id']      = $inactiveSource->id;
        $hydrated['source_account_type_id'] = $inactiveSource->account_type_id;
        $hydrated['source_bank_type_id']    = $inactiveSource->bank_type_id;

        try {
            $this->invokeUpdate($payment->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for switching to a newly-selected inactive source account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source_account_id', $e->errors());
        }

        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
    }
}
