<?php

use App\Http\Controllers\Attachments\AttachmentController;
use App\Http\Controllers\Backups\BackupDownloadController;
use App\Http\Controllers\Reports\AccountStatementExcelDownloadController;
use App\Http\Controllers\Reports\ComprehensiveFinancialTransactionsCsvDownloadController;
use App\Http\Controllers\Reports\ComprehensiveFinancialTransactionsExcelDownloadController;
use App\Http\Controllers\Restores\RestoreLaunchController;
use App\Http\Controllers\Restores\RestoreProgressPollController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The application root is not a public landing page - this is an internal
// admin-only system, so `/` sends visitors straight to the Filament Admin
// panel's own login screen.
//
// Deliberately a *named*-route redirect against the verified route name
// (filament.admin.auth.login, confirmed via `route:list --path=admin/login`)
// rather than a literal '/admin/login' string or anything derived from
// APP_URL. Laravel's UrlGenerator builds a named route's URL from the
// incoming request's own root - scheme, host, port and base path - so the
// same line resolves to https://<host>/admin/login when the document root
// is the app itself, and to http://<host>/oms/public/admin/login when the
// app is served from a subdirectory. Nothing about either deployment is
// hard-coded here, and the panel's own /admin/login route is untouched.
Route::get('/', function () {
    return redirect()->route('filament.admin.auth.login');
});

// Single authenticated route for serving attachment files (OMS Task 6A).
//
// Uses Filament's own Authenticate middleware - the exact class
// AdminPanelProvider's ->authMiddleware() applies - rather than Laravel's
// bare 'auth' alias. Verified against AdminPanelProvider: it never calls
// ->authGuard(), so Filament::auth()/Filament::getAuthGuard() resolve to
// the same default 'web' guard Laravel's own 'auth' middleware would use.
// The reason to still prefer Filament's class over the plain guard name:
// its redirectTo() returns Filament::getLoginUrl() instead of route('login')
// (this app has no route literally named 'login', only the panel's own
// filament.admin.auth.login), and its authenticate() also runs the same
// canAccessPanel() check every other admin request goes through - so a
// deactivated/soft-deleted user is denied here exactly as everywhere else.
//
// {attachment} is constrained to digits only and {mode} to view|download -
// no file path, filename, or disk is ever accepted from the request.
Route::get('/attachments/{attachment}/{mode}', [AttachmentController::class, 'show'])
    ->whereNumber('attachment')
    ->whereIn('mode', ['view', 'download'])
    ->middleware(\Filament\Http\Middleware\Authenticate::class)
    ->name('attachments.show');

// OMS Task 7B.1 — secure download foundation for backup archives. {backup}
// is the BackupOperation's UUID (never its stored_path or a disk path) and
// is constrained to the UUID shape before the controller ever runs a
// query. No Filament UI links here yet (Task 7B.2) — this route exists so
// that later UI has a safe, already-authorized endpoint to point at.
Route::get('/backups/{backup}/download', [BackupDownloadController::class, 'show'])
    ->where('backup', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->middleware(\Filament\Http\Middleware\Authenticate::class)
    ->name('backups.download');

// Large-report Excel download for "تقرير الحركات المالية الشامل": serves a
// workbook a queued job already prepared. {export} is the opaque export ULID —
// never a path — constrained to that shape before the controller runs.
// Filament's own Authenticate middleware (same guard as the panel) plus a
// short-lived signature issued by the page; the controller still re-checks the
// export permission, resolves the export and its snapshot for the CURRENT user
// only, and audits before sending a byte.
Route::get('/reports/comprehensive-financial-transactions/exports/{export}/xlsx', [ComprehensiveFinancialTransactionsExcelDownloadController::class, 'show'])
    ->where('export', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware([\Filament\Http\Middleware\Authenticate::class, 'signed'])
    ->name('reports.comprehensive-financial-transactions.xlsx');

// «تصدير CSV» package (ZIP of UTF-8 CSVs) of "تقرير الحركات المالية الشامل" —
// same shape and guarantees as the account statement's CSV routes: opaque
// ULID, Filament auth + signature on the download; the controller re-checks
// both report permissions and ownership, and audits the first delivery.
Route::get('/reports/comprehensive-financial-transactions/exports/{export}/csv', [ComprehensiveFinancialTransactionsCsvDownloadController::class, 'show'])
    ->where('export', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware([\Filament\Http\Middleware\Authenticate::class, 'signed'])
    ->name('reports.comprehensive-financial-transactions.csv');

// Lightweight JSON status of a background CSV package, polled by the page
// (never a Livewire re-render). Owner-scoped and permission-checked in the
// controller; not signed — it never serves the file.
Route::get('/reports/comprehensive-financial-transactions/exports/{export}/csv-status', [ComprehensiveFinancialTransactionsCsvDownloadController::class, 'status'])
    ->where('export', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware(\Filament\Http\Middleware\Authenticate::class)
    ->name('reports.comprehensive-financial-transactions.csv-status');

// Large-report Excel download for "تقرير كشف الحساب" — same shape and
// guarantees as the route above (opaque ULID, Filament auth + signature;
// the controller re-checks both report permissions and ownership, and
// audits before sending a byte).
Route::get('/reports/account-statement/exports/{export}/xlsx', [AccountStatementExcelDownloadController::class, 'show'])
    ->where('export', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware([\Filament\Http\Middleware\Authenticate::class, 'signed'])
    ->name('reports.account-statement.xlsx');

// «تصدير CSV» package (ZIP of UTF-8 CSVs) of "تقرير كشف الحساب" — same
// shape and guarantees as the workbook route above; the export must be a CSV
// export of the CURRENT user.
Route::get('/reports/account-statement/exports/{export}/csv', [AccountStatementExcelDownloadController::class, 'csv'])
    ->where('export', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware([\Filament\Http\Middleware\Authenticate::class, 'signed'])
    ->name('reports.account-statement.csv');

// Lightweight JSON status of that background export, polled by the page
// (instead of re-rendering the whole Livewire page every 2 s). Owner-scoped
// and permission-checked in the controller; not signed — it never serves
// the file, only the state and (when ready) a fresh signed download URL.
Route::get('/reports/account-statement/exports/{export}/status', [AccountStatementExcelDownloadController::class, 'status'])
    ->where('export', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware(\Filament\Http\Middleware\Authenticate::class)
    ->name('reports.account-statement.export-status');

// OMS Task 7C.4 — signed launch endpoint for an already-created, fully
// validated queued restore operation. {uuid} is the restore's own
// BackupOperation UUID; the only other accepted query values are the
// random launch nonce and Laravel's own signature/expiration fields (see
// RestoreLaunchController's docblock). No Filament UI links here yet —
// this route exists so a later phase's signed-URL generator has a safe,
// already-authorized endpoint to point at.
//
// OMS Task 7C.4 correction pass: POST, not GET — this endpoint mutates
// state (claims a row) and spawns a process, so a plain signed GET could be
// triggered by browser prefetching, link scanners/crawlers, or accidental
// navigation. POST keeps the normal 'web' group's CSRF verification active
// (bypassed automatically only while PHPUnit is running, per Laravel's own
// VerifyCsrfToken::runningUnitTests()) in addition to the signed-URL check.
// There is deliberately no GET route at this path at all — an unauthorized
// GET here 404s/405s via Laravel's own routing, never reaching the
// controller, so it can never claim or spawn anything.
Route::post('/restores/{uuid}/launch', [RestoreLaunchController::class, 'launch'])
    ->where('uuid', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->middleware([\Filament\Http\Middleware\Authenticate::class, 'signed'])
    ->name('restores.launch');

// OMS Task 7C.8 section H — the DB-independent private restore progress
// polling endpoint. Deliberately NOT behind Filament's Authenticate
// middleware (which resolves a session-backed guard) and NOT inside the
// normal 'web' group's DB-touching members: this app's session AND cache
// stores are both the `database` driver (see config/session.php,
// config/cache.php), so StartSession alone would issue a query on every
// request — exactly what must never happen here, since this endpoint's
// whole purpose is staying answerable while the database a restore is
// actively replacing is unreachable. Authorization is the `signed`
// middleware alone: a short-lived Laravel signed URL bound to this exact
// {uuid}, carrying only the restore UUID, an expiration, and a signature —
// itself a pure HMAC-over-URL computation against APP_KEY, needing no
// database, cache, or session either. See bootstrap/app.php for the
// matching maintenance-mode exception that keeps this one path (and no
// other admin/API surface) reachable during `php artisan down`.
Route::get('/restores/{uuid}/progress', [RestoreProgressPollController::class, 'show'])
    ->where('uuid', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->middleware('signed')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('restores.progress.poll');
