<?php

namespace Tests\Feature\ProjectCostBudgetsPayments;

use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditEvent;
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
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A 0% administrative or transfer deduction is a VALID business case for
 * صرف مبلغ المشروع, and it produces NO transaction line for that role — never
 * a line carrying debit_base = credit_base = amount_currency = 0, which is
 * meaningless accounting that FinancialTransactionBalanceGuard rejects
 * outright and must keep rejecting.
 *
 * This class pins the whole consequence of that rule for the disbursement
 * workflow: the 2/3/4-line matrix, the roles present in each shape, the
 * account balances that move (and the ones that must not), the account
 * validation that is skipped for an inactive deduction, the single audit
 * event and the account roles its snapshot carries, and every
 * zero <-> positive edit transition in both directions.
 *
 * Uses the same schema-only SQLite approach as BalanceGuardIntegrationTest.
 */
class ZeroPercentageDeductionTest extends TestCase
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

    /* =====================================================================
     | Fixture
     ===================================================================== */

    private function baseFixture(): array
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
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType     = PartnerType::create(['name' => 'نوع شريك']);
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        $super       = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status      = ProjectStatus::create(['name' => 'نشط']);
        $project     = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        return compact(
            'currency', 'accountType', 'bankType', 'sourceAccount', 'adminAccount',
            'transferAccount', 'destinationAccount', 'fiscalYear', 'transactionType', 'partner', 'projectCost'
        );
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'project_cost_id'             => $fx['projectCost']->id,
            'original_amount'             => 1000,
            'administrative_percentage'   => 10,
            'transfer_percentage'         => 10,
            'disbursement_currency_id'    => $fx['currency']->id,
            'fx_rate'                     => 1,
            'transaction_super_type_id'   => null,
            'transaction_type_id'         => $fx['transactionType']->id,
            'fiscal_year_id'              => $fx['fiscalYear']->id,
            'partner_id'                  => $fx['partner']->id,
            'date'                        => '2026-07-18',
            'notes'                       => null,
            'source_account_id'           => $fx['sourceAccount']->id,
            'source_account_type_id'      => $fx['sourceAccount']->account_type_id,
            'source_bank_type_id'         => $fx['sourceAccount']->bank_type_id,
            'admin_account_id'            => $fx['adminAccount']->id,
            'admin_account_type_id'       => $fx['adminAccount']->account_type_id,
            'admin_bank_type_id'          => $fx['adminAccount']->bank_type_id,
            'transfer_account_id'         => $fx['transferAccount']->id,
            'transfer_account_type_id'    => $fx['transferAccount']->account_type_id,
            'transfer_bank_type_id'       => $fx['transferAccount']->bank_type_id,
            'destination_account_id'      => $fx['destinationAccount']->id,
            'destination_account_type_id' => $fx['destinationAccount']->account_type_id,
            'destination_bank_type_id'    => $fx['destinationAccount']->bank_type_id,
            'payment_image'               => null,
        ], $overrides);
    }

    /**
     * The submitted payload a REAL disabled deduction card produces: the
     * percentage is 0 and every field of that account cascade is absent from
     * $data entirely (a disabled Filament field is not dehydrated, and the
     * percentage hook clears the state anyway). Proving the server copes with
     * the keys being MISSING — not merely null — is the point.
     */
    private function withoutRoleKeys(array $data, string $role): array
    {
        unset($data["{$role}_account_id"], $data["{$role}_account_type_id"], $data["{$role}_bank_type_id"]);

        return $data;
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
        $page         = new EditProjectCostBudgetsPayment();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    /** @return array<int, string> the line_role values on the record's transaction, sorted */
    private function roles(ProjectCostBudget $budget): array
    {
        return $budget->fresh()->transaction->lines()
            ->pluck('line_role')->sort()->values()->all();
    }

    private function assertNoZeroValueLines(ProjectCostBudget $budget): void
    {
        foreach ($budget->fresh()->transaction->lines as $line) {
            $this->assertTrue(
                (float) $line->debit_base > 0 || (float) $line->credit_base > 0,
                "line_role {$line->line_role} was written with both sides zero",
            );
            $this->assertGreaterThan(0, (float) $line->amount_currency);
        }
    }

    /* =====================================================================
     | CREATE — the 2/3/4-line matrix
     ===================================================================== */

    public function test_create_with_both_deductions_writes_four_lines(): void
    {
        $fx     = $this->baseFixture();
        $budget = $this->invokeCreate($this->baseData($fx));

        $this->assertSame(4, $budget->transaction->lines()->count());
        $this->assertSame(
            ['administrative_deduction', 'destination', 'source', 'transfer_fee'],
            $this->roles($budget),
        );
        $this->assertNoZeroValueLines($budget);

        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_create_with_zero_administrative_percentage_writes_three_lines_and_no_admin_role(): void
    {
        $fx = $this->baseFixture();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $this->assertSame(3, $budget->transaction->lines()->count());
        $this->assertSame(['destination', 'source', 'transfer_fee'], $this->roles($budget));
        $this->assertNoZeroValueLines($budget);

        $this->assertNull(
            $budget->transaction->lines()->where('notes', ProjectCostBudget::LINE_ADMIN)->first(),
            'a 0% administrative deduction must not write an administrative line',
        );

        // 1000 - 0 - 100 = 900 net, fx 1.
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance, 'the admin account must not move at all');
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $budget->amount_after_deductions);
        $this->assertEquals(900, (float) $budget->final_amount);
    }

    public function test_create_with_zero_transfer_percentage_writes_three_lines_and_no_transfer_role(): void
    {
        $fx = $this->baseFixture();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['transfer_percentage' => 0]),
            'transfer',
        ));

        $this->assertSame(3, $budget->transaction->lines()->count());
        $this->assertSame(['administrative_deduction', 'destination', 'source'], $this->roles($budget));
        $this->assertNoZeroValueLines($budget);

        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance, 'the transfer account must not move at all');
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_create_with_both_percentages_zero_writes_only_source_and_destination(): void
    {
        $fx = $this->baseFixture();

        $data = $this->withoutRoleKeys($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0, 'transfer_percentage' => 0]),
            'admin',
        ), 'transfer');

        $budget = $this->invokeCreate($data);

        $this->assertSame(2, $budget->transaction->lines()->count());
        $this->assertSame(['destination', 'source'], $this->roles($budget));
        $this->assertNoZeroValueLines($budget);

        // The whole amount passes straight through, undeducted.
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(1000, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(1000, (float) $budget->amount_after_deductions);
        $this->assertEquals(1000, (float) $budget->final_amount);
    }

    public function test_a_two_line_disbursement_still_converts_currency(): void
    {
        $fx        = $this->baseFixture();
        $ils       = Currency::create(['name' => 'ILS', 'code' => 'ILS', 'symbol' => 'ILS']);
        $ilsTarget = Account::create([
            'account_code' => 'وجهة-ILS', 'name' => 'وجهة-ILS',
            'account_type_id' => $fx['accountType']->id, 'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $ils->id, 'current_balance' => 0, 'is_active' => true,
        ]);

        $data = $this->withoutRoleKeys($this->withoutRoleKeys($this->baseData($fx, [
            'administrative_percentage'   => 0,
            'transfer_percentage'         => 0,
            'fx_rate'                     => 3.5,
            'disbursement_currency_id'    => $ils->id,
            'destination_account_id'      => $ilsTarget->id,
            'destination_account_type_id' => $ilsTarget->account_type_id,
            'destination_bank_type_id'    => $ilsTarget->bank_type_id,
        ]), 'admin'), 'transfer');

        $budget = $this->invokeCreate($data);

        $this->assertSame(2, $budget->transaction->lines()->count());
        $this->assertEquals(3500, (float) $ilsTarget->fresh()->current_balance);

        $destination = $budget->transaction->lines()->where('line_role', 'destination')->first();
        $this->assertEquals($ils->id, $destination->currency_id);
        $this->assertEquals(3.5, (float) $destination->fx_rate);
    }

    /* =====================================================================
     | CREATE — account validation is skipped for an inactive deduction
     ===================================================================== */

    public function test_create_accepts_a_missing_admin_account_when_the_percentage_is_zero(): void
    {
        $fx = $this->baseFixture();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $this->assertNotNull($budget->id);
    }

    public function test_create_accepts_a_missing_transfer_account_when_the_percentage_is_zero(): void
    {
        $fx = $this->baseFixture();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['transfer_percentage' => 0]),
            'transfer',
        ));

        $this->assertNotNull($budget->id);
    }

    public function test_create_still_rejects_a_missing_admin_account_when_the_percentage_is_positive(): void
    {
        $fx = $this->baseFixture();

        $this->expectException(ValidationException::class);

        $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 10]),
            'admin',
        ));
    }

    public function test_create_still_rejects_a_missing_transfer_account_when_the_percentage_is_positive(): void
    {
        $fx = $this->baseFixture();

        $this->expectException(ValidationException::class);

        $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['transfer_percentage' => 10]),
            'transfer',
        ));
    }

    public function test_an_inactive_admin_account_is_ignored_when_the_percentage_is_zero(): void
    {
        $fx = $this->baseFixture();
        $fx['adminAccount']->update(['is_active' => false]);

        // Even a deactivated account passed in the payload must not be
        // validated: that role does not exist for this disbursement.
        $budget = $this->invokeCreate($this->baseData($fx, ['administrative_percentage' => 0]));

        $this->assertSame(3, $budget->transaction->lines()->count());
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
    }

    /* =====================================================================
     | CREATE — a percentage too small to record is rejected, not dropped
     ===================================================================== */

    public function test_a_positive_percentage_rounding_to_a_zero_amount_is_rejected_on_its_own_field(): void
    {
        $fx = $this->baseFixture();

        try {
            // 0.4% of 1.00 = 0.004 -> rounds to 0.00: a deduction the operator
            // asked for that can neither be written nor silently ignored.
            $this->invokeCreate($this->baseData($fx, [
                'original_amount'           => 1,
                'administrative_percentage' => 0.4,
                'transfer_percentage'       => 0,
            ]));
            $this->fail('Expected a ValidationException for an unrecordable administrative deduction.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('administrative_percentage', $e->errors());
        }

        $this->assertSame(0, ProjectCostBudget::whereNotNull('transaction_id')->count());
        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }

    /* =====================================================================
     | CREATE — audit
     ===================================================================== */

    public function test_a_zero_deduction_create_writes_one_event_without_that_account_role(): void
    {
        $fx = $this->baseFixture();
        AuditEvent::query()->delete();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $this->assertSame(1, AuditEvent::count(), 'a 3-line disbursement is still exactly one logical action');

        $new = AuditEvent::first()->new_values;

        $this->assertArrayNotHasKey('admin_account_id', $new);
        $this->assertArrayNotHasKey('admin_account_label', $new);
        $this->assertSame($fx['transferAccount']->id, $new['transfer_account_id']);
        $this->assertSame($fx['sourceAccount']->id, $new['source_account_id']);
        $this->assertSame($fx['destinationAccount']->id, $new['destination_account_id']);
        $this->assertSame('0.00', $new['administrative_percentage']);
        $this->assertSame($budget->transaction->transaction_number, $new['transaction_number']);
    }

    /* =====================================================================
     | EDIT — Case A: 0% -> positive
     ===================================================================== */

    public function test_edit_from_zero_to_positive_adds_the_line_and_applies_the_balance(): void
    {
        $fx = $this->baseFixture();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        // The saved record has no admin line, so the form loads with an empty
        // admin cascade - nothing stale can be carried forward.
        $hydrated = $this->invokeMutateBeforeFill($budget->fresh());
        $this->assertNull($hydrated['admin_account_id']);
        $this->assertNull($hydrated['admin_account_type_id']);
        $this->assertNull($hydrated['admin_bank_type_id']);
        $this->assertEquals(0, (float) $hydrated['administrative_percentage']);

        // The operator raises the percentage and picks an account.
        $hydrated['administrative_percentage'] = 10;
        $hydrated['admin_account_id']          = $fx['adminAccount']->id;
        $hydrated['admin_account_type_id']     = $fx['adminAccount']->account_type_id;
        $hydrated['admin_bank_type_id']        = $fx['adminAccount']->bank_type_id;

        $updated = $this->invokeUpdate($budget->fresh(), $hydrated);

        $this->assertSame(4, $updated->fresh()->transaction->lines()->count());
        $this->assertSame(
            ['administrative_deduction', 'destination', 'source', 'transfer_fee'],
            $this->roles($updated),
        );

        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_edit_from_zero_to_positive_without_choosing_an_account_is_rejected(): void
    {
        $fx = $this->baseFixture();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $hydrated = $this->invokeMutateBeforeFill($budget->fresh());
        $hydrated['administrative_percentage'] = 10;
        // ... but no admin account supplied.

        try {
            $this->invokeUpdate($budget->fresh(), $hydrated);
            $this->fail('Expected a ValidationException: a positive deduction needs an account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('admin_account_id', $e->errors());
        }

        // Nothing moved: the guard ran before DB::transaction() opened.
        $this->assertSame(3, $budget->fresh()->transaction->lines()->count());
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
    }

    /* =====================================================================
     | EDIT — Case B: positive -> 0%
     ===================================================================== */

    public function test_edit_from_positive_to_zero_removes_the_line_and_reverses_the_balance(): void
    {
        $fx     = $this->baseFixture();
        $budget = $this->invokeCreate($this->baseData($fx));

        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);

        $hydrated = $this->invokeMutateBeforeFill($budget->fresh());
        $hydrated['administrative_percentage'] = 0;
        // The disabled card submits nothing at all for this role.
        $hydrated = $this->withoutRoleKeys($hydrated, 'admin');

        $updated = $this->invokeUpdate($budget->fresh(), $hydrated);

        $this->assertSame(3, $updated->fresh()->transaction->lines()->count());
        $this->assertSame(['destination', 'source', 'transfer_fee'], $this->roles($updated));
        $this->assertNull($updated->fresh()->transaction->lines()->where('notes', ProjectCostBudget::LINE_ADMIN)->first());

        // The old +100 was reversed and no new movement applied.
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $updated->fresh()->administrative_percentage);
    }

    public function test_edit_from_positive_to_zero_records_the_account_removal_in_the_audit_diff(): void
    {
        $fx     = $this->baseFixture();
        $budget = $this->invokeCreate($this->baseData($fx));

        AuditEvent::query()->delete();

        $hydrated = $this->invokeMutateBeforeFill($budget->fresh());
        $hydrated['administrative_percentage'] = 0;
        $hydrated = $this->withoutRoleKeys($hydrated, 'admin');

        $this->invokeUpdate($budget->fresh(), $hydrated);

        $this->assertSame(1, AuditEvent::count());
        $event = AuditEvent::first();

        $this->assertContains('admin_account_id', $event->changed_fields);
        $this->assertContains('administrative_percentage', $event->changed_fields);

        // The removed account is still described on the OLD side, and the NEW
        // side carries an explicit null rather than dropping the key: a diff
        // states both endpoints of every change it reports, so "was account X,
        // is now nothing" stays readable. (The CREATE snapshot behaves the
        // other way round and omits the key entirely — see
        // test_a_zero_deduction_create_writes_one_event_without_that_account_role.)
        $this->assertSame($fx['adminAccount']->id, $event->old_values['admin_account_id']);
        $this->assertArrayHasKey('admin_account_label', $event->old_values);
        $this->assertNull($event->new_values['admin_account_id']);
        $this->assertNull($event->new_values['admin_account_label']);
    }

    /* =====================================================================
     | EDIT — Case C: 0% -> 0%, and Case D: positive -> positive
     ===================================================================== */

    public function test_edit_from_zero_to_zero_keeps_three_lines_and_moves_no_deduction_balance(): void
    {
        $fx = $this->baseFixture();

        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $hydrated = $this->withoutRoleKeys($this->invokeMutateBeforeFill($budget->fresh()), 'admin');
        $updated  = $this->invokeUpdate($budget->fresh(), $hydrated);

        $this->assertSame(3, $updated->fresh()->transaction->lines()->count());
        $this->assertSame(['destination', 'source', 'transfer_fee'], $this->roles($updated));
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_edit_between_two_positive_percentages_keeps_the_line_and_rebalances(): void
    {
        $fx     = $this->baseFixture();
        $budget = $this->invokeCreate($this->baseData($fx));

        $hydrated = $this->invokeMutateBeforeFill($budget->fresh());
        $hydrated['administrative_percentage'] = 20;

        $updated = $this->invokeUpdate($budget->fresh(), $hydrated);

        $this->assertSame(4, $updated->fresh()->transaction->lines()->count());
        // 1000 - 200 - 100 = 700
        $this->assertEquals(200, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(700, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }

    /* =====================================================================
     | EDIT — the transfer deduction behaves identically
     ===================================================================== */

    public function test_transfer_deduction_supports_the_same_zero_transitions(): void
    {
        $fx = $this->baseFixture();

        // 0% -> positive
        $budget = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['transfer_percentage' => 0]),
            'transfer',
        ));
        $this->assertSame(['administrative_deduction', 'destination', 'source'], $this->roles($budget));

        $hydrated = $this->invokeMutateBeforeFill($budget->fresh());
        $this->assertNull($hydrated['transfer_account_id']);

        $hydrated['transfer_percentage']      = 10;
        $hydrated['transfer_account_id']      = $fx['transferAccount']->id;
        $hydrated['transfer_account_type_id'] = $fx['transferAccount']->account_type_id;
        $hydrated['transfer_bank_type_id']    = $fx['transferAccount']->bank_type_id;

        $updated = $this->invokeUpdate($budget->fresh(), $hydrated);
        $this->assertSame(4, $updated->fresh()->transaction->lines()->count());
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);

        // positive -> 0%
        $hydrated = $this->withoutRoleKeys($this->invokeMutateBeforeFill($updated->fresh()), 'transfer');
        $hydrated['transfer_percentage'] = 0;

        $final = $this->invokeUpdate($updated->fresh(), $hydrated);
        $this->assertSame(3, $final->fresh()->transaction->lines()->count());
        $this->assertSame(['administrative_deduction', 'destination', 'source'], $this->roles($final));
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    /* =====================================================================
     | Line descriptions still generate for the shorter shapes
     ===================================================================== */

    public function test_every_line_of_a_two_line_disbursement_gets_a_description(): void
    {
        $fx = $this->baseFixture();

        $data = $this->withoutRoleKeys($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0, 'transfer_percentage' => 0]),
            'admin',
        ), 'transfer');

        $budget = $this->invokeCreate($data);

        $lines = $budget->fresh()->transaction->lines;
        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            $this->assertNotNull($line->description, "line_role {$line->line_role} has no description");
            $this->assertStringContainsString('الغرض:', $line->description);
        }

        $this->assertNotNull($budget->fresh()->transaction->description);
    }

    /* =====================================================================
     | Delete still fully reverses a shortened transaction
     ===================================================================== */

    public function test_deleting_a_two_line_disbursement_reverses_every_balance(): void
    {
        $fx = $this->baseFixture();

        $data = $this->withoutRoleKeys($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0, 'transfer_percentage' => 0]),
            'admin',
        ), 'transfer');

        $budget = $this->invokeCreate($data);

        \App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable::deletePayment($budget->fresh());

        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertSame(0, TransactionLine::whereNull('deleted_at')->count());
    }
}
