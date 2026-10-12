<?php

namespace App\Http\Controllers\Reports;

use App\Filament\Pages\DonorFinancialReportPage;
use App\Http\Controllers\Controller;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportPurpose;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\DonorFinancialReport\Csv\DonorFinancialReportCsvExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «تصدير البيانات CSV» of "تقرير الجهات المانحة": the download of a package
 * the page already built (one .csv, or a ZIP of several) — never generated
 * here. Same guarantees as the other reports' CSV delivery:
 *
 *  - an authenticated user holding BOTH report permissions (view + export);
 *  - only the CURRENT user's own CSV export — anything else is a plain 404,
 *    indistinguishable from "no such export", and is not audited;
 *  - reached only through a short-lived signed URL, downloadable by its
 *    owner until it expires (a browser may request one download several
 *    times) and audited ONCE, on first delivery, before a byte is sent.
 */
class DonorFinancialReportCsvDownloadController extends Controller
{
    public static function downloadUrl(string $export): string
    {
        return URL::temporarySignedRoute(
            'reports.donor-financial-report.csv',
            now()->addMinutes((int) config(DonorFinancialReportCsvExport::CONFIG.'.export_url_ttl_minutes', 10)),
            ['export' => $export],
        );
    }

    public function show(Request $request, string $export, ReportExportAuditRecorder $auditRecorder): StreamedResponse
    {
        $ownerId = $this->authorizeUser($request);
        $exports = DonorFinancialReportCsvExport::store();
        $status = $exports->status($ownerId, $export);
        abort_if($status === null || $status['state'] !== AccountStatementExcelExports::COMPLETED || $status['format'] !== 'csv', 404);

        $stream = $exports->readStream($ownerId, $export);
        abort_if($stream === null, 404);

        $deliveredKey = 'donor-csv-export-delivered:'.$export;
        $firstDelivery = Cache::add($deliveredKey, true, now()->addDay());

        try {
            if ($firstDelivery) {
                $auditRecorder->exportRequested(
                    ReportExportSubject::DonorFinancialReport,
                    ReportExportFormat::Csv,
                    (array) ($status['payload']['audit'] ?? []) + ['status' => 'completed'] + $status['stats'],
                    ReportExportPurpose::DetailData,
                );
            }
        } catch (\Throwable $e) {
            Cache::forget($deliveredKey);
            fclose($stream);

            throw $e;
        }

        $size = $exports->size($ownerId, $export);
        $filename = (string) $status['filename'];
        $extension = str_ends_with($filename, '.csv') ? 'csv' : 'zip';

        return new StreamedResponse(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, array_filter([
            'Content-Type' => $extension === 'csv' ? 'text/csv; charset=UTF-8' : 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                preg_match('/\d{8}_\d{6}/', $filename, $m) === 1 ? "donor-financial-report-data-{$m[0]}.{$extension}" : "donor-financial-report-data.{$extension}",
            ),
            'Content-Length' => $size !== null ? (string) $size : null,
            'Cache-Control' => 'no-store',
        ]));
    }

    private function authorizeUser(Request $request): int
    {
        $user = $request->user();
        abort_if($user === null, 403);
        abort_unless(
            $user->can(DonorFinancialReportPage::reportViewPermission())
                && $user->can(DonorFinancialReportPage::reportExportPermission()),
            403,
        );

        return (int) $user->getAuthIdentifier();
    }
}
