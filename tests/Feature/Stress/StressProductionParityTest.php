<?php

namespace Tests\Feature\Stress;

use App\Enums\AuditStatus;
use App\Enums\AuditFailureMode;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\User;
use App\Services\Audit\AuditActorContext;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditPayloadBounder;
use App\Services\Audit\AuditRecordRequest;
use App\Services\Audit\AuditRedactor;
use App\Services\Stress\Generator\Audit\StressAuditRowFactory;
use App\Services\Stress\Generator\Phases\PartnersAccountsPhase;
use App\Services\Stress\Generator\ProductionFlowBuilders;
use App\Services\Stress\Generator\StressRandom;
use App\Services\Stress\Generator\StressTransactionDescriber;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\Stress\StressLookupFixtures;
use Tests\TestCase;

/**
 * Where the generator restates (rather than calls) production logic, its
 * output must equal what the real write path produces.
 */
class StressProductionParityTest extends TestCase
{
    use StressLookupFixtures;

    private function seedLedger(): array
    {
        $this->seedStressLookups();
        $user = User::factory()->create();
        $this->actingAs($user);

        $usd = Currency::where('code', 'USD')->firstOrFail();
        $type = DB::table('accounts_type')->value('id');
        $bank = DB::table('bank_types')->value('id');
        $a = Account::create(['name' => 'الصندوق الرئيسي', 'account_type_id' => $type, 'bank_type_id' => $bank, 'currency_id' => $usd->id]);
        $b = Account::create(['name' => 'حساب المورد', 'account_type_id' => $type, 'bank_type_id' => $bank, 'currency_id' => $usd->id]);
        $fy = DB::table('fiscal_years')->insertGetId(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);

        return [$user, $usd, $a, $b, $fy];
    }

    public function test_every_production_builder_is_callable_and_its_payload_passes_the_real_guards(): void
    {
        [$user, $usd, $a, $b] = $this->seedLedger();
        $builders = new ProductionFlowBuilders;
        $builders->actingAs($user);

        $lines = $builders->expenseLines($a->id, $b->id, $usd->id, 125.5);
        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, $usd->id);
        $this->assertSame($user->id, $lines[0]['created_by']);

        $lines = $builders->executionLines($a->id, $b->id, $usd->id, 40.25);
        FinancialTransactionBalanceGuard::assertValidLinePayload($lines);

        foreach ([[5.0, 1.0], [0.0, 1.5], [7.0, 0.0], [0.0, 0.0]] as [$adm, $trf]) {
            [$admin, $transfer, , $final] = $builders->deriveAmounts(1000.0, $adm, $trf, 3.65);
            $lines = $builders->exchangeLines(['source_account_id' => 1, 'admin_account_id' => 2, 'transfer_account_id' => 3, 'destination_account_id' => 4], 1, 2, ['original' => 1000.0, 'admin' => $admin, 'transfer' => $transfer, 'final' => $final, 'fx' => 3.65]);
            FinancialTransactionBalanceGuard::assertValidLinePayload(array_values($lines));
            FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($lines['source'], $lines['admin'] ?? null, $lines['transfer'] ?? null, $lines['destination'], 1, 2);
            $this->assertCount(2 + ($adm > 0 ? 1 : 0) + ($trf > 0 ? 1 : 0), $lines);
        }

        $this->assertArrayHasKey('destination', $builders->disbursementPurposes());
        $this->assertArrayHasKey('source', $builders->exchangePurposes());
    }

    public function test_transaction_description_equals_the_real_database_builder(): void
    {
        [$user, $usd, $a, $b, $fy] = $this->seedLedger();
        $type = DB::table('transactions_types')->value('id');
        $transaction = Transaction::create(['fiscal_year_id' => $fy, 'transaction_type_id' => $type, 'transaction_number' => 'GEN-2026-0001', 'transaction_time' => '2026-02-01 00:00:00']);

        $builders = new ProductionFlowBuilders;
        $builders->actingAs($user);
        $payload = $builders->expenseLines($a->id, $b->id, $usd->id, 1234.5);
        $models = collect();

        foreach ($payload as $line) {
            TransactionLine::create($line + ['transaction_id' => $transaction->id]);
            $model = new TransactionLine;
            $model->forceFill(['debit_base' => $line['debit_base'], 'credit_base' => $line['credit_base'], 'line_role' => $line['line_role']]);
            $model->setRelation('account', Account::find($line['account_id']));
            $model->setRelation('currency', $usd);
            $models->push($model);
        }

        $summary = 'تسجيل مصروف عام: اختبار';
        $real = app(TransactionDescriptionBuilder::class)->build($transaction, $summary);
        $stress = (new StressTransactionDescriber(app(TransactionLineDescriptionBuilder::class)))->describeTransaction($models, $summary);

        $this->assertSame($real, $stress);
    }

    public function test_opening_lines_equal_create_account_opening_lines(): void
    {
        [$user, $usd, $a, $b, $fy] = $this->seedLedger();
        $type = DB::table('transactions_types')->value('id');
        $transaction = Transaction::create(['fiscal_year_id' => $fy, 'transaction_type_id' => $type, 'transaction_number' => 'OPB-2026-0001', 'transaction_time' => '2026-01-01 00:00:00']);

        $page = new CreateAccount;
        Closure::bind(fn () => $this->createOpeningLines($transaction->id, $a, $b, 500.25, 3.6), $page, CreateAccount::class)();

        $real = TransactionLine::where('transaction_id', $transaction->id)->orderBy('id')
            ->get(['account_id', 'currency_id', 'amount_currency', 'fx_rate', 'debit_base', 'credit_base', 'notes', 'line_role', 'created_by', 'updated_by'])
            ->map(fn ($l) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, $l->getAttributes()))->all();

        $stress = array_map(
            fn ($l) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, $l),
            PartnersAccountsPhase::openingLines($a->id, $b->id, $usd->id, 500.25, 3.6, $user->id),
        );

        $this->assertEquals($real, $stress);
    }

    public function test_staged_audit_row_equals_what_the_real_audit_logger_writes(): void
    {
        [$user] = $this->seedLedger();

        $request = new AuditRecordRequest(
            eventCategory: 'financial',
            eventAction: 'updated',
            actor: AuditActorContext::forUser($user),
            status: AuditStatus::Success,
            subjectType: 'general_expense',
            subjectKey: '42',
            subjectLabel: 'GEN-2026-0042',
            oldValues: ['amount' => '10.00', 'notes' => "=SUM(A1)\nسطر", 'password' => 'secret'],
            newValues: ['amount' => '12.00', 'notes' => null],
            changedFields: ['amount', 'notes'],
        );

        $real = app(AuditLogger::class)->record($request, AuditFailureMode::Required);
        $realRow = (array) DB::table('audit_events')->where('id', $real->id)->first();

        $factory = new StressAuditRowFactory(app(AuditRedactor::class), app(AuditPayloadBounder::class), new StressRandom(1));
        $stressRow = $factory->row($request, '2026-02-01 10:00:00');
        DB::table('audit_events')->insert($stressRow);
        $insertedRow = (array) DB::table('audit_events')->where('uuid', $stressRow['uuid'])->first();

        foreach (['id', 'uuid', 'created_at'] as $ignored) {
            unset($realRow[$ignored], $insertedRow[$ignored]);
        }

        foreach (['actor_roles', 'old_values', 'new_values', 'changed_fields'] as $json) {
            $realRow[$json] = json_decode((string) $realRow[$json], true);
            $insertedRow[$json] = json_decode((string) $insertedRow[$json], true);
        }

        $this->assertSame($realRow, $insertedRow);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $stressRow['uuid']);
        $this->assertSame(1, AuditEvent::where('created_at', '2026-02-01 10:00:00')->count());
    }
}
