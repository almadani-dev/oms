<?php

namespace App\Services\Stress\Generator;

use App\Models\Account;
use RuntimeException;

/**
 * In-memory chart of synthetic accounts: which pool each account serves,
 * its currency/type/bank (an account is single-currency, exactly as
 * FinancialAccountGuard enforces), and the day range in which it may be
 * selected for a NEW posting (created .. deactivated/replaced/deleted).
 */
final class AccountRegistry
{
    /** @var array<int, array{type: int, bank: int, cur: int, name: string, code: ?string, from: int, until: ?int, deleted: ?int}> */
    private array $accounts = [];

    /** @var array<string, array<int, array<int, int>>> pool => currency => ids */
    private array $pools = [];

    /** @var array<int, float> */
    private array $weights = [];

    /** @var array<int, Account> */
    private array $models = [];

    public function register(int $id, string $pool, int $typeId, int $bankId, int $currencyId, string $name, ?string $code, int $fromDay, float $weight = 1.0): void
    {
        $this->accounts[$id] = [
            'type' => $typeId, 'bank' => $bankId, 'cur' => $currencyId, 'name' => $name,
            'code' => $code, 'from' => $fromDay, 'until' => null, 'deleted' => null,
        ];
        $this->pools[$pool][$currencyId][] = $id;
        $this->weights[$id] = $weight;
    }

    /** The account must already exist on $day (it is opened earlier). */
    public function extendFrom(int $id, int $day): void
    {
        $this->accounts[$id]['from'] = min($this->accounts[$id]['from'], $day);
    }

    /** No new posting may use the account after $day (deactivated / replaced). */
    public function closeFrom(int $id, int $day): void
    {
        $this->accounts[$id]['until'] = $day;
    }

    public function markDeleted(int $id, int $day): void
    {
        $this->accounts[$id]['deleted'] = $day;
        $this->accounts[$id]['until'] = min($this->accounts[$id]['until'] ?? $day, $day);
    }

    /** @return array{type: int, bank: int, cur: int, name: string, code: ?string, from: int, until: ?int, deleted: ?int} */
    public function get(int $id): array
    {
        return $this->accounts[$id] ?? throw new RuntimeException("Unknown synthetic account #{$id}.");
    }

    public function has(string $pool, int $currencyId): bool
    {
        return ! empty($this->pools[$pool][$currencyId]);
    }

    /**
     * A weighted pick among the pool's accounts usable on $day in $currency.
     */
    public function pick(StressRandom $random, string $pool, int $currencyId, int $day, array $exclude = []): ?int
    {
        $weights = [];

        foreach ($this->pools[$pool][$currencyId] ?? [] as $id) {
            if (isset($exclude[$id]) || ! $this->usable($id, $day)) {
                continue;
            }

            $weights[$id] = $this->weights[$id];
        }

        return $weights === [] ? null : (int) $random->weighted($weights);
    }

    /**
     * The write-time FinancialAccountGuard rules, applied to a synthetic
     * posting: the account exists, is in the operation currency, and is
     * active (created, not yet deactivated, not deleted) on that day.
     */
    public function assertUsable(int $id, int $currencyId, int $day): void
    {
        $account = $this->get($id);

        if ($account['cur'] !== $currencyId) {
            throw new RuntimeException("Account #{$id} is not in currency #{$currencyId}.");
        }

        if (! $this->usable($id, $day)) {
            throw new RuntimeException("Account #{$id} is not active on day {$day}.");
        }
    }

    public function usable(int $id, int $day): bool
    {
        $account = $this->accounts[$id];

        return $account['from'] <= $day && ($account['until'] === null || $day < $account['until']);
    }

    /** A lightweight Account model (name only) for description builders. */
    public function model(int $id): Account
    {
        if (! isset($this->models[$id])) {
            $model = new Account;
            $model->forceFill(['id' => $id, 'name' => $this->accounts[$id]['name'], 'account_code' => $this->accounts[$id]['code']]);
            $this->models[$id] = $model;
        }

        return $this->models[$id];
    }

    /** @return array<int, int> */
    public function ids(): array
    {
        return array_keys($this->accounts);
    }

    public function count(): int
    {
        return count($this->accounts);
    }
}
