<?php

namespace App\Services\Integrity;

use Illuminate\Support\Facades\DB;

/**
 * Detects PHYSICALLY missing referenced rows (real orphans) across the core
 * financial schema — transaction_lines, transactions, accounts. Every one of
 * these relationships already carries a real MySQL foreign-key constraint
 * (confirmed by the OMS Task 8 read-only audit), so under normal operation
 * this should always report zero; this check exists as a periodic defense-in
 * -depth safety net (e.g. against a raw import/restore that bypassed FK
 * checks), not because the app is currently unprotected.
 *
 * A soft-deleted referenced row is NEVER an orphan — every query here joins
 * against the physical table with no `deleted_at` filter on the referenced
 * side, so a trashed-but-physically-present account/currency/etc. correctly
 * counts as a valid reference.
 *
 * Bounded: each relationship is one aggregate COUNT query plus, only when
 * violations exist, one further bounded LIMIT query for a small ID sample —
 * never a full-table row load.
 */
class DatabaseRelationshipIntegrityChecker
{
    /**
     * @var array<int, array{table: string, column: string, references: string, nullable: bool}>
     */
    private const RELATIONSHIPS = [
        ['table' => 'transaction_lines', 'column' => 'transaction_id', 'references' => 'transactions', 'nullable' => false],
        ['table' => 'transaction_lines', 'column' => 'account_id', 'references' => 'accounts', 'nullable' => false],
        ['table' => 'transaction_lines', 'column' => 'currency_id', 'references' => 'currencies', 'nullable' => false],
        ['table' => 'transaction_lines', 'column' => 'project_cost_id', 'references' => 'projects_costs', 'nullable' => true],
        ['table' => 'transactions', 'column' => 'fiscal_year_id', 'references' => 'fiscal_years', 'nullable' => false],
        ['table' => 'transactions', 'column' => 'transaction_type_id', 'references' => 'transactions_types', 'nullable' => false],
        ['table' => 'transactions', 'column' => 'partner_id', 'references' => 'partners', 'nullable' => true],
        ['table' => 'accounts', 'column' => 'account_type_id', 'references' => 'accounts_type', 'nullable' => false],
        ['table' => 'accounts', 'column' => 'currency_id', 'references' => 'currencies', 'nullable' => false],
        ['table' => 'accounts', 'column' => 'bank_type_id', 'references' => 'bank_types', 'nullable' => true],
    ];

    public function check(IntegrityCheckReport $report): void
    {
        $checked = 0;

        foreach (self::RELATIONSHIPS as $relationship) {
            $checked++;
            $this->checkRelationship($report, $relationship);
        }

        $report->setStat('relationships_checked', $checked);
    }

    /**
     * @param  array{table: string, column: string, references: string, nullable: bool}  $relationship
     */
    private function checkRelationship(IntegrityCheckReport $report, array $relationship): void
    {
        ['table' => $table, 'column' => $column, 'references' => $references, 'nullable' => $nullable] = $relationship;

        $query = DB::table($table . ' as t')
            ->leftJoin($references . ' as r', 'r.id', '=', "t.{$column}")
            ->whereNull('r.id');

        if ($nullable) {
            $query->whereNotNull("t.{$column}");
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            return;
        }

        $sampleIds = (clone $query)->limit(IntegrityViolation::MAX_SAMPLE_IDS)->pluck('t.id')->all();

        $report->addViolation(new IntegrityViolation(
            category: 'orphan_relationship',
            description: "{$table}.{$column} references a physically missing {$references} row",
            count: $count,
            sampleIds: $sampleIds,
        ));
    }
}
