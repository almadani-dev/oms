<?php

namespace Tests\Support\Reports;

use App\Filament\Pages\AccountStatementPage;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Reports\AccountStatement\AccountStatementProjectionRebuilder;
use App\Support\Permissions\PermissionRegistry;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The "تقرير كشف الحساب" fixture ledger — the same ledger as
 * AccountStatementReportTest::seedLedger() (kept identical on purpose):
 * five accounts in two currencies and two account types, three types (two
 * classifications), two projects (one cost item soft-deleted), ~260
 * transactions over Nov 2025 – Mar 2026 with deliberate timestamp ties, a
 * soft-deleted transaction, a soft-deleted line and one line in a foreign
 * currency (mixed-currency warning).
 */
trait AccountStatementLedgerFixture
{
    /**
     * @return array<string, mixed>
     */
    protected function seedStatementLedger(): array
    {
        $usd = Currency::create(['name' => 'دولار', 'code' => 'USD', 'symbol' => '$']);
        $ils = Currency::create(['name' => 'شيكل', 'code' => 'ILS', 'symbol' => '₪']);
        $bankType = AccountType::create(['name' => 'بنوك']);
        $boxType = AccountType::create(['name' => 'صناديق']);
        $bank = BankType::create(['name' => 'بنك']);

        $account = fn (string $code, Currency $currency, AccountType $type) => Account::create([
            'account_code' => $code,
            'name' => 'حساب '.$code,
            'account_type_id' => $type->id,
            'bank_type_id' => $bank->id,
            'currency_id' => $currency->id,
            'current_balance' => 0,
            'is_active' => true,
            'iban' => 'PS00 '.$code,
        ]);

        $a = $account('A-100', $usd, $bankType);
        $b = $account('B-200', $usd, $bankType);
        $c = $account('C-300', $ils, $boxType);
        $d = $account('D-400', $usd, $boxType);
        $e = $account('E-500', $usd, $bankType);

        $income = TransactionSuperType::create(['name' => 'إيرادات']);
        $spend = TransactionSuperType::create(['name' => 'مصروفات']);
        $receipt = TransactionType::create(['name' => 'استلام', 'transaction_super_type_id' => $income->id]);
        $expense = TransactionType::create(['name' => 'صرف', 'transaction_super_type_id' => $spend->id]);
        $transfer = TransactionType::create(['name' => 'تحويل']);

        $status = ProjectStatus::create(['name' => 'نشط']);
        $super = ProjectSuper::create(['name' => 'برامج']);
        $p1 = Project::create(['name' => 'مشروع أول', 'code' => 'P-1', 'project_status_id' => $status->id, 'project_super_id' => $super->id]);
        $p2 = Project::create(['name' => 'مشروع ثان', 'code' => 'P-2', 'project_status_id' => $status->id, 'project_super_id' => $super->id]);
        $c1 = ProjectCost::create(['project_id' => $p1->id, 'amount' => 1000, 'currency_id' => $usd->id]);
        $c2 = ProjectCost::create(['project_id' => $p1->id, 'amount' => 1000, 'currency_id' => $usd->id]);
        $c3 = ProjectCost::create(['project_id' => $p2->id, 'amount' => 1000, 'currency_id' => $usd->id]);

        $fy = FiscalYear::create(['name' => 'سنة', 'start_date' => '2025-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);

        $accounts = [$a, $b, $c, $d, $e];
        $types = [$receipt, $expense, $transfer];
        $costs = [null, $c1, $c2, $c3];
        $seed = 7;
        $next = function (int $mod) use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;

            return intdiv($seed, 65536) % $mod;
        };

        for ($i = 0; $i < 260; $i++) {
            $time = $i % 9 === 0 && isset($time)
                ? $time
                : sprintf('%s %02d:%02d:00', date('Y-m-d', strtotime('2025-11-01 +'.$next(150).' days')), $next(24), $next(4) * 15);

            if ($i >= 60 && $i < 72) {
                $time = '2025-11-20 10:00:00';
            }

            $tx = Transaction::create([
                'fiscal_year_id' => $fy->id,
                'transaction_type_id' => $types[$next(3)]->id,
                'transaction_number' => sprintf('TX-%04d', $i),
                'transaction_time' => $time,
                'description' => 'عملية '.$i,
            ]);

            $debitAccount = $accounts[$next(5)];
            $creditAccount = $accounts[($next(4) + 1 + array_search($debitAccount, $accounts, true)) % 5];
            $amount = ($next(90000) + 100) / 100;
            $cost = $costs[$next(4)];

            TransactionLine::create([
                'transaction_id' => $tx->id, 'account_id' => $debitAccount->id, 'currency_id' => $debitAccount->currency_id,
                'project_cost_id' => $cost?->id, 'amount_currency' => $amount, 'fx_rate' => 1,
                'debit_base' => $amount, 'credit_base' => 0, 'notes' => 'ملاحظة '.$i, 'description' => 'مدين '.$i, 'line_role' => null,
            ]);
            TransactionLine::create([
                'transaction_id' => $tx->id, 'account_id' => $creditAccount->id, 'currency_id' => $creditAccount->currency_id,
                'project_cost_id' => $cost?->id, 'amount_currency' => $amount, 'fx_rate' => 1,
                'debit_base' => 0, 'credit_base' => $amount, 'notes' => null, 'description' => null, 'line_role' => null,
            ]);
        }

        Transaction::query()->where('transaction_number', 'TX-0005')->first()->delete();
        TransactionLine::query()->where('description', 'مدين 7')->first()->delete();

        $tx = Transaction::create(['fiscal_year_id' => $fy->id, 'transaction_type_id' => $transfer->id, 'transaction_number' => 'TX-MIX', 'transaction_time' => '2026-01-10 12:00:00']);
        TransactionLine::create(['transaction_id' => $tx->id, 'account_id' => $e->id, 'currency_id' => $ils->id, 'amount_currency' => 55.55, 'fx_rate' => 1, 'debit_base' => 55.55, 'credit_base' => 0]);
        TransactionLine::create(['transaction_id' => $tx->id, 'account_id' => $c->id, 'currency_id' => $ils->id, 'amount_currency' => 55.55, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 55.55]);

        $c2->delete();
        $transfer->delete();

        app(AccountStatementProjectionRebuilder::class)->rebuild();

        return compact('usd', 'ils', 'bankType', 'boxType', 'bank', 'a', 'b', 'c', 'd', 'e', 'income', 'spend', 'receipt', 'expense', 'transfer', 'p1', 'p2', 'c1', 'c2', 'c3', 'fy');
    }

    protected function statementSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    /**
     * The page after the real "عرض", as $user (a new super admin by default).
     *
     * @param  array<string, mixed>  $data
     */
    protected function statementPage(array $data, ?User $user = null)
    {
        $this->actingAs($user ?? $this->statementSuperAdmin());

        $test = Livewire::test(AccountStatementPage::class);

        foreach ($data as $key => $value) {
            $test->set('data.'.$key, $value);
        }

        return $test->call('showReport');
    }
}
