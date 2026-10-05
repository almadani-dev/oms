<?php

namespace App\Services\Stress\Generator\Phases;

use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\Transaction;
use App\Services\Audit\Financial\FinancialAuditSubject;
use App\Services\Stress\Generator\AccountFactory;
use App\Services\Stress\Generator\LedgerPoster;
use App\Services\Stress\Generator\StressBuildContext;
use App\Services\Stress\Generator\StressNames;
use App\Services\Stress\Generator\StressRandom;
use App\Services\Validation\FinancialAmountGuard;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The five financial workflows, planned per project cost (receipts ->
 * disbursements -> execution payments) and as organization-wide activity
 * (general expenses, general exchanges), then emitted month by month in
 * chronological order so transaction ids and MAX+1 numbers grow with time.
 *
 * Every payload comes from the CURRENT production builders and passes the
 * real guards. Planning keeps every amount in integer cents, so "fully
 * received / disbursed / executed" states are exact, never approximate.
 */
final class FinancialFlowsPhase
{
    private const RECEIPT = 1;

    private const DISBURSEMENT = 2;

    private const EXECUTION = 3;

    private const EXPENSE = 4;

    private const EXCHANGE = 5;

    private const ACTORS = ['accountant' => 45, 'accountant_2' => 30, 'accountant_former' => 15, 'admin' => 10];

    /** @var array<string, array<int, array<string, mixed>>> Y-m => planned events */
    private array $buckets = [];

    /** @var array<int, array{id: int, cost: int}> plan key => emitted budget */
    private array $budgets = [];

    private int $planKey = 0;

    private int $sequence = 0;

    /** @var array<int, ProjectCost> */
    private array $costModels = [];

    /** @var array<int, int> project id => last execution day (fully executed projects) */
    public array $completionDays = [];

    /** @var array<string, int> */
    public array $emitted = [];

    private StressRandom $random;

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @param  array<int, array<string, mixed>>  $costs
     * @param  array<int, string>  $partnerNames
     */
    public function __construct(
        private readonly StressBuildContext $ctx,
        private readonly AccountFactory $factory,
        private readonly LedgerPoster $poster,
        private readonly MuwakhaPhase $muwakha,
        private readonly array $projects,
        private readonly array $costs,
        private readonly array $partnerNames,
    ) {
        $this->random = $ctx->random->fork('flows');
    }

    /**
     * @param  Closure(string): void  $log
     */
    public function run(Closure $log): void
    {
        foreach ($this->costs as $cost) {
            $this->planCost($cost);
        }

        $this->planExpenses();
        $this->planExchanges();
        ksort($this->buckets);

        // Donor accounts now know the first day they are needed.
        $this->factory->flushDeferred();
        $this->ctx->writer->flushAll();

        $log(sprintf('Planned %d events across %d months; emitting chronologically …', array_sum(array_map('count', $this->buckets)), count($this->buckets)));

        foreach (array_keys($this->buckets) as $ym) {
            $events = $this->buckets[$ym];
            unset($this->buckets[$ym]);

            usort($events, fn (array $a, array $b) => [$a['d'], $a['k'], $a['s']] <=> [$b['d'], $b['k'], $b['s']]);

            foreach ($events as $event) {
                match ($event['k']) {
                    self::RECEIPT => $this->emitReceipt($event),
                    self::DISBURSEMENT => $this->emitDisbursement($event),
                    self::EXECUTION => $this->emitExecution($event),
                    self::EXPENSE => $this->emitExpense($event),
                    default => $this->emitExchange($event),
                };
            }

            $this->ctx->writer->flushAll();
            $log(sprintf('  %s: %d operations (peak memory %.0f MB)', $ym, count($events), memory_get_peak_usage(true) / 1048576));
        }
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    /**
     * @param  array{id: int, project: int, currency: int, cents: int, day: int}  $cost
     */
    private function planCost(array $cost): void
    {
        $project = $this->projects[$cost['project']];

        if ($project['deleted'] || $project['state'] === 'none') {
            return;
        }

        $r = $this->random;
        $last = $this->ctx->calendar->lastDay();
        $from = max($cost['day'], $project['approval']);
        $to = min($last, $project['end'] + 90);

        if ($to <= $from) {
            $to = min($last, $from + 30);
        }

        $intensity = $this->ctx->scale->perCostIntensity();
        $state = $project['state'];
        $cur = $cost['currency'];

        // ---- receipts -------------------------------------------------
        $recvFrac = match ($state) {
            'part_recv' => $r->int(20, 90) / 100,
            'part_disb', 'full_disb', 'part_exec' => $r->chance(0.6) ? 1.0 : $r->int(55, 95) / 100,
            default => 1.0,
        };
        $receivedCents = $recvFrac >= 1.0 ? $cost['cents'] : max(100, (int) round($cost['cents'] * $recvFrac));
        $receiptCount = max(1, min(16, (int) round($r->normal(7, 3) * $intensity), intdiv($receivedCents, 1000)));
        $receiving = $this->ctx->accounts->pick($r, 'org', $cur, $from) ?? $this->ctx->accounts->pick($r, 'org', $cur, $last);
        // Projects without a donor are funded by any donor that already exists.
        $donor = $project['donor'] ?? (int) $r->weighted(array_filter(
            $this->ctx->donorWeights,
            fn (float $w, int $id) => ($this->ctx->partnerFrom[$id] ?? 0) <= $from,
            ARRAY_FILTER_USE_BOTH,
        ) ?: $this->ctx->donorWeights);
        $receiptSpan = max(1, (int) (($to - $from) * 0.7));
        $days = [];

        for ($i = 0; $i < $receiptCount; $i++) {
            $days[] = min($last, $from + $r->int(0, $receiptSpan));
        }

        sort($days);
        $donorAccount = $this->factory->donorAccount($donor, $this->partnerNames[$donor] ?? 'جهة مانحة', $cur, $days[0]);
        $receipts = [];

        foreach ($r->splitCents($receivedCents, $receiptCount) as $i => $cents) {
            $receipts[] = ['d' => $days[$i], 'cents' => $cents];
            $this->add(['k' => self::RECEIPT, 'd' => $days[$i], 'cost' => $cost['id'], 'cents' => $cents, 'debit' => $receiving, 'credit' => $donorAccount, 'partner' => $donor, 'del' => null]);
        }

        if ($r->chance(0.015 * max(1, $receiptCount / 3))) {
            // A mistaken duplicate receipt, deleted a few days later.
            $ghostDay = $r->pick($days);
            $this->add(['k' => self::RECEIPT, 'd' => $ghostDay, 'cost' => $cost['id'], 'cents' => $r->pick($receipts)['cents'], 'debit' => $receiving, 'credit' => $donorAccount, 'partner' => $donor, 'del' => min($last, $ghostDay + $r->int(0, 6))]);
        }

        if (in_array($state, ['part_recv', 'full_recv'], true)) {
            return;
        }

        // ---- disbursements -------------------------------------------
        $disbFrac = match ($state) {
            'part_disb' => $r->int(30, 90) / 100,
            'part_exec' => $r->chance(0.5) ? 1.0 : $r->int(60, 95) / 100,
            default => 1.0,
        };
        $disbursedCents = $disbFrac >= 1.0 ? $receivedCents : max(1000, (int) round($receivedCents * $disbFrac));
        $disbCount = max(1, min(18, (int) round($r->normal(8, 3.5) * $intensity), intdiv($disbursedCents, 2000)));
        $parts = $r->splitCents($disbursedCents, $disbCount);
        $isMuwakha = $project['super'] === 'MUWAKHA';
        $cumReceived = 0;
        $cumDisbursed = 0;
        $receiptIndex = 0;
        $previousDay = $from;
        $executionDays = [];

        foreach ($parts as $originalCents) {
            $cumDisbursed += $originalCents;

            // Never disburse more than was received by that day.
            while ($cumReceived < $cumDisbursed && $receiptIndex < count($receipts)) {
                $cumReceived += $receipts[$receiptIndex]['cents'];
                $receiptIndex++;
            }

            $after = $receipts[max(0, $receiptIndex - 1)]['d'];
            $day = max($previousDay, min($last, $after + $r->int(1, 25)));
            $previousDay = $day;

            $destCur = $this->destinationCurrency($cur, $isMuwakha);
            [$adminPct, $transferPct] = $this->deductionShape(0.25, 0.25, 0.15);
            $fx = $destCur === $cur ? 1.0 : $this->fx($cur, $destCur, $day);
            $original = $originalCents / 100;
            [, , , $final] = $this->ctx->builders->deriveAmounts($original, $adminPct, $transferPct, $fx);

            $destination = $this->ctx->accounts->pick($r, 'org', $destCur, $day, [$receiving => true]);
            $key = ++$this->planKey;

            $this->add([
                'k' => self::DISBURSEMENT, 'd' => $day, 'cost' => $cost['id'], 'orig' => $original,
                'adm' => $adminPct, 'trf' => $transferPct, 'dcur' => $destCur, 'fx' => $fx,
                'src' => $receiving, 'admA' => $this->ctx->accounts->pick($r, 'admin', $cur, $day), 'trfA' => $this->ctx->accounts->pick($r, 'transfer', $cur, $day),
                'dst' => $destination, 'partner' => $this->implementer($day) ?? $donor, 'key' => $key, 'del' => null,
            ]);

            // ---- execution payments for this disbursement -------------
            // part_exec mixes not-yet-executed, partially and fully executed
            // disbursements, so "remaining execution" covers every case.
            $execFrac = match ($state) {
                'full_exec' => 1.0,
                'part_exec' => match ($r->weighted(['none' => 30, 'partial' => 40, 'full' => 30])) {
                    'none' => 0.0,
                    'partial' => $r->int(20, 95) / 100,
                    default => 1.0,
                },
                default => 0.0,
            };

            if ($execFrac > 0) {
                $finalCents = (int) round($final * 100);
                $executedCents = $execFrac >= 1.0 ? $finalCents : max(100, (int) round($finalCents * $execFrac));
                $count = max(1, min(6, (int) round($r->normal(2.6, 1.2)), intdiv($executedCents, 500)));

                foreach ($r->splitCents($executedCents, $count) as $cents) {
                    $execDay = min($last, $day + $r->int(0, 60));
                    $beneficiary = ($isMuwakha ? $this->muwakha->beneficiary($r, $cost['project'], $destCur, $execDay) : null)
                        ?? $this->ctx->accounts->pick($r, $r->chance(0.5) ? 'individual' : 'supplier', $destCur, $execDay)
                        ?? $this->ctx->accounts->pick($r, 'individual', $destCur, $execDay)
                        ?? $this->ctx->accounts->pick($r, 'supplier', $destCur, $execDay);

                    if ($beneficiary === null) {
                        continue;
                    }

                    $credit = $r->chance(0.9) ? $destination : ($this->ctx->accounts->pick($r, 'org', $destCur, $execDay, [$beneficiary => true]) ?? $destination);
                    $this->add(['k' => self::EXECUTION, 'd' => $execDay, 'key' => $key, 'cents' => $cents, 'ben' => $beneficiary, 'credit' => $credit, 'partner' => $this->implementer($execDay) ?? $donor, 'del' => null]);
                    $executionDays[] = $execDay;

                    if ($r->chance(0.012)) {
                        $this->add(['k' => self::EXECUTION, 'd' => $execDay, 'key' => $key, 'cents' => max(100, intdiv($cents, 3)), 'ben' => $beneficiary, 'credit' => $credit, 'partner' => $this->implementer($execDay) ?? $donor, 'del' => min($last, $execDay + $r->int(0, 5))]);
                    }
                }
            } elseif ($r->chance(0.012)) {
                // A disbursement entered twice and deleted (never executed).
                $this->add([
                    'k' => self::DISBURSEMENT, 'd' => $day, 'cost' => $cost['id'], 'orig' => $original,
                    'adm' => $adminPct, 'trf' => $transferPct, 'dcur' => $destCur, 'fx' => $fx,
                    'src' => $receiving, 'admA' => $this->ctx->accounts->pick($r, 'admin', $cur, $day), 'trfA' => $this->ctx->accounts->pick($r, 'transfer', $cur, $day),
                    'dst' => $destination, 'partner' => $this->implementer($day) ?? $donor, 'key' => null, 'del' => min($last, $day + $r->int(0, 4)),
                ]);
            }
        }

        if ($state === 'full_exec' && $executionDays !== []) {
            $this->completionDays[$cost['project']] = max($this->completionDays[$cost['project']] ?? 0, max($executionDays));
        }
    }

    private function planExpenses(): void
    {
        $r = $this->random;
        $total = $this->ctx->scale->generalExpenses();
        $last = $this->ctx->calendar->lastDay();
        $kinds = ['salary' => 6, 'fuel' => 25, 'maintenance' => 15, 'electricity' => 9, 'aid_medical' => 27, 'aid_marriage' => 18];

        for ($i = 0; $i < $total; $i++) {
            $kind = (string) $r->weighted($kinds);
            $day = $this->ctx->calendar->randomDay($r);
            $code = match ($kind) {
                'fuel', 'electricity' => $r->chance(0.9) ? 'ILS' : 'USD',
                'salary' => $r->chance(0.7) ? 'ILS' : 'USD',
                'maintenance' => $r->chance(0.7) ? 'ILS' : 'USD',
                default => $r->chance(0.75) ? 'ILS' : 'USD',
            };
            $cur = $this->ctx->currencyId($code);
            $pool = match ($kind) {
                'salary' => 'employee',
                'aid_medical', 'aid_marriage' => 'individual',
                default => 'supplier',
            };
            $debit = $this->ctx->accounts->pick($r, $pool, $cur, $day);
            $credit = $this->ctx->accounts->pick($r, 'org', $cur, $day);

            if ($debit === null || $credit === null) {
                continue;
            }

            $amount = match ($kind) {
                'salary' => $code === 'ILS' ? $r->amount(3_800, 0.3, 1_800, 9_000) : $r->amount(1_100, 0.3, 500, 2_600),
                'fuel' => $r->amount(900, 0.6, 50, 9_000),
                'maintenance' => $r->amount(1_200, 0.7, 80, 15_000),
                'electricity' => $r->amount(1_500, 0.5, 100, 12_000),
                default => $code === 'ILS' ? $r->amount(1_500, 0.7, 100, 20_000) : $r->amount(400, 0.7, 30, 6_000),
            };

            $event = ['k' => self::EXPENSE, 'd' => $day, 'kind' => $kind, 'cur' => $cur, 'amount' => $amount, 'debit' => $debit, 'credit' => $credit,
                'partner' => in_array($pool, ['supplier'], true) && $r->chance(0.6) ? $this->pickPartner($this->ctx->supplierPartners, $day) : null, 'del' => null];
            $this->add($event);

            if ($r->chance(0.015)) {
                $this->add(['del' => min($last, $day + $r->int(0, 7))] + $event);
            }
        }
    }

    private function planExchanges(): void
    {
        $r = $this->random;
        $total = $this->ctx->scale->generalExchanges();
        $last = $this->ctx->calendar->lastDay();
        $pairs = ['USD>ILS' => 40, 'USD>USD' => 20, 'ILS>ILS' => 10, 'EUR>USD' => 15, 'EUR>ILS' => 5, 'ILS>USD' => 10];

        for ($i = 0; $i < $total; $i++) {
            [$s, $d] = explode('>', (string) $r->weighted($pairs));
            $scur = $this->ctx->currencyId($s);
            $dcur = $this->ctx->currencyId($d);
            $day = $this->ctx->calendar->randomDay($r);
            $source = $this->ctx->accounts->pick($r, 'org', $scur, $day);
            $destination = $this->ctx->accounts->pick($r, 'org', $dcur, $day, $source === null ? [] : [$source => true]);

            if ($source === null || $destination === null) {
                continue;
            }

            [$adminPct, $transferPct] = $this->deductionShape(0.15, 0.05, 0.30);
            $original = match ($s) {
                'USD' => $r->amount(8_000, 0.9, 200, 150_000),
                'EUR' => $r->amount(6_000, 0.9, 200, 120_000),
                default => $r->amount(25_000, 0.9, 500, 500_000),
            };

            $event = [
                'k' => self::EXCHANGE, 'd' => $day, 'scur' => $scur, 'dcur' => $dcur, 'orig' => $original,
                'adm' => $adminPct, 'trf' => $transferPct, 'fx' => $scur === $dcur ? 1.0 : $this->fx($scur, $dcur, $day),
                'src' => $source, 'admA' => $this->ctx->accounts->pick($r, 'admin', $scur, $day), 'trfA' => $this->ctx->accounts->pick($r, 'transfer', $scur, $day),
                'dst' => $destination, 'partner' => $r->chance(0.5) ? $this->pickPartner($this->ctx->exchangers, $day) : null, 'del' => null,
            ];
            $this->add($event);

            if ($r->chance(0.015)) {
                $this->add(['del' => min($last, $day + $r->int(0, 5))] + $event);
            }
        }
    }

    /**
     * @return array{0: float, 1: float} [administrative %, transfer %]
     */
    private function deductionShape(float $both, float $adminOnly, float $transferOnly): array
    {
        $r = $this->random;
        $shape = $r->weighted(['both' => $both, 'admin' => $adminOnly, 'transfer' => $transferOnly, 'none' => max(0.0, 1 - $both - $adminOnly - $transferOnly)]);
        $admin = in_array($shape, ['both', 'admin'], true) ? (float) $r->pick([2.5, 3, 5, 7, 10]) : 0.0;
        $transfer = in_array($shape, ['both', 'transfer'], true) ? (float) $r->pick([0.5, 1, 1.5, 2, 3]) : 0.0;

        return [$admin, $transfer];
    }

    private function destinationCurrency(int $costCurrency, bool $muwakha): int
    {
        $r = $this->random;
        $usd = $this->ctx->currencyId('USD');
        $ils = $this->ctx->currencyId('ILS');
        $eur = $this->ctx->currencyId('EUR');

        if ($muwakha) {
            return $r->chance(0.85) ? $ils : $usd;
        }

        if ($r->chance(0.7)) {
            return $costCurrency;
        }

        return match ($costCurrency) {
            $usd => $r->chance(0.7) ? $ils : $eur,
            $eur => $r->chance(0.6) ? $usd : $ils,
            default => $usd,
        };
    }

    /** Market-like rate from the monthly history, typed to 4 decimals. */
    private function fx(int $from, int $to, int $day): float
    {
        $rate = $this->ctx->rate($to, $day) / $this->ctx->rate($from, $day);

        return round($rate * (1 + $this->random->normal(0, 0.003)), 4);
    }

    private function implementer(int $day): ?int
    {
        return $this->pickPartner($this->ctx->implementers, $day);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function pickPartner(array $ids, int $day): ?int
    {
        for ($try = 0; $try < 6 && $ids !== []; $try++) {
            $id = $this->random->pick($ids);

            if (($this->ctx->partnerFrom[$id] ?? 0) <= $day) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function add(array $event): void
    {
        $event['s'] = ++$this->sequence;
        $this->buckets[$this->ctx->calendar->monthKey($event['d'])][] = $event;
    }

    // ------------------------------------------------------------------
    // Emission (production builders + real guards)
    // ------------------------------------------------------------------

    /**
     * Who recorded it, when, and — for ~3% — a later edit.
     *
     * @return array{by: int, at: string, edit_at: ?string, edit_by: ?int, deleted_at: ?string, delete_by: ?int}
     */
    private function lifecycle(int $day, ?int $deleteDay, bool $editable = true): array
    {
        $r = $this->random;
        $user = $this->ctx->actorFor(self::ACTORS, $day, $r);
        $at = $this->ctx->calendar->timestamp($r, $day, $r->chance(0.7) ? 0 : $r->int(1, 3));
        $editAt = null;
        $editBy = null;
        $deletedAt = null;
        $deleteBy = null;

        if ($deleteDay !== null) {
            $deletedAt = max($at, $this->ctx->calendar->timestamp($r, $deleteDay));
            $deletedAt = $deletedAt === $at ? CarbonImmutable::parse($at)->addMinutes(9)->format('Y-m-d H:i:s') : $deletedAt;
            $deleteBy = $this->ctx->actorFor(['admin' => 6, 'accountant' => 3, 'accountant_2' => 2], $deleteDay, $r)->id;
        } elseif ($editable && $r->chance(0.03)) {
            $editDay = min($this->ctx->calendar->lastDay(), $day + $r->int(1, 10));
            $editAt = max($at, $this->ctx->calendar->timestamp($r, $editDay));
            $editAt = $editAt === $at ? CarbonImmutable::parse($at)->addMinutes(13)->format('Y-m-d H:i:s') : $editAt;
            $editBy = $this->ctx->actorFor(['admin' => 5, 'accountant' => 3, 'accountant_2' => 2], $editDay, $r)->id;
        }

        return ['by' => $user->id, 'at' => $at, 'edit_at' => $editAt, 'edit_by' => $editBy, 'deleted_at' => $deletedAt, 'delete_by' => $deleteBy];
    }

    /**
     * @param  array{by: int, at: string, edit_at: ?string, edit_by: ?int, deleted_at: ?string}  $life
     * @return array<string, mixed>
     */
    private function header(string $prefix, string $typeName, int $day, ?int $partner, ?string $notes, array $life, bool $linesRebuiltOnEdit = true): array
    {
        $updatedAt = $life['deleted_at'] ?? $life['edit_at'] ?? $life['at'];

        return [
            'prefix' => $prefix,
            'type_id' => $this->ctx->transactionTypes[$typeName],
            'day' => $day,
            'partner_id' => $partner,
            'notes' => $notes,
            'created_at' => $life['at'],
            'created_by' => $life['by'],
            'updated_at' => $updatedAt,
            'updated_by' => $life['edit_by'] ?? $life['by'],
            'deleted_at' => $life['deleted_at'],
            // Edit pages force-delete and recreate the lines (receipts update in place).
            'lines_created_at' => $life['edit_at'] !== null && $linesRebuiltOnEdit ? $life['edit_at'] : $life['at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{by: int, at: string, edit_at: ?string, edit_by: ?int, deleted_at: ?string, delete_by: ?int}  $life
     * @return array<string, mixed>
     */
    private function stamp(array $row, array $life): array
    {
        return $row + [
            'created_by' => $life['by'],
            'updated_by' => $life['edit_by'] ?? $life['by'],
            'created_at' => $life['at'],
            'updated_at' => $life['deleted_at'] ?? $life['edit_at'] ?? $life['at'],
            'deleted_at' => $life['deleted_at'],
        ];
    }

    private function emitReceipt(array $e): void
    {
        $cost = $this->costModel($e['cost']);
        $amount = $e['cents'] / 100;
        $life = $this->lifecycle($e['d'], $e['del']);
        $notes = $this->ctx->text->note(0.25);

        FinancialAmountGuard::assertSimpleAmount($amount, 'amount', 'مبلغ الاستلام');

        // Builders stamp created_by from auth(); EditProjectCostReceipt updates
        // the lines in place, so an edit only re-stamps updated_by.
        $this->actAs($life['by']);
        $lines = $this->ctx->builders->receiptLines(['debit_account_id' => $e['debit'], 'credit_account_id' => $e['credit'], 'amount' => $amount], $cost);

        if ($life['edit_by'] !== null) {
            foreach ($lines as &$line) {
                $line['updated_by'] = $life['edit_by'];
            }
            unset($line);
        }

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, (int) $cost->currency_id);

        $transaction = new Transaction;
        $transaction->setRelation('partner', $this->partnerModel($e['partner']));

        $posted = $this->poster->post(
            $this->header('REC', StressNames::TX_RECEIPT, $e['d'], $e['partner'], $notes, $life, linesRebuiltOnEdit: false),
            $lines,
            $this->ctx->builders->receiptPurposes($cost),
            $this->ctx->builders->receiptSummary($transaction, $cost),
        );

        $id = $this->ctx->nextId('project_cost_receipts');
        $this->ctx->writer->add('project_cost_receipts', $this->stamp([
            'id' => $id,
            'project_cost_id' => $e['cost'],
            'transaction_id' => $posted['id'],
            'amount' => $amount,
            'currency_id' => (int) $cost->currency_id,
            'date' => $this->ctx->calendar->date($e['d']),
            'notes' => $notes,
        ], $life));

        $this->after('project_cost_receipts', FinancialAuditSubject::ProjectCostReceipt, $id, $life, $e['d'], $amount, 'receipts', 'receive', [
            'amount' => round($amount * (1 + ($this->random->chance(0.5) ? 1 : -1) * $this->random->int(5, 20) / 100), 2),
        ], ['notes' => $notes === null ? 'ملاحظة سابقة قبل التعديل' : null]);
    }

    private function emitDisbursement(array $e): void
    {
        $cost = $this->costModel($e['cost']);
        $costCur = (int) $cost->currency_id;
        $life = $this->lifecycle($e['d'], $e['del']);
        $notes = $this->ctx->text->note(0.3);

        [$admin, $transfer, $after, $final] = $this->ctx->builders->deriveAmounts($e['orig'], $e['adm'], $e['trf'], $e['fx']);

        FinancialAmountGuard::assertDisbursementInputs($e['orig'], $e['adm'], $e['trf'], $e['fx'], $after, $final);
        FinancialAmountGuard::assertDeductionsAreRecordable($e['adm'], $admin, $e['trf'], $transfer);

        $this->actAs($life['edit_by'] ?? $life['by']);
        $lines = $this->ctx->builders->disbursementLines($e['cost'], $costCur, [
            'source_account_id' => $e['src'],
            'admin_account_id' => $admin > 0 ? $e['admA'] : null,
            'transfer_account_id' => $transfer > 0 ? $e['trfA'] : null,
            'destination_account_id' => $e['dst'],
            'disbursement_currency_id' => $e['dcur'],
        ], ['original' => $e['orig'], 'admin' => $admin, 'transfer' => $transfer, 'final' => $final, 'fx' => $e['fx']]);

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($lines['source'], $lines['admin'] ?? null, $lines['transfer'] ?? null, $lines['destination'], $costCur, (int) $e['dcur']);

        $posted = $this->poster->post(
            $this->header('BUD', StressNames::TX_DISBURSEMENT, $e['d'], $e['partner'], $notes, $life),
            $lines,
            $this->ctx->builders->disbursementPurposes(),
            $this->ctx->builders->disbursementSummary($cost),
        );

        $id = $this->ctx->nextId('project_cost_budgets');
        $this->ctx->writer->add('project_cost_budgets', $this->stamp([
            'id' => $id,
            'project_cost_id' => $e['cost'],
            'transaction_id' => $posted['id'],
            'original_amount' => $e['orig'],
            'amount_after_deductions' => $after,
            'source_currency_id' => $costCur,
            'disbursement_currency_id' => $e['dcur'],
            'administrative_percentage' => $e['adm'],
            'transfer_percentage' => $e['trf'],
            'exchange_percentage' => 0,
            'fx_rate' => $e['fx'],
            'final_amount' => $final,
            'notes' => $notes,
        ], $life));

        if ($e['key'] !== null) {
            $this->budgets[$e['key']] = ['id' => $id, 'cost' => $e['cost']];
        }

        // Disbursement edits stay amount-neutral (executions follow from the
        // final amount): only the notes change.
        $this->after('project_cost_budgets', FinancialAuditSubject::ProjectDisbursement, $id, $life, $e['d'], $final, 'payments', 'pay', [], ['notes' => $notes === null ? 'ملاحظة سابقة قبل التعديل' : null]);
    }

    private function emitExecution(array $e): void
    {
        $budget = $this->budgets[$e['key']] ?? null;

        if ($budget === null) {
            return;
        }

        $amount = $e['cents'] / 100;
        $currency = $this->ctx->accounts->get($e['credit'])['cur'];
        $life = $this->lifecycle($e['d'], $e['del']);
        $notes = $this->ctx->text->note(0.2);

        FinancialAmountGuard::assertSimpleAmount($amount, 'amount', 'مبلغ التنفيذ');

        $this->actAs($life['edit_by'] ?? $life['by']);
        $lines = $this->ctx->builders->executionLines($e['ben'], $e['credit'], $currency, $amount);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, $currency);

        $budgetModel = new ProjectCostBudget;
        $budgetModel->forceFill(['id' => $budget['id'], 'project_cost_id' => $budget['cost']]);
        $budgetModel->setRelation('projectCost', $this->costModel($budget['cost']));

        $posted = $this->poster->post(
            $this->header('PAY', StressNames::TX_EXECUTION, $e['d'], $e['partner'], $notes, $life),
            $lines,
            $this->ctx->builders->executionPurposes($budgetModel),
            $this->ctx->builders->executionSummary($budgetModel),
        );

        $id = $this->ctx->nextId('project_cost_budgets_payments');
        $this->ctx->writer->add('project_cost_budgets_payments', $this->stamp([
            'id' => $id,
            'project_cost_budget_id' => $budget['id'],
            'transaction_id' => $posted['id'],
            'amount' => $amount,
            'currency_id' => $currency,
            'date' => $this->ctx->calendar->date($e['d']),
            'notes' => $notes,
        ], $life));

        $this->after('project_cost_budgets_payments', FinancialAuditSubject::ExecutionPayment, $id, $life, $e['d'], $amount, 'execution-payments', 'pay', [], ['notes' => $notes === null ? 'ملاحظة سابقة قبل التعديل' : null]);
    }

    private function emitExpense(array $e): void
    {
        $life = $this->lifecycle($e['d'], $e['del']);
        $notes = $this->ctx->text->note(0.2);
        $description = $this->ctx->text->expenseDescription($e['kind']);

        FinancialAmountGuard::assertSimpleAmount($e['amount'], 'amount', 'مبلغ المصروف');

        $this->actAs($life['edit_by'] ?? $life['by']);
        $lines = $this->ctx->builders->expenseLines($e['debit'], $e['credit'], $e['cur'], $e['amount']);
        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($lines, $e['cur']);

        $expenseModel = new GeneralExpense;
        $expenseModel->forceFill(['description' => $description]);

        $posted = $this->poster->post(
            $this->header('GEN', StressNames::EXPENSE_TYPES[$e['kind']], $e['d'], $e['partner'], $notes, $life),
            $lines,
            $this->ctx->builders->expensePurposes($expenseModel),
            $this->ctx->builders->expenseSummary($expenseModel),
        );

        $id = $this->ctx->nextId('general_expenses');
        $this->ctx->writer->add('general_expenses', $this->stamp([
            'id' => $id,
            'transaction_id' => $posted['id'],
            'amount' => $e['amount'],
            'currency_id' => $e['cur'],
            'date' => $this->ctx->calendar->date($e['d']),
            'partner_id' => $e['partner'],
            'description' => $description,
            'notes' => $notes,
        ], $life));

        $this->after('general_expenses', FinancialAuditSubject::GeneralExpense, $id, $life, $e['d'], $e['amount'], 'general-expenses', 'gen', [
            'amount' => round($e['amount'] * (1 + $this->random->int(5, 25) / 100), 2),
        ], ['notes' => $notes === null ? 'ملاحظة سابقة قبل التعديل' : null]);
    }

    private function emitExchange(array $e): void
    {
        $life = $this->lifecycle($e['d'], $e['del']);
        $notes = $this->ctx->text->note(0.25);

        [$admin, $transfer, $after, $final] = $this->ctx->builders->deriveAmounts($e['orig'], $e['adm'], $e['trf'], $e['fx']);

        FinancialAmountGuard::assertDisbursementInputs($e['orig'], $e['adm'], $e['trf'], $e['fx'], $after, $final);
        FinancialAmountGuard::assertDeductionsAreRecordable($e['adm'], $admin, $e['trf'], $transfer);

        $this->actAs($life['edit_by'] ?? $life['by']);
        $lines = $this->ctx->builders->exchangeLines([
            'source_account_id' => $e['src'],
            'admin_account_id' => $admin > 0 ? $e['admA'] : null,
            'transfer_account_id' => $transfer > 0 ? $e['trfA'] : null,
            'destination_account_id' => $e['dst'],
        ], $e['scur'], $e['dcur'], ['original' => $e['orig'], 'admin' => $admin, 'transfer' => $transfer, 'final' => $final, 'fx' => $e['fx']]);

        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines($lines['source'], $lines['admin'] ?? null, $lines['transfer'] ?? null, $lines['destination'], $e['scur'], $e['dcur']);

        $posted = $this->poster->post(
            $this->header('EXT', StressNames::TX_EXCHANGE, $e['d'], $e['partner'], $notes, $life),
            $lines,
            $this->ctx->builders->exchangePurposes(),
            $this->ctx->builders->exchangeSummary($e['src'], $e['dst']),
        );

        $id = $this->ctx->nextId('general_exchanges');
        $this->ctx->writer->add('general_exchanges', $this->stamp([
            'id' => $id,
            'transaction_id' => $posted['id'],
            'original_amount' => $e['orig'],
            'administrative_percentage' => $e['adm'],
            'transfer_percentage' => $e['trf'],
            'fx_rate' => $e['fx'],
            'final_amount' => $final,
            'source_currency_id' => $e['scur'],
            'disbursement_currency_id' => $e['dcur'],
            'partner_id' => $e['partner'],
            'notes' => $notes,
            'date' => $this->ctx->calendar->date($e['d']),
        ], $life));

        $this->after('general_exchanges', FinancialAuditSubject::GeneralExchange, $id, $life, $e['d'], $final, 'general-exchanges', 'ext', [], ['notes' => $notes === null ? 'ملاحظة سابقة قبل التعديل' : null]);
    }

    /**
     * Journal the edit/delete for the audit phase and plan an attachment.
     *
     * @param  array<string, mixed>  $amountEdit  pre-edit values when the edit changed an amount
     * @param  array<string, mixed>  $notesEdit  pre-edit values when the edit changed notes
     */
    private function after(string $table, FinancialAuditSubject $subject, int $id, array $life, int $day, float $amount, string $dir, string $prefix, array $amountEdit, array $notesEdit): void
    {
        $this->emitted[$table] = ($this->emitted[$table] ?? 0) + 1;

        if ($life['deleted_at'] !== null) {
            $this->ctx->journal->deleted($table, $id, $life['delete_by']);
        }

        if ($life['edit_at'] !== null) {
            // An amount edit where the flow allows it, otherwise a notes edit.
            $before = $amountEdit !== [] && $this->random->chance(0.6) ? $amountEdit : $notesEdit;
            $this->ctx->journal->financialEdit($table, $id, $life['edit_at'], $life['edit_by'], $before);
        }

        if ($this->random->chance(0.015)) {
            $this->ctx->attachmentPlans[] = [
                'table' => $table, 'id' => $id, 'dir' => $dir, 'prefix' => $prefix, 'day' => $day,
                'amount' => $amount, 'at' => $life['at'], 'by' => $life['by'], 'deleted_at' => $life['deleted_at'],
                'delete_by' => $life['delete_by'],
            ];
        }
    }

    private function actAs(int $userId): void
    {
        foreach ($this->ctx->users as $user) {
            if ($user->id === $userId) {
                $this->ctx->builders->actingAs($user);

                return;
            }
        }
    }

    private function costModel(int $costId): ProjectCost
    {
        if (! isset($this->costModels[$costId])) {
            $cost = $this->costs[$costId];
            $project = new Project;
            $project->forceFill(['id' => $cost['project'], 'name' => $this->projects[$cost['project']]['name']]);

            $model = new ProjectCost;
            $model->forceFill(['id' => $costId, 'project_id' => $cost['project'], 'currency_id' => $cost['currency'], 'amount' => $cost['cents'] / 100]);
            $model->setRelation('project', $project);
            $this->costModels[$costId] = $model;
        }

        return $this->costModels[$costId];
    }

    private function partnerModel(?int $partnerId): ?Partner
    {
        if ($partnerId === null) {
            return null;
        }

        $partner = new Partner;
        $partner->forceFill(['id' => $partnerId, 'name' => $this->partnerNames[$partnerId] ?? null]);

        return $partner;
    }
}
