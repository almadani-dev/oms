<?php

namespace App\Console\Commands;

use App\Services\Muwakha\Import\MuwakhaFamilyImporter;
use App\Services\Muwakha\Import\MuwakhaFamilyImportPreflight;
use App\Services\Muwakha\Import\MuwakhaFamilyImportReport;
use App\Services\Muwakha\Import\MuwakhaFamilyImportRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-time bulk import of Muwakha families from a normalized JSON source.
 *
 * A THIN CLI SHELL. Every decision lives elsewhere: validation, reference
 * resolution and conflict detection in MuwakhaFamilyImportPreflight, the write
 * in MuwakhaFamilyImporter, and the actual domain write in the untouched
 * MuwakhaFamilyService. This class parses options, prints the report and
 * chooses an exit code.
 *
 * DRY RUN IS THE DEFAULT AND WRITES NOTHING. Building the report performs only
 * SELECTs, so the command is read-only unless --execute is passed. Safety does
 * NOT rest on an interactive confirmation: --execute is a required explicit
 * flag, and it is additionally refused unless --actor resolves to a real, live,
 * active OMS user and every single source row passes the same complete
 * preflight the dry run printed.
 *
 * Exit codes:
 *   0 = dry run clean and ready to execute, or the real import committed
 *   1 = the preflight found blocking problems, or the import was refused
 *       (in both cases nothing was written)
 *   2 = invalid invocation, or the run failed unexpectedly
 */
class ImportMuwakhaFamilies extends Command
{
    protected $signature = 'muwakha:import-families
        {file : Path to the normalized JSON source (absolute, or relative to the project root)}
        {--project= : Target Muwakha project CODE, e.g. MUWAKHA_20260926_001. Resolved at runtime; never an id.}
        {--actor= : The OMS user recorded as created_by/updated_by and as the audit actor — numeric id or email. REQUIRED with --execute.}
        {--execute : Perform the real import. WITHOUT this flag the command is a dry run that writes nothing.}
        {--expect=72 : The exact number of rows the source file must contain.}';

    protected $description = 'One-time bulk import of Muwakha families through MuwakhaFamilyService. Dry run by default; --execute is required for any write.';

    private const EXIT_BLOCKED = 1;

    private const EXIT_RUNTIME_FAILURE = 2;

    public function handle(MuwakhaFamilyImportPreflight $preflight, MuwakhaFamilyImporter $importer): int
    {
        $execute = (bool) $this->option('execute');
        $projectCode = trim((string) $this->option('project'));
        $actorReference = trim((string) $this->option('actor'));
        $expected = (int) $this->option('expect');

        if ($projectCode === '') {
            $this->error('--project=<CODE> is required (e.g. --project=MUWAKHA_20260926_001).');

            return self::INVALID;
        }

        if ($expected < 1) {
            $this->error('--expect must be a positive number of source rows.');

            return self::INVALID;
        }

        // Checked before the preflight so the operator is told what is missing
        // rather than reading a full report that ends in a refusal.
        if ($execute && $actorReference === '') {
            $this->error('--actor=<USER_ID|EMAIL> is required with --execute so the families, their accounts and the audit trail name a real OMS user.');

            return self::INVALID;
        }

        $path = $this->resolvePath((string) $this->argument('file'));

        try {
            $report = $preflight->run(
                filePath: $path,
                projectCode: $projectCode,
                actorReference: $actorReference === '' ? null : $actorReference,
                expectedRowCount: $expected,
            );
        } catch (Throwable $e) {
            return $this->reportRuntimeFailure('preflight', $e);
        }

        $this->renderReport($report, $execute);

        if (! $execute) {
            $this->newLine();
            $this->line('REAL DATABASE WRITES: 0');
            $this->newLine();

            if ($report->hasBlockingProblems()) {
                $this->error('DRY RUN: the source is NOT ready to import. Fix the problems above and run the dry run again.');

                return self::EXIT_BLOCKED;
            }

            $this->info('DRY RUN: the source is ready. Re-run with --execute (and --actor) to perform the real import.');

            return self::SUCCESS;
        }

        if (! $report->isImportable()) {
            $this->newLine();

            foreach ($report->blockingReasons() as $reason) {
                $this->line('  - '.$reason);
            }

            $this->newLine();
            $this->line('REAL DATABASE WRITES: 0');
            $this->error('ABORTED before any write. A partial import is not permitted: either all rows import, or none do.');

            return self::EXIT_BLOCKED;
        }

        try {
            $created = $importer->import($report);
        } catch (Throwable $e) {
            // The whole batch shares one outer transaction, so an exception from
            // anywhere inside it has already rolled every family back.
            return $this->reportRuntimeFailure('import', $e);
        }

        $this->newLine();
        $this->info(sprintf('IMPORT COMMITTED: %d families created in one transaction.', count($created)));

        return self::SUCCESS;
    }

    /**
     * A bare relative path is resolved against the project root so the documented
     * `storage/app/imports/...` invocation works from any working directory.
     */
    private function resolvePath(string $file): string
    {
        return str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : base_path($file);
    }

    private function renderReport(MuwakhaFamilyImportReport $report, bool $execute): void
    {
        $this->line('Muwakha Families Import');
        $this->line('-----------------------');
        $this->line('Mode: '.($execute ? 'EXECUTE (real writes)' : 'DRY RUN (zero writes)'));
        $this->line('Source file: '.$report->filePath);
        $this->newLine();

        if ($report->fatalErrors !== []) {
            $this->error('Blocking problems with the run itself:');

            foreach ($report->fatalErrors as $error) {
                $this->line('  - '.$error);
            }

            $this->newLine();
        }

        if ($report->warnings !== []) {
            $this->warn('Warnings (not blocking):');

            foreach ($report->warnings as $warning) {
                $this->line('  - '.$warning);
            }

            $this->newLine();
        }

        if ($report->rows !== []) {
            $this->table(
                ['Row', 'Martyr name', 'Martyr national ID', 'Card code', 'Currency', 'Bank type', 'Status', 'Error(s)'],
                array_map(
                    static fn (MuwakhaFamilyImportRow $row): array => [
                        $row->label(),
                        $row->martyrName,
                        $row->martyrNationalId,
                        $row->cardCode,
                        $row->currencyCode,
                        $row->bankTypeName,
                        $row->isReady() ? 'READY' : 'ERROR',
                        $row->errors === []
                            ? ''
                            : count($row->errors).' — listed below',
                    ],
                    $report->rows,
                ),
            );

            $this->newLine();
        }

        // The messages themselves go BELOW the table rather than inside it: with
        // 72 rows, inline error text makes every column unreadably wide, and the
        // operator needs the full message, not a truncated one.
        if ($report->errorRows() !== []) {
            $this->error('Row errors (nothing is imported while any row has one):');

            foreach ($report->errorRows() as $row) {
                $this->line(sprintf(
                    '  Row %s — %s (%s):',
                    $row->label(),
                    $row->martyrName === '' ? '(no martyr name)' : $row->martyrName,
                    $row->martyrNationalId === '' ? 'no national id' : $row->martyrNationalId,
                ));

                foreach ($row->errors as $error) {
                    $this->line('      - '.$error);
                }
            }

            $this->newLine();
        }

        $writes = $report->projectedWrites();

        $this->line('Summary');
        $this->line('  Source rows: '.$report->sourceRowCount.' (expected '.$report->expectedRowCount.')');
        $this->line('  Ready: '.$report->readyCount());
        $this->line('  Errors: '.$report->errorCount());

        foreach ($report->currencyBreakdown() as $code => $count) {
            $this->line('  '.$code.': '.$count);
        }

        $this->line('  Target project: '.$this->describeProject($report));
        $this->line('  Actor: '.$this->describeActor($report));
        $this->line('  Would create families: '.$writes['families']);
        $this->line('  Would create accounts: '.$writes['accounts']);
        $this->line('  Would create family-account mappings: '.$writes['family_account_mappings']);
        $this->line('  Would create family-project links: '.$writes['family_project_links']);
    }

    private function describeProject(MuwakhaFamilyImportReport $report): string
    {
        $project = $report->project;

        return $project === null
            ? $report->projectCode.' — UNRESOLVED'
            : sprintf('%s — %s (#%d)', (string) $project->code, (string) $project->name, $project->id);
    }

    private function describeActor(MuwakhaFamilyImportReport $report): string
    {
        $actor = $report->actor;

        if ($actor !== null) {
            return sprintf('%s <%s> (#%d)', (string) $actor->name, (string) $actor->email, $actor->id);
        }

        return $report->actorReference === null || $report->actorReference === ''
            ? 'NOT SUPPLIED (required for --execute)'
            : $report->actorReference.' — UNRESOLVED';
    }

    private function reportRuntimeFailure(string $stage, Throwable $e): int
    {
        Log::error('muwakha:import-families failed during '.$stage.'.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $this->newLine();
        $this->error(sprintf(
            'The %s stage failed unexpectedly (%s): %s',
            $stage,
            $e::class,
            $e->getMessage(),
        ));
        $this->line($stage === 'import'
            ? 'The batch runs inside one transaction, so nothing was committed.'
            : 'No writes were attempted.');

        return self::EXIT_RUNTIME_FAILURE;
    }
}
