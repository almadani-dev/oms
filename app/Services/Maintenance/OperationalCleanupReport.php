<?php

namespace App\Services\Maintenance;

use Carbon\CarbonImmutable;

/**
 * Result of an OperationalDataCleanupService audit() or apply() run.
 *
 * Every count is [active, trashed]. dryRun=true reports must never be
 * produced alongside writesPerformed=true — the command asserts that
 * invariant before printing.
 */
class OperationalCleanupReport
{
    /**
     * @param  array<string, array{active: int, trashed: int}>  $preservedCounts
     * @param  array<string, array{active: int, trashed: int}>  $operationalCounts
     * @param  array<string, mixed>  $entityClassification
     * @param  array<string, mixed>  $attachmentPlan
     * @param  array<int, string>  $deletionOrder
     * @param  array<int, string>  $risks
     * @param  array<int, string>  $unexpectedOperationalTables
     * @param  array<string, mixed>  $fingerprints
     * @param  array<string, mixed>  $accountBalances  before/after non-zero counts
     * @param  array<string, mixed>  $numbering
     * @param  array<int, string>  $verificationFailures  populated only after apply()
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $databaseName,
        public readonly CarbonImmutable $performedAt,
        public readonly bool $dryRun,
        public readonly bool $writesPerformed,
        public readonly array $preservedCounts,
        public readonly array $operationalCounts,
        public readonly array $entityClassification,
        public readonly array $attachmentPlan,
        public readonly array $deletionOrder,
        public readonly array $risks,
        public readonly array $unexpectedOperationalTables,
        public readonly array $fingerprints,
        public readonly array $accountBalances,
        public readonly array $numbering,
        public readonly array $verificationFailures = [],
    ) {
    }

    public function isClean(): bool
    {
        return $this->verificationFailures === [];
    }
}
