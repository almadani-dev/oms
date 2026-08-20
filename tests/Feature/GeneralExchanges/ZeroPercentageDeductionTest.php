<?php

namespace Tests\Feature\GeneralExchanges;

use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Pages\EditGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Tables\GeneralExchangesTable;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditEvent;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The General Exchange (التحويلات العامة / EXT) mirror of
 * Tests\Feature\ProjectCostBudgetsPayments\ZeroPercentageDeductionTest.
 *
 * Both workflows share the same deduction/FX shape — one credit source, two
 * OPTIONAL debit deductions, one debit destination in a possibly different
 * currency — so both must behave identically when a percentage is 0: no
 * line, no account validation, no balance movement, no audit role, and never
 * a forbidden zero-valued line.
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

        return compact(
            'currency', 'accountType', 'bankType', 'sourceAccount', 'adminAccount',
            'transferAccount', 'destinationAccount', 'fiscalYear', 'transactionType', 'partner'
        );
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'original_amount'             => 1000,
            'source_currency_id'          => $fx['currency']->id,
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
            'exchange_image'              => null,
        ], $overrides);
    }

    /** Mimics a disabled card: the whole cascade is absent from the payload. */
    private function withoutRoleKeys(array $data, string $role): array
    {
        unset($data["{$role}_account_id"], $data["{$role}_account_type_id"], $data["{$role}_bank_type_id"]);

        return $data;
    }

    private function invokeCreate(array $data): GeneralExchange
    {
        $page   = new CreateGeneralExchange();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(GeneralExchange $record): array
    {
        $page         = new EditGeneralExchange();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        return $method->invoke($page, []);
    }

    private function invokeUpdate(GeneralExchange $record, array $data): GeneralExchange
    {
        $page         = new EditGeneralExchange();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    /** @return array<int, string> */
    private function roles(GeneralExchange $exchange): array
    {
        return $exchange->fresh()->transaction->lines()->pluck('line_role')->sort()->values()->all();
    }

    private function assertNoZeroValueLines(GeneralExchange $exchange): void
    {
        foreach ($exchange->fresh()->transaction->lines as $line) {
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
        $fx       = $this->baseFixture();
        $exchange = $this->invokeCreate($this->baseData($fx));

        $this->assertSame(4, $exchange->transaction->lines()->count());
        $this->assertSame(
            ['administrative_deduction', 'destination', 'source', 'transfer_fee'],
            $this->roles($exchange),
        );
        $this->assertNoZeroValueLines($exchange);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_create_with_zero_administrative_percentage_writes_three_lines(): void
    {
        $fx = $this->baseFixture();

        $exchange = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $this->assertSame(3, $exchange->transaction->lines()->count());
        $this->assertSame(['destination', 'source', 'transfer_fee'], $this->roles($exchange));
        $this->assertNoZeroValueLines($exchange);
        $this->assertNull($exchange->transaction->lines()->where('notes', GeneralExchange::LINE_ADMIN)->first());

        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_create_with_zero_transfer_percentage_writes_three_lines(): void
    {
        $fx = $this->baseFixture();

        $exchange = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['transfer_percentage' => 0]),
            'transfer',
        ));

        $this->assertSame(3, $exchange->transaction->lines()->count());
        $this->assertSame(['administrative_deduction', 'destination', 'source'], $this->roles($exchange));
        $this->assertNoZeroValueLines($exchange);

        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_create_with_both_percentages_zero_writes_only_source_and_destination(): void
    {
        $fx = $this->baseFixture();

        $data = $this->withoutRoleKeys($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0, 'transfer_percentage' => 0]),
            'admin',
        ), 'transfer');

        $exchange = $this->invokeCreate($data);

        $this->assertSame(2, $exchange->transaction->lines()->count());
        $this->assertSame(['destination', 'source'], $this->roles($exchange));
        $this->assertNoZeroValueLines($exchange);

        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(1000, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(1000, (float) $exchange->final_amount);
    }

    public function test_a_two_line_exchange_still_converts_currency(): void
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

        $exchange = $this->invokeCreate($data);

        $this->assertSame(2, $exchange->transaction->lines()->count());
        $this->assertEquals(3500, (float) $ilsTarget->fresh()->current_balance);
    }

    /* =====================================================================
     | CREATE — account validation
     ===================================================================== */

    public function test_create_accepts_missing_deduction_accounts_when_their_percentages_are_zero(): void
    {
        $fx = $this->baseFixture();

        $data = $this->withoutRoleKeys($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0, 'transfer_percentage' => 0]),
            'admin',
        ), 'transfer');

        $this->assertNotNull($this->invokeCreate($data)->id);
    }

    public function test_create_still_rejects_a_missing_admin_account_when_the_percentage_is_positive(): void
    {
        $fx = $this->baseFixture();

        $this->expectException(ValidationException::class);
        $this->invokeCreate($this->withoutRoleKeys($this->baseData($fx), 'admin'));
    }

    public function test_create_still_rejects_a_missing_transfer_account_when_the_percentage_is_positive(): void
    {
        $fx = $this->baseFixture();

        $this->expectException(ValidationException::class);
        $this->invokeCreate($this->withoutRoleKeys($this->baseData($fx), 'transfer'));
    }

    public function test_a_positive_percentage_rounding_to_a_zero_amount_is_rejected(): void
    {
        $fx = $this->baseFixture();

        try {
            $this->invokeCreate($this->baseData($fx, [
                'original_amount'           => 1,
                'administrative_percentage' => 0,
                'transfer_percentage'       => 0.4,
            ]));
            $this->fail('Expected a ValidationException for an unrecordable transfer deduction.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transfer_percentage', $e->errors());
        }

        $this->assertSame(0, GeneralExchange::count());
        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }

    /* =====================================================================
     | CREATE — audit
     ===================================================================== */

    public function test_a_zero_deduction_create_writes_one_event_without_that_account_role(): void
    {
        $fx = $this->baseFixture();
        AuditEvent::query()->delete();

        $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $this->assertSame(1, AuditEvent::count());

        $new = AuditEvent::first()->new_values;

        $this->assertArrayNotHasKey('admin_account_id', $new);
        $this->assertArrayNotHasKey('admin_account_label', $new);
        $this->assertSame($fx['transferAccount']->id, $new['transfer_account_id']);
        $this->assertSame('0.00', $new['administrative_percentage']);
    }

    /* =====================================================================
     | EDIT — zero <-> positive in both directions
     ===================================================================== */

    public function test_edit_from_zero_to_positive_adds_the_line_and_applies_the_balance(): void
    {
        $fx = $this->baseFixture();

        $exchange = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $hydrated = $this->invokeMutateBeforeFill($exchange->fresh());
        $this->assertNull($hydrated['admin_account_id']);
        $this->assertNull($hydrated['admin_account_type_id']);
        $this->assertNull($hydrated['admin_bank_type_id']);

        $hydrated['administrative_percentage'] = 10;
        $hydrated['admin_account_id']          = $fx['adminAccount']->id;
        $hydrated['admin_account_type_id']     = $fx['adminAccount']->account_type_id;
        $hydrated['admin_bank_type_id']        = $fx['adminAccount']->bank_type_id;

        $updated = $this->invokeUpdate($exchange->fresh(), $hydrated);

        $this->assertSame(4, $updated->fresh()->transaction->lines()->count());
        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }

    public function test_edit_from_zero_to_positive_without_choosing_an_account_is_rejected(): void
    {
        $fx = $this->baseFixture();

        $exchange = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $hydrated = $this->invokeMutateBeforeFill($exchange->fresh());
        $hydrated['administrative_percentage'] = 10;

        try {
            $this->invokeUpdate($exchange->fresh(), $hydrated);
            $this->fail('Expected a ValidationException: a positive deduction needs an account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('admin_account_id', $e->errors());
        }

        $this->assertSame(3, $exchange->fresh()->transaction->lines()->count());
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
    }

    public function test_edit_from_positive_to_zero_removes_the_line_and_reverses_the_balance(): void
    {
        $fx       = $this->baseFixture();
        $exchange = $this->invokeCreate($this->baseData($fx));

        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);

        $hydrated = $this->withoutRoleKeys($this->invokeMutateBeforeFill($exchange->fresh()), 'admin');
        $hydrated['administrative_percentage'] = 0;

        $updated = $this->invokeUpdate($exchange->fresh(), $hydrated);

        $this->assertSame(3, $updated->fresh()->transaction->lines()->count());
        $this->assertSame(['destination', 'source', 'transfer_fee'], $this->roles($updated));
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
    }

    public function test_edit_from_positive_to_zero_records_the_account_removal_in_the_audit_diff(): void
    {
        $fx       = $this->baseFixture();
        $exchange = $this->invokeCreate($this->baseData($fx));

        AuditEvent::query()->delete();

        $hydrated = $this->withoutRoleKeys($this->invokeMutateBeforeFill($exchange->fresh()), 'admin');
        $hydrated['administrative_percentage'] = 0;

        $this->invokeUpdate($exchange->fresh(), $hydrated);

        $this->assertSame(1, AuditEvent::count());
        $event = AuditEvent::first();

        $this->assertContains('admin_account_id', $event->changed_fields);
        $this->assertSame($fx['adminAccount']->id, $event->old_values['admin_account_id']);
        $this->assertNull($event->new_values['admin_account_id']);
    }

    public function test_edit_from_zero_to_zero_changes_nothing(): void
    {
        $fx = $this->baseFixture();

        $exchange = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0]),
            'admin',
        ));

        $hydrated = $this->withoutRoleKeys($this->invokeMutateBeforeFill($exchange->fresh()), 'admin');
        $updated  = $this->invokeUpdate($exchange->fresh(), $hydrated);

        $this->assertSame(3, $updated->fresh()->transaction->lines()->count());
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_transfer_deduction_supports_the_same_zero_transitions(): void
    {
        $fx = $this->baseFixture();

        $exchange = $this->invokeCreate($this->withoutRoleKeys(
            $this->baseData($fx, ['transfer_percentage' => 0]),
            'transfer',
        ));
        $this->assertSame(['administrative_deduction', 'destination', 'source'], $this->roles($exchange));

        $hydrated = $this->invokeMutateBeforeFill($exchange->fresh());
        $this->assertNull($hydrated['transfer_account_id']);

        $hydrated['transfer_percentage']      = 10;
        $hydrated['transfer_account_id']      = $fx['transferAccount']->id;
        $hydrated['transfer_account_type_id'] = $fx['transferAccount']->account_type_id;
        $hydrated['transfer_bank_type_id']    = $fx['transferAccount']->bank_type_id;

        $updated = $this->invokeUpdate($exchange->fresh(), $hydrated);
        $this->assertSame(4, $updated->fresh()->transaction->lines()->count());
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);

        $hydrated = $this->withoutRoleKeys($this->invokeMutateBeforeFill($updated->fresh()), 'transfer');
        $hydrated['transfer_percentage'] = 0;

        $final = $this->invokeUpdate($updated->fresh(), $hydrated);
        $this->assertSame(3, $final->fresh()->transaction->lines()->count());
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(900, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    /* =====================================================================
     | Descriptions and delete still work on the shorter shapes
     ===================================================================== */

    public function test_every_line_of_a_two_line_exchange_gets_a_description(): void
    {
        $fx = $this->baseFixture();

        $data = $this->withoutRoleKeys($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0, 'transfer_percentage' => 0]),
            'admin',
        ), 'transfer');

        $exchange = $this->invokeCreate($data);

        foreach ($exchange->fresh()->transaction->lines as $line) {
            $this->assertNotNull($line->description);
            $this->assertStringContainsString('الغرض:', $line->description);
        }

        $this->assertNotNull($exchange->fresh()->transaction->description);
    }

    public function test_deleting_a_two_line_exchange_reverses_every_balance(): void
    {
        $fx = $this->baseFixture();

        $data = $this->withoutRoleKeys($this->withoutRoleKeys(
            $this->baseData($fx, ['administrative_percentage' => 0, 'transfer_percentage' => 0]),
            'admin',
        ), 'transfer');

        $exchange = $this->invokeCreate($data);

        GeneralExchangesTable::deleteExchange($exchange->fresh());

        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertSame(0, TransactionLine::whereNull('deleted_at')->count());
    }
}
