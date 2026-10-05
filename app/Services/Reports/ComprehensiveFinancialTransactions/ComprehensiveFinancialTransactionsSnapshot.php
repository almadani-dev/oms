<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;

/**
 * Read-only view of one published, owner-verified report snapshot.
 *
 * Only obtainable from the repository's publish()/find(), both of which have
 * already checked the owner, the commit marker and the expiry — so holding
 * one of these IS the authorization to read it.
 *
 * Every read is bounded: a page touches only the row chunks its rows live in
 * (and, for a classification/type page, only the index chunks covering that
 * page). Nothing here queries the database; summaries come from the manifest,
 * rows from the stored chunks.
 */
final class ComprehensiveFinancialTransactionsSnapshot
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        private readonly ComprehensiveFinancialTransactionsSnapshotRepository $repository,
        private readonly array $manifest,
    ) {}

    public function id(): string
    {
        return (string) $this->manifest['id'];
    }

    public function ownerId(): int
    {
        return (int) $this->manifest['owner_id'];
    }

    public function createdAt(): string
    {
        return (string) $this->manifest['created_at'];
    }

    public function expiresAt(): string
    {
        return (string) $this->manifest['expires_at'];
    }

    public function filters(): ComprehensiveFinancialTransactionsFilters
    {
        return ComprehensiveFinancialTransactionsFilters::fromArray($this->manifest['filters']);
    }

    /**
     * @return array<string, string>
     */
    public function filterLabels(): array
    {
        return $this->manifest['filter_labels'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function currencySummaries(): array
    {
        return $this->manifest['currency_summaries'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categorySummaries(): array
    {
        return $this->manifest['category_summaries'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function typeSummaries(): array
    {
        return $this->manifest['type_summaries'];
    }

    public function transactionCount(): int
    {
        return (int) $this->manifest['transaction_count'];
    }

    public function lineCount(): int
    {
        return (int) $this->manifest['line_count'];
    }

    public function currenciesCount(): int
    {
        return (int) $this->manifest['currencies_count'];
    }

    /**
     * Number of lines behind one classification/type bucket — the same
     * figure as that bucket's 'line_count' summary.
     */
    public function groupLineCount(string $dimension, string $groupKey): int
    {
        return (int) ($this->manifest['indexes'][$dimension][$groupKey] ?? 0);
    }

    public static function lastPage(int $total, int $perPage): int
    {
        return max(1, (int) ceil($total / max(1, $perPage)));
    }

    public static function clampPage(int $page, int $total, int $perPage): int
    {
        return min(max(1, $page), self::lastPage($total, $perPage));
    }

    /**
     * One page of the main detail table, in canonical order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function page(int $page, int $perPage): array
    {
        $total = $this->lineCount();
        $page = self::clampPage($page, $total, $perPage);
        $start = ($page - 1) * $perPage;
        $end = min($start + $perPage, $total);

        return $start < $end ? $this->rowsAt(range($start, $end - 1)) : [];
    }

    /**
     * One page of one classification's/type's lines, in canonical order —
     * read through that group's ordinal index, never by scanning the rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupPage(string $dimension, string $groupKey, int $page, int $perPage): array
    {
        $total = $this->groupLineCount($dimension, $groupKey);

        if ($total === 0) {
            return [];
        }

        $page = self::clampPage($page, $total, $perPage);
        $start = ($page - 1) * $perPage;
        $end = min($start + $perPage, $total);
        $indexChunkSize = (int) $this->manifest['index_chunk_size'];

        $ordinals = [];

        for ($chunk = intdiv($start, $indexChunkSize); $chunk <= intdiv($end - 1, $indexChunkSize); $chunk++) {
            $entries = $this->repository->readIndexChunk($this->ownerId(), $this->id(), $dimension, $groupKey, $chunk + 1);
            $chunkStart = $chunk * $indexChunkSize;

            foreach ($entries as $offset => $ordinal) {
                $position = $chunkStart + $offset;

                if ($position >= $start && $position < $end) {
                    $ordinals[] = (int) $ordinal;
                }
            }
        }

        return $this->rowsAt($ordinals);
    }

    /**
     * Every row in canonical order, one stored chunk at a time — for the
     * exporters. Never more than one row chunk is held here at once.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function rows(): \Generator
    {
        $chunks = (int) $this->manifest['row_chunks'];

        for ($chunk = 1; $chunk <= $chunks; $chunk++) {
            foreach ($this->repository->readRowChunk($this->ownerId(), $this->id(), $chunk) as $row) {
                yield $row;
            }
        }
    }

    /**
     * Rows at the given ascending ordinals, reading each row chunk once.
     *
     * @param  array<int, int>  $ordinals
     * @return array<int, array<string, mixed>>
     */
    private function rowsAt(array $ordinals): array
    {
        $rowChunkSize = (int) $this->manifest['row_chunk_size'];
        $rows = [];
        $loadedChunk = null;
        $chunkRows = [];

        foreach ($ordinals as $ordinal) {
            $chunk = intdiv($ordinal, $rowChunkSize) + 1;

            if ($chunk !== $loadedChunk) {
                $chunkRows = $this->repository->readRowChunk($this->ownerId(), $this->id(), $chunk);
                $loadedChunk = $chunk;
            }

            $rows[] = $chunkRows[$ordinal % $rowChunkSize];
        }

        return $rows;
    }
}
