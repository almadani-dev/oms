<?php

namespace App\Services\Stress\Generator;

use Illuminate\Support\Facades\DB;

/**
 * Buffered multi-row INSERTs with explicit ids, flushed in foreign-key
 * dependency order inside one short DB transaction per flush.
 *
 * Bounded memory: a buffer is flushed as soon as it reaches the chunk size
 * (after all its parents are flushed first), so the whole dataset is never
 * held in memory and the whole build is never one giant transaction.
 */
final class BulkWriter
{
    /** Parents before children — the only order buffers are ever written in. */
    private const ORDER = [
        'users', 'model_has_roles', 'fiscal_years', 'exchange_rate_histories', 'settings',
        'partners', 'accounts', 'projects', 'projects_costs',
        'muwakha_families', 'muwakha_family_accounts', 'muwakha_family_projects',
        'transactions', 'project_cost_budgets', 'transaction_lines',
        'project_cost_receipts', 'project_cost_budgets_payments', 'general_expenses', 'general_exchanges',
        'attachments', 'audit_events',
    ];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $buffers = [];

    /** @var array<string, int> */
    private array $written = [];

    /** @var array<string, string> */
    private array $columns = [];

    private float $insertSeconds = 0.0;

    public function __construct(private readonly string $connection, private readonly int $chunkSize = 1000) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function add(string $table, array $row): void
    {
        ksort($row);
        $signature = implode(',', array_keys($row));

        // A multi-row INSERT takes its column list from the first row; a row
        // with a different key set must fail loudly, never misalign values.
        if (($this->columns[$table] ??= $signature) !== $signature) {
            throw new \LogicException("Row shape mismatch for [{$table}]: [{$signature}] vs [{$this->columns[$table]}].");
        }

        $this->buffers[$table][] = $row;

        if (count($this->buffers[$table]) >= $this->chunkSize) {
            $this->flushUpTo($table);
        }
    }

    /** Flushes $table and every buffer that must precede it. */
    public function flushUpTo(string $table): void
    {
        $position = array_search($table, self::ORDER, true);

        DB::connection($this->connection)->transaction(function () use ($position, $table): void {
            foreach (self::ORDER as $index => $name) {
                if ($position !== false && $index > $position) {
                    break;
                }

                $this->writeBuffer($name);
            }

            // A table outside the dependency list (stress staging) has no
            // parents to wait for; write it after everything else.
            if ($position === false) {
                $this->writeBuffer($table);
            }
        });
    }

    public function flushAll(): void
    {
        DB::connection($this->connection)->transaction(function (): void {
            foreach (self::ORDER as $name) {
                $this->writeBuffer($name);
            }

            foreach (array_keys($this->buffers) as $name) {
                $this->writeBuffer($name);
            }
        });
    }

    /** @return array<string, int> */
    public function written(): array
    {
        return $this->written;
    }

    public function insertSeconds(): float
    {
        return $this->insertSeconds;
    }

    private function writeBuffer(string $table): void
    {
        $rows = $this->buffers[$table] ?? [];

        if ($rows === []) {
            return;
        }

        $this->buffers[$table] = [];
        $start = microtime(true);

        // MySQL caps a prepared statement at 65,535 placeholders, so a wide
        // table gets a smaller chunk than the configured size.
        $chunkSize = max(1, min($this->chunkSize, intdiv(65_000, max(1, count($rows[0])))));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::connection($this->connection)->table($table)->insert($chunk);
        }

        $this->insertSeconds += microtime(true) - $start;
        $this->written[$table] = ($this->written[$table] ?? 0) + count($rows);
    }
}
