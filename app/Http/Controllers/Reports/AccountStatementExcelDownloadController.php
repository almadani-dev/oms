<?php

namespace App\Http\Controllers\Reports;

use App\Filament\Pages\AccountStatementPage;
use App\Http\Controllers\Controller;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers a large "تقرير كشف الحساب" workbook a queued job already
 * prepared — an ordinary streamed download, never generated here.
 *
 * Reached only through a short-lived signed URL the page hands out, and
 * still re-checks: an authenticated user holding BOTH report permissions; a
 * COMPLETED export owned by THIS user (anything else is a plain 404 and is
 * not audited); then the Required export audit, with the page's own payload,
 * before a byte is sent. A workbook is removed once streamed; a «تصدير CSV»
 * package stays downloadable by its owner until it expires (a browser may
 * request one download several times) and is audited on first delivery.
 */
class AccountStatementExcelDownloadController extends Controller
{
    /**
     * Lightweight status of a background export, polled by the page instead
     * of re-rendering the whole Livewire page. Same guards as the download:
     * both report permissions, and only the CURRENT user's own export —
     * anything else is a plain 404, indistinguishable from "no such export".
     *
     * Bounded response: the state and, once ready, a short-lived signed
     * download URL. No report query, no file path, no filters.
     */
    public function status(Request $request, string $export, AccountStatementExcelExports $exports): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);
        abort_unless($user->can(AccountStatementPage::reportViewPermission()) && $user->can(AccountStatementPage::reportExportPermission()), 403);

        $status = $exports->status((int) $user->getAuthIdentifier(), $export);
        abort_if($status === null, 404);

        $state = match ($status['state']) {
            AccountStatementExcelExports::COMPLETED => 'ready',
            AccountStatementExcelExports::PENDING => 'pending',
            AccountStatementExcelExports::PROCESSING => 'processing',
            default => 'failed',
        };

        return response()->json(array_filter([
            'id' => $export,
            'state' => $state,
            'download_url' => $state === 'ready' ? self::downloadUrl($export, $status['format']) : null,
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Short-lived signed download URL of a completed export of $format.
     */
    public static function downloadUrl(string $export, string $format): string
    {
        return URL::temporarySignedRoute(
            $format === 'csv' ? 'reports.account-statement.csv' : 'reports.account-statement.xlsx',
            now()->addMinutes((int) config('oms.reports.account_statement.export_url_ttl_minutes', 10)),
            ['export' => $export],
        );
    }

    public function show(
        Request $request,
        string $export,
        AccountStatementExcelExports $exports,
        ReportExportAuditRecorder $auditRecorder,
    ): StreamedResponse {
        return $this->deliver($request, $export, $exports, $auditRecorder, 'xlsx');
    }

    /**
     * «تصدير CSV» package (ZIP). Same guarantees as the workbook.
     */
    public function csv(
        Request $request,
        string $export,
        AccountStatementExcelExports $exports,
        ReportExportAuditRecorder $auditRecorder,
    ): StreamedResponse {
        return $this->deliver($request, $export, $exports, $auditRecorder, 'csv');
    }

    private function deliver(
        Request $request,
        string $export,
        AccountStatementExcelExports $exports,
        ReportExportAuditRecorder $auditRecorder,
        string $format,
    ): StreamedResponse {
        $user = $request->user();
        abort_if($user === null, 403);
        abort_unless($user->can(AccountStatementPage::reportViewPermission()) && $user->can(AccountStatementPage::reportExportPermission()), 403);

        $ownerId = (int) $user->getAuthIdentifier();
        $status = $exports->status($ownerId, $export);
        abort_if($status === null || $status['state'] !== AccountStatementExcelExports::COMPLETED || $status['format'] !== $format, 404);

        $stream = $exports->readStream($ownerId, $export);
        abort_if($stream === null, 404);

        if ($format === 'csv') {
            // A browser may fetch one download URL several times (download
            // re-checks, "Keep" on a held download, retries), so the CSV
            // package stays downloadable by its owner until it expires
            // (signed URL TTL; files purged by oms:purge-report-snapshots)
            // and is audited ONCE — on its first delivery.
            $firstDelivery = Cache::add('account-statement-export-delivered:'.$export, true, now()->addDay());

            try {
                if ($firstDelivery) {
                    $auditRecorder->exportRequested(
                        ReportExportSubject::AccountStatement,
                        ReportExportFormat::Csv,
                        (array) ($status['payload']['audit'] ?? []) + ['status' => 'completed'] + $status['stats'],
                    );
                }
            } catch (\Throwable $e) {
                Cache::forget('account-statement-export-delivered:'.$export);
                fclose($stream);

                throw $e;
            }
        } else {
            $auditRecorder->exportRequested(
                ReportExportSubject::AccountStatement,
                ReportExportFormat::Xlsx,
                (array) ($status['payload']['audit'] ?? []),
            );
        }

        $size = $exports->size($ownerId, $export);
        $filename = (string) $status['filename'];

        return new StreamedResponse(function () use ($stream, $exports, $ownerId, $export, $format): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($format !== 'csv') {
                    $exports->discard($ownerId, $export);
                }
            }
        }, 200, array_filter([
            'Content-Type' => $format === 'csv' ? 'application/zip' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => $format === 'csv'
                ? HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, preg_match('/\d{8}_\d{6}/', $filename, $m) === 1 ? "account-statement-{$m[0]}.zip" : 'account-statement.zip')
                : HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Content-Length' => $size !== null ? (string) $size : null,
            'Cache-Control' => $format === 'csv' ? 'no-store' : null,
        ]));
    }
}
