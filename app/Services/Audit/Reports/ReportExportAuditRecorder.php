<?php

namespace App\Services\Audit\Reports;

use App\Enums\AuditFailureMode;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use Illuminate\Support\Carbon;

/**
 * The single write path for `event_category = report_export` (OMS Task 9B.5):
 * one REQUIRED event per financial report export request.
 *
 * ---------------------------------------------------------------------------
 * WHY THE ACTION IS `export_requested` AND NOT `export_completed`.
 * ---------------------------------------------------------------------------
 * Verified against all nine services in app/Services/Reports: every one of
 * them ends with
 *
 *     return response()->streamDownload(function () use ($doc) {
 *         (new Xlsx($doc))->save('php://output');       // or PhpWord Word2007
 *     }, $filename, [...]);
 *
 * The in-memory Spreadsheet/PhpWord object is built synchronously before the
 * response is constructed, but the actual SERIALIZATION — the step that can
 * still exhaust memory or throw inside PhpSpreadsheet/PhpWord — runs inside
 * the streamDownload callback, which Symfony invokes only after the response
 * has been returned, the headers are already committed, and the audit row is
 * long since written. No temporary file is produced at any point (the writer
 * targets php://output directly), so there is also no artifact whose
 * existence could prove success.
 *
 * There is therefore NO point in the current synchronous code at which
 * successful generation is objectively known before the response is returned.
 * Recording `export_completed` would be a claim this code cannot support, so
 * the event records exactly what IS known and true at that moment: an
 * authorized actor requested this export, of this report, in this format,
 * over these filters. If exports ever move behind a queued job or a
 * materialized temporary file, a genuine completion event becomes possible
 * and should be ADDED, not substituted.
 *
 * ---------------------------------------------------------------------------
 * REQUIRED, AND PLACED AFTER AUTHORIZATION.
 * ---------------------------------------------------------------------------
 * Every call site records only after AuthorizesReportAccess::
 * authorizeReportExport() has passed (so an unauthorized caller's 403 never
 * writes a report_export row) and after the page's own "عرض" submission gate
 * has passed (so a blocked export that returns null and only shows a warning
 * notification is not recorded as an export at all). Because the mode is
 * Required, an audit-storage failure throws AuditPersistenceException out of
 * the export method and the financial file is never generated or delivered.
 *
 * ---------------------------------------------------------------------------
 * DUPLICATE PREVENTION IS STRUCTURAL.
 * ---------------------------------------------------------------------------
 * The shared AuthorizesReportAccess trait records NOTHING — it only checks
 * permissions. The export event is written exclusively by the page-specific
 * export method, once, so the "trait plus page method" duplication this phase
 * warns about cannot occur. Likewise nothing is recorded inside the export
 * services or inside the streamDownload callback, so a response callback can
 * never add a second event for the same request.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE PAYLOAD MAY CONTAIN.
 * ---------------------------------------------------------------------------
 * Filters only — ids plus short labels — never results. boundFilters() below
 * accepts scalars and flat lists of scalars and DROPS anything nested, which
 * is what structurally prevents a caller from passing $this->rows,
 * $this->report or any other result dataset into an audit row. Nothing about
 * the generated file (path, bytes, size) is recorded, because no such file
 * exists.
 */
final class ReportExportAuditRecorder
{
    public const EVENT_CATEGORY = 'report_export';

    public const EVENT_ACTION = 'export_requested';

    private const MAX_FILTER_KEYS = 30;

    private const MAX_FILTER_LIST_ITEMS = 25;

    private const MAX_FILTER_STRING_LENGTH = 200;

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditActorResolver $actorResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  the filters actually applied to
     *         the exported snapshot — ids and short labels only
     */
    public function exportRequested(
        ReportExportSubject $subject,
        ReportExportFormat $format,
        array $filters = [],
    ): void {
        $payload = [
            'report' => $subject->value,
            'report_label' => $subject->label(),
            'format' => $format->value,
            'exported_at' => Carbon::now()->toIso8601String(),
        ] + $this->boundFilters($filters);

        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: self::EVENT_ACTION,
                actor: $this->actorResolver->resolve(),
                subjectType: $subject->value,
                subjectLabel: $subject->label(),
                newValues: $payload,
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * Keeps scalars and flat lists of scalars; drops everything else.
     *
     * A report page holds its rows as array<int, array<string, mixed>> and its
     * computed report as a nested array, so both are rejected by the nesting
     * rule alone — this is a structural guarantee that no result row can be
     * written into an audit payload, not a naming convention a future call
     * site could accidentally sidestep. AuditPayloadBounder still applies its
     * own global string/JSON-size caps on top of these.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function boundFilters(array $filters): array
    {
        $bound = [];

        foreach ($filters as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (count($bound) >= self::MAX_FILTER_KEYS) {
                break;
            }

            if (is_array($value)) {
                $list = $this->boundScalarList($value);

                if ($list !== []) {
                    $bound[$key] = $list;
                }

                continue;
            }

            $scalar = $this->boundScalar($value);

            if ($scalar !== null) {
                $bound[$key] = $scalar;
            }
        }

        return $bound;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<int, scalar>
     */
    private function boundScalarList(array $value): array
    {
        $list = [];

        foreach ($value as $item) {
            // Nested arrays (a result row, a currency summary, an
            // applied-filter label pair) are dropped outright.
            if (is_array($item)) {
                continue;
            }

            $scalar = $this->boundScalar($item);

            if ($scalar === null) {
                continue;
            }

            $list[] = $scalar;

            if (count($list) >= self::MAX_FILTER_LIST_ITEMS) {
                break;
            }
        }

        return $list;
    }

    private function boundScalar(mixed $value): int|float|bool|string|null
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : mb_substr($trimmed, 0, self::MAX_FILTER_STRING_LENGTH);
        }

        // null, objects, closures and resources are all simply not filters.
        return null;
    }
}
