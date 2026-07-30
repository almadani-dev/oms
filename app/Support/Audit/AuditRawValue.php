<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use BackedEnum;

/**
 * Reads one stored `audit_events` column as the plain string the database
 * actually holds, bypassing AuditEvent's attribute casts (OMS Task 9B.7
 * forward-compatibility fix). PRESENTATION ONLY — nothing here writes, and
 * nothing here changes what is stored.
 *
 * WHY THIS EXISTS. Two displayed columns — `actor_type` and `status` — are
 * cast on the model to the domain enums AuditActorType / AuditStatus, but the
 * SCHEMA stores them as plain `varchar` (see the audit_events migration:
 * `string('actor_type', 20)` and `string('status', 10)`), exactly like the
 * open snake_case `event_category` / `event_action` / `subject_type` columns.
 * A historical row written by a future phase — or restored from a backup taken
 * by a later build — can therefore legitimately hold a value this build's enum
 * does not declare. Touching `$record->actor_type` on such a row makes Eloquent
 * resolve the enum cast and throw a ValueError ("… is not a valid backing value
 * for enum"), which would take down the whole list page, the view page, and any
 * search/sort/paginate that renders that row. An audit trail must never become
 * unreadable because the reader is older than the writer.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not remove or weaken the domain
 * enum casts (every WRITE path — AuditLogger and the recorders under
 * App\Services\Audit — keeps its strict enum typing), it does not touch
 * AuditRedactor, and it does not relax AuditEvent's immutability hooks. It is
 * scoped to the read-only Audit Log UI: AuditEventsTable and AuditEventInfolist
 * are its only callers, and the string it returns is handed to AuditLabels,
 * which maps a KNOWN value to its Arabic label and falls back to the stored
 * value verbatim. Filament then escapes that string like any other text — no
 * `->html()`/`->markdown()` is involved anywhere on these surfaces.
 */
final class AuditRawValue
{
    /**
     * The stored value of `$column` as a plain string, or null when the column
     * is null (or was not selected by the list query at all).
     *
     * `getRawOriginal()` is the primary source — it reads the untouched value
     * Eloquent hydrated from the row, so no cast is ever resolved. The current
     * raw attribute array is the fallback for a record that has not been
     * hydrated from the database, and the BackedEnum branch is belt-and-braces
     * for a value that reached the attribute array as an enum instance.
     */
    public static function string(AuditEvent $record, string $column): ?string
    {
        $raw = $record->getRawOriginal($column, $record->getAttributes()[$column] ?? null);

        if ($raw === null) {
            return null;
        }

        if ($raw instanceof BackedEnum) {
            return (string) $raw->value;
        }

        return (string) $raw;
    }
}
