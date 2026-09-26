<?php

namespace App\Services\Muwakha\Import;

use App\Models\Project;
use App\Models\User;

/**
 * The complete result of one MuwakhaFamilyImportPreflight run, and the ONLY
 * thing the real import is allowed to act on.
 *
 * READ-ONLY BY CONSTRUCTION. Producing this object performs zero writes — the
 * preflight only SELECTs — so a dry run is simply "build the report and print
 * it". `--execute` builds the very same report and then, only if
 * isImportable() holds, replays its payloads through MuwakhaFamilyService.
 * There is no second, looser validation path anywhere.
 *
 * TWO LEVELS OF PROBLEM, deliberately separated:
 *  - `fatalErrors` are whole-run problems: an unreadable/invalid file, a row
 *    count that is not the expected one, an unresolvable or ineligible target
 *    project, a missing `أفراد` account type, an unresolvable actor. None of
 *    them can be attributed to one row, and any one of them blocks everything.
 *  - a row's own `errors` block that row, and — because a partial import is
 *    not acceptable for this batch — therefore block the whole run too.
 *
 * `warnings` never block. They exist for the one case where silence would be
 * worse than noise: source keys the importer does not map, which are reported
 * so an operator can confirm the dropped column really is a display column.
 */
final class MuwakhaFamilyImportReport
{
    /**
     * @param  array<int, MuwakhaFamilyImportRow>  $rows
     * @param  array<int, string>  $fatalErrors
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $filePath,
        public readonly string $projectCode,
        public readonly ?Project $project,
        public readonly ?string $actorReference,
        public readonly ?User $actor,
        public readonly int $expectedRowCount,
        public readonly int $sourceRowCount,
        public readonly array $rows,
        public readonly array $fatalErrors,
        public readonly array $warnings,
    ) {}

    /**
     * @return array<int, MuwakhaFamilyImportRow>
     */
    public function readyRows(): array
    {
        return array_values(array_filter($this->rows, static fn (MuwakhaFamilyImportRow $row): bool => $row->isReady()));
    }

    /**
     * @return array<int, MuwakhaFamilyImportRow>
     */
    public function errorRows(): array
    {
        return array_values(array_filter($this->rows, static fn (MuwakhaFamilyImportRow $row): bool => ! $row->isReady()));
    }

    public function readyCount(): int
    {
        return count($this->readyRows());
    }

    public function errorCount(): int
    {
        return count($this->errorRows());
    }

    /**
     * Supplied currency code => number of source rows carrying it, counted over
     * EVERY row including failed ones, because the operator reading the report
     * is reconciling against the spreadsheet, not against what would be written.
     *
     * @return array<string, int>
     */
    public function currencyBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->rows as $row) {
            $code = $row->currencyCode === '' ? '(none)' : $row->currencyCode;

            $breakdown[$code] = ($breakdown[$code] ?? 0) + 1;
        }

        ksort($breakdown);

        return $breakdown;
    }

    /**
     * Every family in this batch gets exactly one Account, one ownership
     * mapping and one project link, because MuwakhaFamilyService::create()
     * writes precisely that for a single-link submission. The projections are
     * derived from that fact rather than counted separately, so they cannot
     * drift from what the service actually does.
     *
     * @return array{families: int, accounts: int, family_account_mappings: int, family_project_links: int}
     */
    public function projectedWrites(): array
    {
        $ready = $this->readyCount();

        return [
            'families' => $ready,
            'accounts' => $ready,
            'family_account_mappings' => $ready,
            'family_project_links' => $ready,
        ];
    }

    public function hasFatalErrors(): bool
    {
        return $this->fatalErrors !== [];
    }

    /**
     * Whether a dry run found anything that would stop a real import. Used for
     * the dry run's exit code, so `--execute` is never reached by an operator
     * who believed a red report was green.
     */
    public function hasBlockingProblems(): bool
    {
        return $this->hasFatalErrors() || $this->errorCount() > 0 || $this->rows === [];
    }

    /**
     * The single gate on real writes. Everything must hold at once: no fatal
     * error, at least one row, EVERY row ready, the exact expected row count, a
     * resolved target project and a resolved actor.
     *
     * The actor is part of THIS check rather than the command's argument
     * parsing because created_by/updated_by and the AuditEvent actor are
     * written by ambient auth state — a run with no resolved user would produce
     * an unattributed audit trail, which this feature does not permit.
     */
    public function isImportable(): bool
    {
        return ! $this->hasBlockingProblems()
            && $this->sourceRowCount === $this->expectedRowCount
            && $this->project !== null
            && $this->actor !== null;
    }

    /**
     * Why isImportable() is false, in operator-readable form. Returns an empty
     * array when the report IS importable.
     *
     * @return array<int, string>
     */
    public function blockingReasons(): array
    {
        $reasons = $this->fatalErrors;

        if ($this->rows === []) {
            $reasons[] = 'The source contains no rows.';
        }

        if ($this->errorCount() > 0) {
            $reasons[] = sprintf(
                '%d source row(s) failed validation; a partial import is not permitted for this batch.',
                $this->errorCount(),
            );
        }

        if ($this->project === null) {
            $reasons[] = sprintf('The target project «%s» could not be resolved.', $this->projectCode);
        }

        if ($this->actor === null) {
            $reasons[] = '--actor is required for a real import so created_by/updated_by and the audit trail name a real OMS user.';
        }

        return array_values($reasons);
    }
}
