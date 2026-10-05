<?php

namespace App\Services\Stress\Generator;

/**
 * Lightweight record of the history the audit phase must reproduce but
 * cannot read back from the final rows alone: pre-edit values, who deleted
 * what, rows that no longer exist (hard-removed Muwakha project links),
 * project costs added later through the audited relation manager, and
 * opening-balance context. Everything else is derived from the tables.
 */
final class AuditJournal
{
    /** @var array<string, array<int, array{at: string, by: int, before: array<string, mixed>}>> subject => id => edit */
    public array $financialEdits = [];

    /** @var array<string, array<int, int>> table => id => deleting user id */
    public array $deletedBy = [];

    /**
     * CRUD updates: model class => list of [id, at, by, before attrs, after attrs].
     *
     * @var array<class-string, array<int, array{id: int, at: string, by: int, before: array<string, mixed>, after: array<string, mixed>}>>
     */
    public array $crudUpdates = [];

    /**
     * Values a CRUD "created" snapshot must show instead of today's row
     * (e.g. a project created "غير مكتمل" and completed later).
     *
     * @var array<class-string, array<int, array<string, mixed>>>
     */
    public array $createdOverrides = [];

    /** @var array<int, true> project cost ids added via the audited relation manager */
    public array $auditedCosts = [];

    /** @var array<int, array<string, mixed>> account id => opening-balance context */
    public array $openingContext = [];

    /** @var array<int, int> account id => creating user id (rows have no created_by on mappings) */
    public array $creators = [];

    /**
     * Muwakha project links that were hard-removed when their family was
     * deleted: the full row as it existed, plus who removed it and when.
     *
     * @var array<int, array{row: array<string, mixed>, at: string, by: int}>
     */
    public array $removedLinks = [];

    /** @var array<string, array<int, int>> table => row id => acting user (for tables without created_by) */
    public array $rowActors = [];

    /**
     * @param  array<string, mixed>  $before
     */
    public function financialEdit(string $table, int $id, string $at, int $by, array $before): void
    {
        $this->financialEdits[$table][$id] = ['at' => $at, 'by' => $by, 'before' => $before];
    }

    public function deleted(string $table, int $id, int $by): void
    {
        $this->deletedBy[$table][$id] = $by;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function crudUpdate(string $class, int $id, string $at, int $by, array $before, array $after): void
    {
        $this->crudUpdates[$class][] = ['id' => $id, 'at' => $at, 'by' => $by, 'before' => $before, 'after' => $after];
    }
}
