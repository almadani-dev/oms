<?php

namespace Tests\Feature\Audit\Financial;

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditEvent;
use App\Models\Currency;
use App\Models\ExchangeRateHistory;
use App\Models\Transaction;
use App\Services\Audit\Crud\AuditedCrudService;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Task 9B.3 — the four financial MASTER-DATA subjects: account,
 * account_type, currency, exchange_rate_history.
 *
 * These use the Task 9B.2 CRUD architecture (event_category `crud`) with the
 * same REQUIRED atomicity, plus two financial guarantees of their own: money
 * and rates survive as decimal strings, and an account created with an
 * opening balance produces exactly ONE account event carrying the opening
 * entry's identifiers — never a second event for the opening Transaction.
 */
class FinancialMasterDataAuditTest extends FinancialAuditTestCase
{
    private function service(): AuditedCrudService
    {
        return app(AuditedCrudService::class);
    }

    // ---- Account ---------------------------------------------------------

    public function test_account_create_update_and_delete_are_audited(): void
    {
        $fx = $this->fixture();

        $account = $this->service()->create(new Account, [
            'account_code' => 'ACC-1',
            'name' => 'حساب جديد',
            'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $fx['currency']->id,
            'is_active' => true,
        ]);

        $created = $this->onlyEventFor('account', 'created');

        $this->assertSame('crud', $created->event_category);
        $this->assertSame((string) $account->id, $created->subject_key);
        $this->assertSame('ACC-1 — حساب جديد', $created->subject_label);
        $this->assertSame('حساب جديد', $created->new_values['name']);
        $this->assertSame('USD', $created->new_values['currency_label']);
        $this->assertSame('نوع حساب', $created->new_values['account_type_label']);
        // Balance as a decimal string, never a float.
        $this->assertSame('0.00', $created->new_values['current_balance']);

        $this->service()->update($account, ['name' => 'حساب معدل', 'is_active' => false]);

        $updated = $this->onlyEventFor('account', 'updated');

        $this->assertEqualsCanonicalizing(['name', 'is_active'], $updated->changed_fields);
        $this->assertSame('حساب جديد', $updated->old_values['name']);
        $this->assertSame('حساب معدل', $updated->new_values['name']);
        $this->assertArrayNotHasKey('current_balance', $updated->new_values);

        $this->service()->delete($account->fresh());

        $deleted = $this->onlyEventFor('account', 'deleted');

        $this->assertNull($deleted->new_values);
        $this->assertSame('حساب معدل', $deleted->old_values['name']);
        $this->assertSame('0.00', $deleted->old_values['current_balance']);
        $this->assertNotNull(Account::withTrashed()->find($account->id)->deleted_at);
    }

    public function test_a_no_op_account_update_creates_no_event(): void
    {
        $fx = $this->fixture();

        $account = $this->service()->create(new Account, [
            'account_code' => 'ACC-2',
            'name' => 'ثابت',
            'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $fx['currency']->id,
            'is_active' => true,
        ]);

        AuditEvent::query()->delete();

        $this->service()->update($account, ['name' => 'ثابت']);

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_account_creation_without_an_opening_balance_writes_one_event(): void
    {
        $fx = $this->fixture();

        $account = $this->invoke(new CreateAccount, 'handleRecordCreation', [[
            'account_code' => 'ACC-3',
            'name' => 'بدون رصيد افتتاحي',
            'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $fx['currency']->id,
            'is_active' => true,
            'opening_balance' => 0,
            'opening_balance_date' => '2026-07-18',
            'opening_balance_fx_rate' => 1,
        ]]);

        $this->assertSame(1, AuditEvent::count());
        $this->assertSame(0, Transaction::count(), 'No opening entry is created for a zero opening balance.');

        $event = $this->onlyEventFor('account', 'created');

        $this->assertSame((string) $account->id, $event->subject_key);
        $this->assertArrayNotHasKey('opening_transaction_number', $event->new_values);
    }

    public function test_an_opening_balance_produces_exactly_one_account_event_carrying_the_entry_identifiers(): void
    {
        $fx = $this->fixture();
        $accountsBefore = Account::count();

        $account = $this->invoke(new CreateAccount, 'handleRecordCreation', [[
            'account_code' => 'ACC-4',
            'name' => 'برصيد افتتاحي',
            'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $fx['currency']->id,
            'is_active' => true,
            'opening_balance' => 2500,
            'opening_balance_date' => '2026-07-18',
            'opening_balance_fx_rate' => 1,
        ]]);

        // One event for the whole action, even though it also created an
        // opening Transaction, two TransactionLines, a clearing Account and
        // its AccountType/TransactionType lookups.
        $this->assertSame(1, AuditEvent::count(), 'The opening entry must not produce a second event.');
        $this->assertSame(0, AuditEvent::whereIn('subject_type', ['transaction', 'transaction_line'])->count());
        $this->assertSame(
            $accountsBefore + 2,
            Account::count(),
            'The counterpart clearing account was created but deliberately not audited.',
        );

        $event = $this->onlyEventFor('account', 'created');
        $new = $event->new_values;

        $this->assertSame((string) $account->id, $event->subject_key);

        $openingTransaction = Transaction::firstOrFail();
        $this->assertSame($openingTransaction->id, $new['opening_transaction_id']);
        $this->assertSame($openingTransaction->transaction_number, $new['opening_transaction_number']);
        $this->assertStringStartsWith('OPB-', $new['opening_transaction_number']);
        $this->assertSame('2500.00', $new['opening_balance']);
        $this->assertSame('2026-07-18', $new['opening_balance_date']);
        $this->assertSame('1.000000', $new['opening_balance_fx_rate']);

        $this->assertEquals(2500, (float) $account->fresh()->current_balance);
    }

    public function test_a_failed_required_audit_rolls_back_the_whole_opening_balance_creation(): void
    {
        $fx = $this->fixture();

        $accountsBefore = Account::count();

        Schema::drop('audit_events');

        try {
            $this->invoke(new CreateAccount, 'handleRecordCreation', [[
                'account_code' => 'ACC-5',
                'name' => 'يجب ألا يبقى',
                'account_type_id' => $fx['accountType']->id,
                'bank_type_id' => $fx['bankType']->id,
                'currency_id' => $fx['currency']->id,
                'is_active' => true,
                'opening_balance' => 900,
                'opening_balance_date' => '2026-07-18',
                'opening_balance_fx_rate' => 1,
            ]]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame($accountsBefore, Account::withTrashed()->count(), 'Both accounts must have rolled back.');
        $this->assertSame(0, Transaction::withTrashed()->count(), 'The opening entry must have rolled back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    // ---- HasUserTracking -------------------------------------------------

    public function test_has_user_tracking_is_active_on_account_and_account_type(): void
    {
        $user = auth()->user();
        $fx = $this->fixture();

        $accountType = AccountType::create(['name' => 'نوع متتبَّع']);

        $this->assertSame($user->id, $accountType->created_by);
        $this->assertSame($user->id, $accountType->updated_by);

        $account = Account::create([
            'account_code' => 'TRK-1',
            'name' => 'حساب متتبَّع',
            'account_type_id' => $accountType->id,
            'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $fx['currency']->id,
            'is_active' => true,
        ]);

        $this->assertSame($user->id, $account->created_by);
        $this->assertSame($user->id, $account->updated_by);

        $other = $this->superAdmin();
        $this->actingAs($other);

        $account->update(['name' => 'اسم آخر']);

        $this->assertSame($user->id, $account->fresh()->created_by, 'created_by is never rewritten.');
        $this->assertSame($other->id, $account->fresh()->updated_by);
    }

    public function test_existing_rows_are_not_backfilled(): void
    {
        $fx = $this->fixture();

        // A pre-existing row, exactly as the real database holds them: the
        // columns exist, the values are NULL, and activating the trait must
        // not invent an actor for it.
        DB::table('accounts_type')->insert(['name' => 'قديم', 'created_at' => now(), 'updated_at' => now()]);

        $legacy = AccountType::where('name', 'قديم')->firstOrFail();

        $this->assertNull($legacy->created_by);
        $this->assertNull($legacy->updated_by);
    }

    // ---- Currency / ExchangeRateHistory ----------------------------------

    public function test_currency_create_update_and_delete_are_audited(): void
    {
        $currency = $this->service()->create(new Currency, [
            'name' => 'يورو',
            'code' => 'EUR',
            'symbol' => '€',
            'is_base' => false,
        ]);

        $created = $this->onlyEventFor('currency', 'created');
        $this->assertSame('EUR — يورو', $created->subject_label);
        $this->assertSame('EUR', $created->new_values['code']);

        $this->service()->update($currency, ['symbol' => 'EU€']);

        $updated = $this->onlyEventFor('currency', 'updated');
        $this->assertSame(['symbol'], $updated->changed_fields);
        $this->assertSame('€', $updated->old_values['symbol']);
        $this->assertSame('EU€', $updated->new_values['symbol']);

        $this->service()->delete($currency->fresh());

        $deleted = $this->onlyEventFor('currency', 'deleted');
        $this->assertNull($deleted->new_values);
        $this->assertSame('EUR', $deleted->old_values['code']);
    }

    public function test_exchange_rate_values_are_preserved_as_decimal_strings(): void
    {
        $fx = $this->fixture();

        $history = $this->service()->create(new ExchangeRateHistory, [
            'currency_id' => $fx['currency']->id,
            'rate' => 3.756123,
            'date' => '2026-07-18',
        ]);

        $created = $this->onlyEventFor('exchange_rate_history', 'created');

        $this->assertSame('crud', $created->event_category);
        $this->assertSame('USD — 2026-07-18', $created->subject_label);
        $this->assertSame($fx['currency']->id, $created->new_values['currency_id']);
        $this->assertSame('USD', $created->new_values['currency_label']);
        $this->assertSame('3.756123', $created->new_values['rate']);
        $this->assertIsString($created->new_values['rate']);
        $this->assertSame('2026-07-18', $created->new_values['date']);

        $this->service()->update($history, ['rate' => 3.9]);

        $updated = $this->onlyEventFor('exchange_rate_history', 'updated');

        $this->assertSame(['rate'], $updated->changed_fields);
        $this->assertSame('3.756123', $updated->old_values['rate']);
        $this->assertSame('3.900000', $updated->new_values['rate']);

        $this->service()->delete($history->fresh());

        $deleted = $this->onlyEventFor('exchange_rate_history', 'deleted');
        $this->assertNull($deleted->new_values);
        $this->assertSame('3.900000', $deleted->old_values['rate']);
        $this->assertSame('2026-07-18', $deleted->old_values['date']);
    }

    public function test_a_no_op_exchange_rate_update_creates_no_event(): void
    {
        $fx = $this->fixture();

        $history = $this->service()->create(new ExchangeRateHistory, [
            'currency_id' => $fx['currency']->id,
            'rate' => 3.5,
            'date' => '2026-07-18',
        ]);

        AuditEvent::query()->delete();

        // Re-saving the identical rate — the shape a seed-only synchronisation
        // pass takes — is not an auditable state change.
        $this->service()->update($history, ['rate' => 3.5, 'date' => '2026-07-18']);

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_account_type_create_update_and_delete_are_audited(): void
    {
        $type = $this->service()->create(new AccountType, ['name' => 'نوع أول']);

        $this->assertSame('نوع أول', $this->onlyEventFor('account_type', 'created')->new_values['name']);

        $this->service()->update($type, ['name' => 'نوع ثانٍ']);

        $updated = $this->onlyEventFor('account_type', 'updated');
        $this->assertSame(['name'], $updated->changed_fields);
        $this->assertSame('نوع أول', $updated->old_values['name']);

        $this->service()->delete($type->fresh());

        $this->assertSame('نوع ثانٍ', $this->onlyEventFor('account_type', 'deleted')->old_values['name']);
    }

    public function test_the_edit_page_routes_updates_through_the_audited_service(): void
    {
        $fx = $this->fixture();

        $account = $this->service()->create(new Account, [
            'account_code' => 'ACC-6',
            'name' => 'قبل',
            'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id,
            'currency_id' => $fx['currency']->id,
            'is_active' => true,
        ]);

        AuditEvent::query()->delete();

        $this->invoke(new EditAccount, 'handleRecordUpdate', [$account, ['name' => 'بعد']]);

        $event = $this->onlyEventFor('account', 'updated');

        $this->assertSame(['name'], $event->changed_fields);
        $this->assertSame('بعد', Account::find($account->id)->name);
    }
}
