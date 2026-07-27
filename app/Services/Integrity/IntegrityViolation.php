<?php

namespace App\Services\Integrity;

/**
 * One bounded finding from a financial/database integrity check. Never
 * carries full financial records — only a category, a human description,
 * a total count, and a small sample of identifiers (never amounts, account
 * names, or other sensitive financial detail).
 */
final class IntegrityViolation
{
    public const MAX_SAMPLE_IDS = 10;

    /**
     * @param  array<int, int|string>  $sampleIds
     */
    public function __construct(
        public readonly string $category,
        public readonly string $description,
        public readonly int $count,
        public readonly array $sampleIds = [],
    ) {
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public static function fromIds(string $category, string $description, array $ids): self
    {
        return new self(
            $category,
            $description,
            count($ids),
            array_slice(array_values($ids), 0, self::MAX_SAMPLE_IDS),
        );
    }
}
