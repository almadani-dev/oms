<?php

namespace Tests\Feature\ProjectCostReceipts;

use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
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
 * Representative "simple two-account, project-cost-currency" workflow. Uses
 * the same schema-only SQLite approach as ExecutionPaymentCreditAccountTest.
 */
class ProjectCostReceiptAccountValidationTest extends TestCase
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
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id, 'is_donor' => true]);

        $super  = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status = ProjectStatus::create(['name' => 'نشط']);
        $project = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        return compact('currency', 'accountType', 'bankType', 'debitAccount', 'creditAccount', 'fiscalYear', 'transactionType', 'partner', 'projectCost');
    }

    private function baseData(array $fx, ?Account $debit = null, ?Account $credit = null, array $overrides = []): array
    {
        $debit  = $debit ?? $fx['debitAccount'];
        $credit = $credit ?? $fx['creditAccount'];

        return array_merge([
            'project_cost_id'            => $fx['projectCost']->id,
            'amount'                     => 250,
            'partner_id'                 => $fx['partner']->id,
            'date'                       => '2026-07-18',
            'transaction_super_type_id'  => null,
            'transaction_type_id'        => $fx['transactionType']->id,
            'fiscal_year_id'             => $fx['fiscalYear']->id,
            'notes'                      => null,
            'debit_account_id'           => $debit->id,
            'debit_account_type_id'      => $debit->account_type_id,
            'debit_bank_type_id'         => $debit->bank_type_id,
            'credit_account_id'          => $credit->id,
            'credit_account_type_id'     => $credit->account_type_id,
            'credit_bank_type_id'        => $credit->bank_type_id,
            'receipt_image'              => null,
        ], $overrides);
    }

    private function invokeCreate(array $data): ProjectCostReceipt
    {
        $page   = new CreateProjectCostReceipt();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(ProjectCostReceipt $record): array
    {
        $page         = new EditProjectCostReceipt();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        // In real Filament usage, EditRecord fills $data from the model's own
        // attributes (e.g. 'amount', 'project_cost_id', 'date') before this
        // hook runs; the hook itself only adds the extra computed/cascade
        // fields. Reproduce that base hydration since this test calls the
        // hook directly.
        return $method->invoke($page, $record->toArray());
    }

    private function invokeUpdate(ProjectCostReceipt $record, array $data): ProjectCostReceipt
    {
        $page   = new EditProjectCostReceipt();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    public function test_create_rejects_inactive_account(): void
    {
        $fx = $this->baseFixture();
        $fx['creditAccount']->update(['is_active' => false]);

        try {
            $this->invokeCreate($this->baseData($fx));
            $this->fail('Expected a ValidationException for an inactive account on Create.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        $this->assertSame(0, ProjectCostReceipt::count());
    }

    public function test_create_rejects_account_in_another_currency(): void
    {
        $fx  = $this->baseFixture();
        $eur = Currency::create(['name' => 'EUR', 'code' => 'EUR', 'symbol' => 'EUR']);
        $eurAccount = Account::create([
            'account_code' => 'يورو', 'name' => 'يورو', 'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id, 'currency_id' => $eur->id, 'current_balance' => 0, 'is_active' => true,
        ]);

        try {
            $this->invokeCreate($this->baseData($fx, debit: $eurAccount));
            $this->fail('Expected a ValidationException for a cross-currency account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }

        $this->assertSame(0, ProjectCostReceipt::count());
    }

    public function test_create_accepts_the_same_account_for_debit_and_credit(): void
    {
        $fx      = $this->baseFixture();
        $account = $fx['debitAccount'];

        $receipt = $this->invokeCreate($this->baseData($fx, debit: $account, credit: $account));

        $this->assertNotNull($receipt->id);
        $this->assertEquals(0, (float) $account->fresh()->current_balance); // +250 then -250
    }

    public function test_create_accepts_valid_account(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
        $this->assertNotNull($receipt->id);
    }

    public function test_edit_allows_unchanged_historical_account_even_if_now_inactive(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $fx['creditAccount']->update(['is_active' => false]);

        $hydrated = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydrated['notes'] = 'ملاحظة محدثة فقط';

        $updated = $this->invokeUpdate($receipt->fresh(), $hydrated);

        $this->assertSame('ملاحظة محدثة فقط', $updated->notes);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    public function test_edit_rejects_switching_to_a_newly_selected_inactive_account(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $inactiveAccount = Account::create([
            'account_code' => 'غير-نشط', 'name' => 'غير نشط', 'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id, 'currency_id' => $fx['currency']->id,
            'current_balance' => 0, 'is_active' => false,
        ]);

        $hydrated = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydrated['credit_account_id']      = $inactiveAccount->id;
        $hydrated['credit_account_type_id'] = $inactiveAccount->account_type_id;
        $hydrated['credit_bank_type_id']    = $inactiveAccount->bank_type_id;

        try {
            $this->invokeUpdate($receipt->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for switching to a newly-selected inactive account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
    }
}
