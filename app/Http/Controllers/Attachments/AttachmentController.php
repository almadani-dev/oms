<?php

namespace App\Http\Controllers\Attachments;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only route in the app that ever serves an attachment's bytes. Every
 * other place that wants to show/download an attachment (Filament View
 * pages, a future Resource cutover) must link here - never build a
 * Storage::url() or expose a raw disk path.
 *
 * Authorization is never invented here: it reuses whichever policy already
 * governs the parent record (ProjectCostReceiptPolicy, GeneralExpensePolicy,
 * etc. - all built on the shared AuthorizesCrud trait), so "can I see this
 * attachment" stays identical to "can I view its parent record" everywhere
 * else in the app. No new attachments.* permission is introduced.
 */
class AttachmentController extends Controller
{
    /**
     * Explicit allowlist of attachable_type => model FQCN. An Attachment
     * row whose attachable_type is not a key here is denied outright and
     * never reaches the generic AttachmentPolicy or an arbitrary class
     * instantiation - see the OMS Task 6A audit note on the standalone
     * AttachmentResource, whose own attachable types (Project, Transaction,
     * Partner) are deliberately NOT included until a later phase decides
     * whether/how to support them through this route.
     */
    private const SUPPORTED_ATTACHABLE_TYPES = [
        ProjectCostReceipt::class,
        ProjectCostBudget::class,
        ProjectCostBudgetsPayment::class,
        GeneralExpense::class,
        GeneralExchange::class,
    ];

    public function show(Request $request, string $attachment, string $mode, AttachmentStorageService $storage): StreamedResponse
    {
        abort_unless(in_array($mode, ['view', 'download'], true), 404);

        // The route already constrains {attachment} to \d+, but re-validate
        // defensively: this method must never accept a file path, filename,
        // or disk from the request - the numeric id is the only input.
        abort_unless(ctype_digit($attachment), 404);

        /** @var Attachment|null $record */
        $record = Attachment::withTrashed()->find((int) $attachment);

        abort_if($record === null, 404);

        $attachableType = $record->attachable_type;

        abort_unless(in_array($attachableType, self::SUPPORTED_ATTACHABLE_TYPES, true), 404);

        /** @var \Illuminate\Database\Eloquent\Model|null $parent */
        $parent = $attachableType::withTrashed()->find($record->attachable_id);

        // Missing or soft-deleted parent -> 404, and Gate::authorize() is
        // never called on a trashed parent at all. This is deliberate, not
        // just a shortcut: AuthorizesCrud::view() already denies a trashed
        // parent too, but relying on that would mean the same request could
        // read as either "403 unauthorized" or "403 trashed" depending on
        // the record, which leaks trashed-status to a caller who might not
        // even have general access to the module. Checking trashed() here
        // first keeps that one bit uninferrable, and matches the "soft-
        // deleted Attachment -> 404" rule applying uniformly to parents too.
        abort_if($parent === null || $parent->trashed(), 404);

        // Only reached for an active parent, so this 403 always means
        // exactly one thing: authenticated, but not authorized to view it.
        Gate::authorize('view', $parent);

        // Only reached once the user is confirmed authorized on the active
        // parent, so a soft-deleted Attachment row never leaks its
        // existence to anyone who couldn't see the parent anyway.
        abort_if($record->trashed(), 404);

        abort_unless($storage->resolveDisk($record) !== null, 404);
        abort_unless($storage->exists($record), 404);

        return $storage->toResponse($record, $mode === 'download' ? 'attachment' : 'inline');
    }
}
