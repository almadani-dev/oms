<?php

use App\Http\Controllers\Attachments\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
