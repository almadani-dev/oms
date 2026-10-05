<?php

namespace Tests\Support\Reports;

use App\Models\BankType;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\TransactionSuperType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ledgers for the "تقرير الحركات المالية الشامل" snapshot tests.
 *
 * Expects Tests\Support\Integrity\IntegrityTestFixtures on the same class.
 */
trait ComprehensiveFinancialTransactionsFixtures
{
    /**
     * A small ledger built to be awkward for every shortcut a batched reader
     * could take:
     *  - several transactions share one transaction_time, and their ids are
     *    NOT in time order (the latest transaction is created first);
     *  - a later transaction's lines are inserted BEFORE an earlier one's, so
     *    line ids do not follow report order (chunkById() would re-order);
     *  - two transaction types share one display name, and one type has no
     *    classification at all (the 'none' bucket);
     *  - two currencies, a transaction with an empty number, every note source
     *    (transaction, line, account, project cost, two receipts on one
     *    transaction plus a soft-deleted one);
     *  - a soft-deleted line, a soft-deleted transaction, a soft-deleted
     *    account and an out-of-period transaction, all of which must vanish.
     *
     * @return array<string, mixed>
     */
    protected function seedAwkwardLedger(): array
    {
        $usd = $this->makeCurrency(['name' => 'دولار', 'code' => 'USD']);
        $ils = $this->makeCurrency(['name' => 'شيكل', 'code' => 'ILS']);

        $income = TransactionSuperType::create(['name' => 'تصنيف التحصيل'])->id;
        $spend = TransactionSuperType::create(['name' => 'تصنيف الصرف'])->id;

        $typeA = $this->typeId('نوع مكرر', $income);
        $typeB = $this->typeId('نوع مكرر', $spend);
        $typeC = $this->typeId('نوع الصرف', $spend);
        $typeNone = $this->typeId('نوع بلا تصنيف', null);

        $bank = BankType::create(['name' => 'بنك تجاري'])->id;

        $cash = $this->makeAccount($usd, ['name' => 'الصندوق', 'notes' => 'ملاحظة على الصندوق']);
        $bankUsd = $this->makeAccount($usd, ['name' => 'بنك فلسطين', 'bank_type_id' => $bank]);
        $bankIls = $this->makeAccount($ils, ['name' => 'بنك شيكل']);
        $gone = $this->makeAccount($usd, ['name' => 'حساب محذوف']);

        $projectCost = $this->projectCostId($usd, 'ملاحظة بند التكلفة');
        $user = User::factory()->create(['name' => 'منشئ القيود']);

        // Created first, latest in time: its id is the smallest.
        $late = $this->tx('2026-07-25 10:00:00', $typeC, ['created_by' => $user->id]);

        $tie1 = $this->tx('2026-07-05 09:00:00', $typeA, ['notes' => 'ملاحظة على المعاملة']);
        $tie2 = $this->tx('2026-07-05 09:00:00', $typeB, ['transaction_number' => '']);
        $tie3 = $this->tx('2026-07-05 09:00:00', $typeNone);
        $early = $this->tx('2026-07-01 00:00:00', $typeA, ['description' => '  ']);
        $ilsTx = $this->tx('2026-07-12 13:30:00', $typeC);
        $deletedTx = $this->tx('2026-07-06 08:00:00', $typeA);
        $outside = $this->tx('2026-08-02 08:00:00', $typeA);

        // tie3's lines first, so its line ids are lower than tie1's/tie2's.
        $this->makeLine($tie3, $cash, $usd, ['debit_base' => 40, 'credit_base' => 0]);
        $this->makeLine($tie3, $bankUsd, $usd, ['debit_base' => 0, 'credit_base' => 40]);

        $this->makeLine($tie1, $cash, $usd, ['debit_base' => 300.10, 'credit_base' => 0, 'notes' => 'ملاحظة سطر', 'project_cost_id' => $projectCost]);
        $this->makeLine($tie1, $bankUsd, $usd, ['debit_base' => 0, 'credit_base' => 200.05, 'description' => 'وصف السطر']);
        $this->makeLine($tie1, $bankUsd, $usd, ['debit_base' => 0, 'credit_base' => 100.05]);
        $deletedLine = $this->makeLine($tie1, $bankUsd, $usd, ['debit_base' => 999, 'credit_base' => 0]);
        $deletedLine->delete();

        $this->makeLine($tie2, $bankUsd, $usd, ['debit_base' => 0.1, 'credit_base' => 0]);
        $this->makeLine($tie2, $cash, $usd, ['debit_base' => 0.2, 'credit_base' => 0]);
        $this->makeLine($tie2, $cash, $usd, ['debit_base' => 0, 'credit_base' => 0.3]);

        $this->makeLine($late, $cash, $usd, ['debit_base' => 0, 'credit_base' => 75]);
        $this->makeLine($late, $bankUsd, $usd, ['debit_base' => 75, 'credit_base' => 0]);

        $this->makeLine($early, $bankUsd, $usd, ['debit_base' => 12.5, 'credit_base' => 0]);
        $this->makeLine($early, $cash, $usd, ['debit_base' => 0, 'credit_base' => 12.5]);

        $this->makeLine($ilsTx, $bankIls, $ils, ['debit_base' => 500, 'credit_base' => 0]);
        $this->makeLine($ilsTx, $bankIls, $ils, ['debit_base' => 0, 'credit_base' => 499.99]);

        $this->makeLine($ilsTx, $gone, $usd, ['debit_base' => 1, 'credit_base' => 0]);
        $gone->delete();

        $this->makeLine($deletedTx, $cash, $usd, ['debit_base' => 5, 'credit_base' => 0]);
        $deletedTx->delete();

        $this->makeLine($outside, $cash, $usd, ['debit_base' => 7, 'credit_base' => 0]);

        // Source-record notes: two live receipts on one transaction, one dead.
        $this->receipt($tie1->id, $projectCost, 'ملاحظة الإيصال الأول');
        $this->receipt($tie1->id, $projectCost, 'ملاحظة الإيصال الثاني');
        $dead = $this->receipt($tie1->id, $projectCost, 'إيصال محذوف');
        DB::table('project_cost_receipts')->where('id', $dead)->update(['deleted_at' => now()]);
        $this->receipt($late->id, $projectCost, 'ملاحظة إيصال المعاملة المتأخرة');

        return compact('usd', 'ils', 'cash', 'bankUsd', 'bankIls', 'income', 'spend', 'typeA', 'typeB', 'typeC', 'typeNone');
    }

    /**
     * Bulk-inserted synthetic ledger: $lines lines in $lines/2 balanced
     * transactions over 2 currencies, 4 classifications and 12 types, with
     * three transactions per transaction_time (ties) and notes on some
     * transactions/lines/accounts. Inserted directly, so even tens of
     * thousands of lines seed in seconds.
     */
    protected function seedBulkLedger(int $lines, string $from = '2026-07-01 08:00:00'): void
    {
        $usd = $this->makeCurrency(['name' => 'دولار', 'code' => 'USD-'.uniqid()]);
        $ils = $this->makeCurrency(['name' => 'شيكل', 'code' => 'ILS-'.uniqid()]);
        $fiscalYear = $this->makeFiscalYear();
        $now = now()->toDateTimeString();

        $supers = [];
        for ($i = 0; $i < 4; $i++) {
            $supers[] = TransactionSuperType::create(['name' => 'تصنيف '.$i])->id;
        }

        $types = [];
        for ($i = 0; $i < 12; $i++) {
            $types[] = $this->typeId('نوع معاملة '.$i, $supers[$i % 4]);
        }

        $accounts = [];
        foreach ([$usd, $ils] as $currency) {
            for ($i = 0; $i < 10; $i++) {
                $accounts[$currency->id][] = $this->makeAccount($currency, [
                    'name' => 'حساب اختبار رقم '.$i,
                    'notes' => $i % 3 === 0 ? 'ملاحظة على الحساب '.$i : null,
                ])->id;
            }
        }

        $firstId = (int) DB::table('transactions')->max('id') + 1;
        $count = intdiv($lines, 2);
        $start = strtotime($from);

        foreach (array_chunk(range(0, $count - 1), 500) as $slice) {
            DB::table('transactions')->insert(array_map(fn (int $n) => [
                'id' => $firstId + $n,
                'fiscal_year_id' => $fiscalYear->id,
                'transaction_type_id' => $types[$n % 12],
                'transaction_number' => 'TRX-'.($firstId + $n),
                'transaction_time' => date('Y-m-d H:i:s', $start + intdiv($n, 3) * 7),
                'description' => 'صرف دفعة لمشروع الإغاثة رقم '.$n.' وفق المحضر المعتمد للجنة المالية',
                'notes' => $n % 4 === 0 ? 'ملاحظة على المعاملة رقم '.$n : null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $slice));
        }

        foreach (array_chunk(range(0, $count - 1), 250) as $slice) {
            $rows = [];

            foreach ($slice as $n) {
                $currency = $n % 5 === 0 ? $ils : $usd;
                $amount = round(10 + ($n * 37 % 10000) / 7, 2);

                foreach ([[$amount, 0], [0, $amount]] as $side => [$debit, $credit]) {
                    $rows[] = [
                        'transaction_id' => $firstId + $n,
                        'account_id' => $accounts[$currency->id][($n + $side) % 10],
                        'currency_id' => $currency->id,
                        'amount_currency' => $amount,
                        'fx_rate' => 1,
                        'debit_base' => $debit,
                        'credit_base' => $credit,
                        'line_role' => null,
                        'description' => 'سطر '.($side ? 'دائن' : 'مدين').' للمعاملة '.$n,
                        'notes' => $n % 6 === 0 ? 'ملاحظة على سطر القيد '.$n : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('transaction_lines')->insert($rows);
        }
    }

    private function typeId(string $name, ?int $superTypeId): int
    {
        return (int) DB::table('transactions_types')->insertGetId([
            'name' => $name,
            'transaction_super_type_id' => $superTypeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tx(string $time, int $typeId, array $attrs = []): Transaction
    {
        return $this->makeTransaction(array_merge([
            'transaction_time' => $time,
            'transaction_type_id' => $typeId,
            'description' => 'بيان '.$time,
        ], $attrs));
    }

    private function projectCostId(Currency $currency, ?string $notes): int
    {
        $superId = DB::table('projects_super')->insertGetId(['name' => 'مظلة', 'created_at' => now(), 'updated_at' => now()]);
        $statusId = DB::table('projects_status')->insertGetId(['name' => 'قيد التنفيذ', 'created_at' => now(), 'updated_at' => now()]);
        $projectId = DB::table('projects')->insertGetId([
            'name' => 'مشروع اختبار',
            'project_super_id' => $superId,
            'project_status_id' => $statusId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('projects_costs')->insertGetId([
            'project_id' => $projectId,
            'amount' => 1000,
            'currency_id' => $currency->id,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function receipt(int $transactionId, int $projectCostId, string $notes): int
    {
        return (int) DB::table('project_cost_receipts')->insertGetId([
            'project_cost_id' => $projectCostId,
            'transaction_id' => $transactionId,
            'amount' => 10,
            'date' => '2026-07-10',
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
