<?php

namespace App\Services\Audit\Crud;

/**
 * The audited-field delta of one pending update, produced by
 * AuditModelSnapshotter::pendingDiff() before the model is saved.
 *
 * `old`/`new` contain ONLY the audited business fields that actually
 * changed (plus, where applicable, the bounded relationship label snapshot
 * belonging to a changed foreign key) — never the untouched remainder of
 * the record, and never a technical column.
 */
final class AuditFieldDiff
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<int, string>  $changed  real audited column names only
     */
    public function __construct(
        public readonly array $old,
        public readonly array $new,
        public readonly array $changed,
    ) {}

    public function isEmpty(): bool
    {
        return $this->changed === [];
    }
}
