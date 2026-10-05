<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;

/**
 * Writes one dimension's (classification or type) ordinal indexes while a
 * snapshot is being built, flushing each group's ordinals in fixed-size
 * chunks. Memory is bounded by (number of groups × chunk size) — never by
 * the number of lines in a group.
 *
 * @internal used only by ComprehensiveFinancialTransactionsSnapshotBuilder
 */
final class SnapshotIndexWriter
{
    /** @var array<string, array<int, int>> pending ordinals per group key */
    private array $pending = [];

    /** @var array<string, int> chunks already written per group key */
    private array $written = [];

    /** @var array<string, int> total ordinals per group key, in first-seen order */
    private array $counts = [];

    public function __construct(
        private readonly ComprehensiveFinancialTransactionsSnapshotRepository $repository,
        private readonly int $ownerId,
        private readonly string $snapshotId,
        private readonly string $dimension,
        private readonly int $chunkSize,
    ) {}

    public function add(string $groupKey, int $ordinal): void
    {
        $this->pending[$groupKey][] = $ordinal;
        $this->counts[$groupKey] = ($this->counts[$groupKey] ?? 0) + 1;

        if (count($this->pending[$groupKey]) === $this->chunkSize) {
            $this->flush($groupKey);
        }
    }

    /**
     * Flushes every remaining partial chunk.
     *
     * @return array<string, int> line count per group key
     */
    public function finish(): array
    {
        foreach (array_keys($this->pending) as $groupKey) {
            if ($this->pending[$groupKey] !== []) {
                $this->flush($groupKey);
            }
        }

        return $this->counts;
    }

    private function flush(string $groupKey): void
    {
        $this->written[$groupKey] = ($this->written[$groupKey] ?? 0) + 1;

        $this->repository->writeIndexChunk(
            $this->ownerId,
            $this->snapshotId,
            $this->dimension,
            $groupKey,
            $this->written[$groupKey],
            $this->pending[$groupKey],
        );

        $this->pending[$groupKey] = [];
    }
}
