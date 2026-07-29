<?php

namespace App\Services\Audit\Financial;

use App\Models\Account;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\Transaction;

/**
 * Resolves ONE bounded, human-readable label for a foreign key in a
 * financial audit payload (OMS Task 9B.3).
 *
 * Every lookup here obeys the same three rules:
 *
 *  1. it selects only the handful of columns the label needs — a related
 *     Eloquent model, relation or collection is never serialized into a
 *     payload;
 *  2. it uses withTrashed(), because the most important label of all is the
 *     one captured while auditing a DELETION, where the related row may
 *     itself already be soft-deleted;
 *  3. it is memoized per instance, so one logical financial action performs
 *     at most one query per DISTINCT related record even though the same
 *     account/project appears on both the pre-change and post-change side
 *     of an edit.
 *
 * The instance is created fresh per FinancialAuditRecorder resolution, so
 * the cache cannot go stale across requests.
 */
final class FinancialAuditLabeller
{
    public const MAX_LABEL_LENGTH = 255;

    /** @var array<string, ?string> */
    private array $cache = [];

    public function account(mixed $accountId): ?string
    {
        return $this->memo('account', $accountId, static function (int $id): ?string {
            $account = Account::withTrashed()->select(['id', 'account_code', 'name'])->find($id);

            return $account === null ? null : self::join([$account->account_code, $account->name]);
        });
    }

    public function project(mixed $projectId): ?string
    {
        return $this->memo('project', $projectId, static function (int $id): ?string {
            $project = Project::withTrashed()->select(['id', 'code', 'name'])->find($id);

            return $project === null ? null : self::join([$project->code, $project->name]);
        });
    }

    /**
     * A cost line is meaningless in an audit trail without the project it
     * belonged to, so the label carries both.
     */
    public function projectCost(mixed $projectCostId): ?string
    {
        return $this->memo('project_cost', $projectCostId, function (int $id): ?string {
            $cost = ProjectCost::withTrashed()->select(['id', 'project_id', 'amount'])->find($id);

            if ($cost === null) {
                return null;
            }

            return self::join([
                $this->project($cost->project_id),
                FinancialAuditValue::money($cost->amount),
            ]);
        });
    }

    /**
     * The budget an execution payment draws down, labelled by the budget's
     * own transaction number (its stable business identifier) plus its final
     * amount.
     */
    public function projectCostBudget(mixed $budgetId): ?string
    {
        return $this->memo('project_cost_budget', $budgetId, function (int $id): ?string {
            $budget = ProjectCostBudget::withTrashed()
                ->select(['id', 'transaction_id', 'final_amount'])
                ->find($id);

            if ($budget === null) {
                return null;
            }

            return self::join([
                $this->transactionNumber($budget->transaction_id),
                FinancialAuditValue::money($budget->final_amount),
            ]);
        });
    }

    public function partner(mixed $partnerId): ?string
    {
        return $this->memo('partner', $partnerId, static function (int $id): ?string {
            return Partner::withTrashed()->select(['id', 'name'])->find($id)?->name;
        });
    }

    public function currencyCode(mixed $currencyId): ?string
    {
        return $this->memo('currency', $currencyId, static function (int $id): ?string {
            return Currency::withTrashed()->select(['id', 'code'])->find($id)?->code;
        });
    }

    public function transactionNumber(mixed $transactionId): ?string
    {
        return $this->memo('transaction', $transactionId, static function (int $id): ?string {
            return Transaction::withTrashed()->select(['id', 'transaction_number'])->find($id)?->transaction_number;
        });
    }

    /**
     * @param  callable(int): ?string  $resolver
     */
    private function memo(string $kind, mixed $key, callable $resolver): ?string
    {
        $id = FinancialAuditValue::id($key);

        if ($id === null) {
            return null;
        }

        $cacheKey = $kind.'|'.$id;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        return $this->cache[$cacheKey] = self::bound($resolver($id));
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private static function join(array $parts): ?string
    {
        $clean = array_values(array_filter(
            array_map(static fn (?string $part): string => trim((string) $part), $parts),
            static fn (string $part): bool => $part !== '',
        ));

        return $clean === [] ? null : implode(' — ', $clean);
    }

    private static function bound(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }
}
