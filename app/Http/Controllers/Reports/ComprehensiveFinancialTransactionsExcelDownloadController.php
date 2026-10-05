<?php

namespace App\Http\Controllers\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Http\Controllers\Controller;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers a large-report workbook of "تقرير الحركات المالية الشامل" that a
 * queued job has already prepared — an ordinary streamed file download, never
 * base64 inside a Livewire response, and never generated inside this request.
 *
 * Reached only through a short-lived signed URL the page hands out, and still
 * re-checks everything itself, in this order:
 *  1. an authenticated user (route middleware) holding the export permission;
 *  2. a COMPLETED export owned by THIS user, whose snapshot is still readable
 *     by THIS user — anything else (someone else's id, pending, failed,
 *     expired, already downloaded) is a plain 404 and nothing is audited;
 *  3. the Required export audit, written before a byte is sent, with the
 *     same payload as the page's own exports.
 *
 * The prepared file is removed once it has been streamed (or the stream
 * failed); a file never downloaded is removed by oms:purge-report-snapshots.
 */
class ComprehensiveFinancialTransactionsExcelDownloadController extends Controller
{
    public function show(
        Request $request,
        string $export,
        ComprehensiveFinancialTransactionsExcelExports $exports,
        ComprehensiveFinancialTransactionsSnapshotRepository $snapshots,
        ReportExportAuditRecorder $auditRecorder,
    ): StreamedResponse {
        $user = $request->user();
        abort_if($user === null, 403);
        abort_unless($user->can(ComprehensiveFinancialTransactionsPage::reportExportPermission()), 403);

        $ownerId = (int) $user->getAuthIdentifier();
        $status = $exports->status($ownerId, $export);
        abort_if($status === null || $status['state'] !== ComprehensiveFinancialTransactionsExcelExports::COMPLETED, 404);

        $snapshot = $snapshots->find($ownerId, $status['snapshot_id']);
        abort_if($snapshot === null, 404);

        $stream = $exports->readStream($ownerId, $export);
        abort_if($stream === null, 404);

        $auditRecorder->exportRequested(
            ReportExportSubject::ComprehensiveFinancialTransactions,
            ReportExportFormat::Xlsx,
            ComprehensiveFinancialTransactionsPage::exportAuditFilters($snapshot, true),
        );

        $size = $exports->size($ownerId, $export);

        return new StreamedResponse(function () use ($stream, $exports, $ownerId, $export): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $exports->discard($ownerId, $export);
            }
        }, 200, array_filter([
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, (string) $status['filename']),
            'Content-Length' => $size !== null ? (string) $size : null,
        ]));
    }
}
