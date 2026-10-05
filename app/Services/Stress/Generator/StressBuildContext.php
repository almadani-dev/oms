<?php

namespace App\Services\Stress\Generator;

use App\Models\Currency;
use App\Models\User;
use App\Services\Audit\AuditActorContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shared state of one build: deterministic sources, resolved lookups, the
 * synthetic users, id counters, the account registry, transaction
 * numbering and the cents-exact ledger.
 */
final class StressBuildContext
{
    public readonly StressCalendar $calendar;

    public readonly AccountRegistry $accounts;

    public readonly AuditJournal $journal;

    /** @var array<int, array{table: string, id: int, dir: string, prefix: string, day: int, amount: float, at: string, by: int, deleted_at: ?string}> */
    public array $attachmentPlans = [];

    /** @var array<int, int> partner id => first day it may be referenced */
    public array $partnerFrom = [];

    /** @var array<int, string> partner id => partner type name */
    public array $partnerTypeName = [];

    /** @var array<int, float> donor partner id => selection weight (high/medium/occasional tiers) */
    public array $donorWeights = [];

    /** @var array<int, int> implementing partners (disbursement / execution counterparties) */
    public array $implementers = [];

    /** @var array<int, int> supplier partners (expenses) */
    public array $supplierPartners = [];

    /** @var array<int, int> exchange-house partners (general exchanges) */
    public array $exchangers = [];

    /** @var array<string, int> table => last id handed out */
    private array $ids = [];

    /** @var array<string, int> "PREFIX-YYYY-" => last number */
    private array $numbers = [];

    /** @var array<int, int> account id => balance in cents (debit − credit) */
    public array $balances = [];

    /** @var array<string, array{id: int, code: string, name: string}> by ISO code */
    public array $currencies = [];

    /** @var array<int, Currency> */
    private array $currencyModels = [];

    /** @var array<string, int> account type name => id */
    public array $accountTypes = [];

    /** @var array<string, int> bank type name => id */
    public array $banks = [];

    /** @var array<string, int> */
    public array $partnerTypes = [];

    /** @var array<string, int> */
    public array $statuses = [];

    /** @var array<string, array{id: int, prefix: string}> by code_prefix */
    public array $supers = [];

    /** @var array<string, int> transaction type name => id */
    public array $transactionTypes = [];

    /** @var array<int, int> year => fiscal_year id */
    public array $fiscalYears = [];

    /** @var array<string, User> role key => user */
    public array $users = [];

    /** @var array<int, AuditActorContext> user id => actor */
    private array $actors = [];

    /** @var array<string, array<string, float>> Y-m => currency id => units per USD */
    public array $rates = [];

    public function __construct(
        public readonly string $connection,
        public readonly StressRandom $random,
        public readonly StressScale $scale,
        public readonly BulkWriter $writer,
        public readonly SyntheticText $text,
        public readonly ProductionFlowBuilders $builders,
        public readonly StressTransactionDescriber $describer,
    ) {
        $this->calendar = new StressCalendar;
        $this->accounts = new AccountRegistry;
        $this->journal = new AuditJournal;
    }

    public function nextId(string $table): int
    {
        if (! isset($this->ids[$table])) {
            $this->ids[$table] = (int) DB::connection($this->connection)->table($table)->max('id');
        }

        return ++$this->ids[$table];
    }

    public function nextTransactionNumber(string $prefix, int $year): string
    {
        $key = $prefix.'-'.$year.'-';
        $this->numbers[$key] = ($this->numbers[$key] ?? 0) + 1;

        // Same MAX+1, 4-digit-padded format as GeneratesSequentialTransactionNumbers.
        return $key.str_pad((string) $this->numbers[$key], 4, '0', STR_PAD_LEFT);
    }

    public function applyToLedger(int $accountId, float $debit, float $credit): void
    {
        $this->balances[$accountId] = ($this->balances[$accountId] ?? 0)
            + (int) round($debit * 100) - (int) round($credit * 100);
    }

    public function loadLookups(): void
    {
        $db = DB::connection($this->connection);

        foreach ($db->table('currencies')->whereNull('deleted_at')->get() as $c) {
            $this->currencies[$c->code] = ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name];
        }

        $this->accountTypes = $db->table('accounts_type')->whereNull('deleted_at')->pluck('id', 'name')->map(fn ($v) => (int) $v)->all();
        $this->banks = $db->table('bank_types')->whereNull('deleted_at')->pluck('id', 'name')->map(fn ($v) => (int) $v)->all();
        $this->partnerTypes = $db->table('partners_types')->whereNull('deleted_at')->pluck('id', 'name')->map(fn ($v) => (int) $v)->all();
        $this->statuses = $db->table('projects_status')->whereNull('deleted_at')->pluck('id', 'name')->map(fn ($v) => (int) $v)->all();
        $this->transactionTypes = $db->table('transactions_types')->whereNull('deleted_at')->pluck('id', 'name')->map(fn ($v) => (int) $v)->all();

        foreach ($db->table('projects_super')->whereNull('deleted_at')->get() as $s) {
            $this->supers[strtoupper((string) $s->code_prefix)] = ['id' => (int) $s->id, 'prefix' => strtoupper((string) $s->code_prefix)];
        }

        foreach (['USD', 'ILS', 'EUR'] as $code) {
            $this->requireKey($this->currencies, $code, 'currency');
        }

        foreach ([StressNames::STATUS_INCOMPLETE, StressNames::STATUS_COMPLETE] as $name) {
            $this->requireKey($this->statuses, $name, 'project status');
        }

        foreach (StressNames::REQUIRED_ACCOUNT_TYPES as $name) {
            $this->requireKey($this->accountTypes, $name, 'account type');
        }

        foreach (StressNames::REQUIRED_TRANSACTION_TYPES as $name) {
            $this->requireKey($this->transactionTypes, $name, 'transaction type');
        }

        $this->requireKey($this->supers, 'MUWAKHA', 'project super');
    }

    public function currencyId(string $code): int
    {
        return $this->currencies[$code]['id'];
    }

    public function currencyCode(int $id): string
    {
        foreach ($this->currencies as $code => $currency) {
            if ($currency['id'] === $id) {
                return $code;
            }
        }

        throw new RuntimeException("Unknown currency #{$id}.");
    }

    public function currencyModel(int $id): Currency
    {
        if (! isset($this->currencyModels[$id])) {
            $code = $this->currencyCode($id);
            $model = new Currency;
            $model->forceFill(['id' => $id, 'code' => $code, 'name' => $this->currencies[$code]['name']]);
            $this->currencyModels[$id] = $model;
        }

        return $this->currencyModels[$id];
    }

    public function fiscalYearFor(int $day): int
    {
        return $this->fiscalYears[$this->calendar->year($day)];
    }

    /** Units of $currency per 1 USD in the month of $day. */
    public function rate(int $currencyId, int $day): float
    {
        return $this->rates[$this->calendar->monthKey($day)][$currencyId] ?? 1.0;
    }

    public function user(string $roleKey): User
    {
        return $this->users[$roleKey] ?? throw new RuntimeException("Unknown stress user [{$roleKey}].");
    }

    /**
     * The user who records an operation on $day: the inactive (former)
     * accountant only before their deactivation.
     *
     * @param  array<string, float>  $weights  role key => weight
     */
    public function actorFor(array $weights, int $day, StressRandom $random): User
    {
        if ($day >= StressNames::FORMER_ACCOUNTANT_UNTIL_DAY) {
            unset($weights['accountant_former']);
        }

        return $this->user((string) $random->weighted($weights));
    }

    public function actor(User $user): AuditActorContext
    {
        return $this->actors[$user->id] ??= AuditActorContext::forUser($user, $this->syntheticRequest($user));
    }

    /** The anonymous actor of a failed login attempt from $user's workstation. */
    public function guestActor(User $user): AuditActorContext
    {
        return $this->actors[-$user->id] ??= AuditActorContext::guest($this->syntheticRequest($user));
    }

    /**
     * A Livewire POST from a synthetic office workstation — the request
     * shape AuditActorContext records for real panel actions.
     */
    private function syntheticRequest(User $user): Request
    {
        $request = Request::create('/livewire/update', 'POST', server: [
            'REMOTE_ADDR' => '10.20.0.'.(10 + $user->id),
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) OMS-Stress-Synthetic/1.0',
        ]);
        $route = new Route('POST', 'livewire/update', []);
        $route->name('default-livewire.update');
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function requireKey(array $map, string $key, string $what): void
    {
        if (! array_key_exists($key, $map)) {
            throw new RuntimeException("The stress database is missing the {$what} [{$key}]. Was it created by oms:stress:create-database?");
        }
    }
}
