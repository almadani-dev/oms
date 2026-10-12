<?php

namespace App\Services\Audit\Reports;

/**
 * What an export delivers, recorded beside its format in the
 * `report_export.export_requested` payload (`purpose`, `purpose_label`).
 *
 * The financial reports with a data / statistics split (تقرير كشف
 * الحساب, تقرير الحركات المالية الشامل, 2026-10-11; تقرير الجهات المانحة,
 * 2026-10-12):
 *
 *  - DetailData  «تصدير البيانات CSV»: the detailed rows only
 *  - Statistics  «تصدير الإحصائيات Excel» / «تصدير الإحصائيات Word»:
 *                statistics, summaries and the applied filters only, never
 *                a detail row
 *
 * Exports recorded without a purpose (Word, the other reports, every event
 * written before this split) simply carry no `purpose` key.
 */
enum ReportExportPurpose: string
{
    case DetailData = 'detail_data';
    case Statistics = 'statistics';

    public function label(): string
    {
        return match ($this) {
            self::DetailData => 'تصدير البيانات التفصيلية (CSV)',
            // Excel or Word — the event's `format` says which.
            self::Statistics => 'تصدير الإحصائيات والملخصات',
        };
    }
}
