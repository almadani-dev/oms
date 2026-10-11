<?php

namespace App\Http\Controllers\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Http\Controllers\Controller;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportPurpose;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «تصدير البيانات CSV» of "تقرير الحركات المالية الشامل": the lightweight
 * status of a background export and the download of a prepared one (one
 * .csv, or a ZIP of several) — never generated here. Same guarantees as the account statement's CSV delivery:
 *
 *  - an authenticated user holding BOTH report permissions (view + export);
 *  - only the CURRENT user's own CSV export — anything else is a plain 404,
 *    indistinguishable from "no such export", and is not audited;
 *  - the download is reached only through a short-lived signed URL, stays
 *    downloadable by its owner until it expires (a browser may request one
 *    download several times) and is audited ONCE, on first delivery, before
 *    a byte is sent.
 */
class ComprehensiveFinancialTransactionsCsvDownloadController extends Controller
{
    /**
     * Polled by the page instead of re-rendering the Livewire page. Bounded
     * response: the state and, once ready, a fresh signed download URL. No
     * report query, no file path, no filters.
     */
    public function status(Request $request, string $export): JsonResponse
    {
        $ownerId = $this->authorizeUser($request);
        $status = ComprehensiveCsvExport::store()->status($ownerId, $export);
        abort_if($status === null || $status['format'] !== 'csv', 404);

        $state = match ($status['state']) {
            AccountStatementExcelExports::COMPLETED => 'ready',
            AccountStatementExcelExports::PENDING => 'pending',
            AccountStatementExcelExports::PROCESSING => 'processing',
            default => 'failed',
        };

        return response()->json(array_filter([
            'id' => $export,
            'state' => $state,
            'download_url' => $state === 'ready' ? self::downloadUrl($export) : null,
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    public static function downloadUrl(string $export): string
    {
        return URL::temporarySignedRoute(
            'reports.comprehensive-financial-transactions.csv',
            now()->addMinutes((int) config(ComprehensiveCsvExport::CONFIG.'.export_url_ttl_minutes', 10)),
            ['export' => $export],
        );
    }

    public function show(Request $request, string $export, ReportExportAuditRecorder $auditRecorder): StreamedResponse
    {
        $ownerId = $this->authorizeUser($request);
        $exports = ComprehensiveCsvExport::store();
        $status = $exports->status($ownerId, $export);
        abort_if($status === null || $status['state'] !== AccountStatementExcelExports::COMPLETED || $status['format'] !== 'csv', 404);

        $stream = $exports->readStream($ownerId, $export);
        abort_if($stream === null, 404);

        $deliveredKey = 'comprehensive-csv-export-delivered:'.$export;
        $firstDelivery = Cache::add($deliveredKey, true, now()->addDay());

        try {
            if ($firstDelivery) {
                $auditRecorder->exportRequested(
                    ReportExportSubject::ComprehensiveFinancialTransactions,
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
                preg_match('/\d{8}_\d{6}/', $filename, $m) === 1 ? "comprehensive-financial-transactions-{$m[0]}.{$extension}" : "comprehensive-financial-transactions.{$extension}",
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
            $user->can(ComprehensiveFinancialTransactionsPage::reportViewPermission())
                && $user->can(ComprehensiveFinancialTransactionsPage::reportExportPermission()),
            403,
        );

        return (int) $user->getAuthIdentifier();
    }
}
