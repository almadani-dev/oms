<?php

namespace App\Filament\Pages\Concerns;

/**
 * Shared authorization wiring for the six custom report pages. Each
 * concrete page explicitly declares its own two PermissionRegistry
 * permission names (no name-based mapping from the class name) via
 * reportViewPermission()/reportExportPermission().
 *
 * canAccess() plugs into Filament\Pages\Concerns\CanAuthorizeAccess, which
 * every Filament\Pages\Page already uses: it gates navigation-item
 * registration (Page::registerNavigationItems()) AND aborts 403 on both
 * mount (direct URL access) and every subsequent Livewire hydration —
 * hydrateCanAuthorizeAccess() runs before any action/method call is
 * dispatched, so the view permission is re-checked on every request, not
 * only the first one.
 *
 * The view-permission check on every hydration does not by itself protect
 * the export permission (a user can legitimately hold view without export),
 * so authorizeReportExport() must be called explicitly as the first
 * statement of every export method — this is what stops a crafted Livewire
 * request from invoking exportExcel()/exportWord()/exportXlsx() directly
 * when only the header action's visible() is hiding the button.
 */
trait AuthorizesReportAccess
{
    abstract public static function reportViewPermission(): string;

    abstract public static function reportExportPermission(): string;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(static::reportViewPermission());
    }

    /**
     * Call as the first statement of every export method. Independent of
     * the header action's visible() state, and independent of canAccess()
     * having already run for this request — always re-checks both
     * permissions before any Excel/Word file is generated.
     */
    protected function authorizeReportExport(): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->can(static::reportViewPermission()) && $user?->can(static::reportExportPermission()),
            403,
        );
    }

    protected function canExportReport(): bool
    {
        return (bool) auth()->user()?->can(static::reportExportPermission());
    }
}
