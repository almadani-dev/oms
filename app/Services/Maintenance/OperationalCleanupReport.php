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
     * @param  array<string, mixed>  $partnerCensus  partner rows this reset destroys
     * @param  array<string, mixed>  $accountCensus  account rows this reset destroys
     * @param  array<string, mixed>  $attachmentPlan
     * @param  array<int, string>  $deletionOrder  tables, in real execution order
     * @param  array<string, mixed>  $specialCleanupPlan  test markers / orphan assignments still present
     * @param  array<string, mixed>  $specialCleanupResult  what the special cleanup actually removed; apply() only
     * @param  array<int, string>  $risks
     * @param  array<int, string>  $unexpectedOperationalTables
     * @param  array<string, mixed>  $fingerprints
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
        public readonly array $partnerCensus,
        public readonly array $accountCensus,
        public readonly array $attachmentPlan,
        public readonly array $deletionOrder,
        public readonly array $specialCleanupPlan,
        public readonly array $specialCleanupResult,
        public readonly array $risks,
        public readonly array $unexpectedOperationalTables,
        public readonly array $fingerprints,
        public readonly array $numbering,
        public readonly array $verificationFailures = [],
    ) {
    }

    public function isClean(): bool
    {
        return $this->verificationFailures === [];
    }
}
