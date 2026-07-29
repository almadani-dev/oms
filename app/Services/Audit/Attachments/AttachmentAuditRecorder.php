<?php

namespace App\Services\Audit\Attachments;

use App\Enums\AuditFailureMode;
use App\Models\Attachment;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single write path for the three REQUIRED `event_category = attachment`
 * WRITE events (OMS Task 9B.5): `uploaded`, `replaced`, `deleted`.
 *
 * Reading a private attachment is NOT written here — that is
 * AttachmentAccessAuditRecorder, which runs on a plain authenticated GET with
 * no caller transaction to belong to. The split is the same structural one
 * Task 9B.4 made between SecurityAuditRecorder and
 * AuthenticationAuditRecorder: this class physically cannot record an access
 * event and that one physically cannot record a write event, so a later edit
 * at a call site cannot quietly move an event to the wrong contract.
 *
 * RULE 1 — the audit insert belongs to the CALLER's transaction.
 * Like FinancialAuditRecorder and SecurityAuditRecorder, this class NEVER
 * opens a transaction. Every real call site is already inside the financial
 * workflow's own DB::transaction() spanning the Transaction, its lines, the
 * account balances, the source record AND the attachment metadata (verified
 * in all five Create pages, all five Edit pages and all five table delete
 * methods). Opening a second one here would let a REQUIRED audit failure roll
 * back nothing but itself, so every entry point asserts a transaction is
 * genuinely open and fails closed.
 *
 * WHAT THAT GUARANTEE COVERS, AND WHAT IT HONESTLY DOES NOT.
 * It covers the DATABASE: the Attachment row and its audit event commit or
 * roll back together, always. It does NOT cover the FILESYSTEM, and this
 * phase does not pretend otherwise — AttachmentUploadService moves the
 * uploaded file to its final path before the surrounding transaction commits,
 * so a rollback (audit failure included) leaves that one new file orphaned on
 * the private disk with no row pointing at it. That was already true of every
 * pre-9B.5 rollback in these workflows and is deliberately left unchanged.
 *
 * The pre-existing crash-safe ordering is likewise untouched. Replacement
 * stores the NEW file first and only then soft-deletes the PREVIOUS
 * Attachment ROW; a prior file is never removed from disk by any path in this
 * application (both replacement and workflow deletion keep it for audit). So
 * integrating a REQUIRED audit event here can never cause an old file to be
 * destroyed ahead of a commit — there is no code that destroys one at all.
 *
 * RULE 2 — one logical file action = exactly one event.
 * `replaced` is ONE event carrying both sides, never an `uploaded` plus a
 * `deleted`. There is deliberately no Attachment model observer and no
 * `Attachment` entry in the general-CRUD AuditSubjectRegistry, so an
 * Attachment save/delete has no independent audit path: uploads and
 * replacements can only be recorded from AttachmentUploadService (the single
 * choke point all ten upload call sites already go through), and deletions
 * only from the explicit call next to each soft delete. A financial operation
 * that also carries a file therefore produces exactly one `financial` event
 * and exactly one `attachment` event — never two of either.
 */
final class AttachmentAuditRecorder
{
    public const EVENT_CATEGORY = 'attachment';

    /**
     * The stable `subject_type` alias for every attachment event. A single
     * alias is correct here: the subject IS the attachment, and WHICH kind of
     * financial parent it belongs to is carried inside the payload as
     * `parent_subject` (a Task 9B.3 workflow alias), never smuggled into the
     * subject type.
     */
    public const SUBJECT_TYPE = 'attachment';

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditActorResolver $actorResolver,
        private readonly AttachmentAuditMetadata $metadata,
    ) {}

    public function metadata(): AttachmentAuditMetadata
    {
        return $this->metadata;
    }

    /**
     * A brand-new attachment on a parent that had none. $attachment must
     * already be at its FINAL state — real id, final deterministic file_name,
     * final file_path, resolved MIME type and size — which is exactly why the
     * only caller is AttachmentUploadService::store(), after its move and its
     * finalizing update() have both succeeded.
     */
    public function uploaded(Attachment $attachment): void
    {
        $this->record(
            action: 'uploaded',
            attachment: $attachment,
            oldValues: null,
            newValues: $this->metadata->of($attachment, 'uploaded'),
            changedFields: null,
        );
    }

    /**
     * One event for a replacement, carrying the previous file's metadata as
     * `old_values` and the replacement's as `new_values`.
     *
     * $previous must have been captured with metadata()->of() BEFORE the
     * replacement row was created, while the outgoing attachment was still
     * the parent's active one.
     *
     * @param  array<string, mixed>  $previous
     */
    public function replaced(array $previous, Attachment $attachment): void
    {
        $current = $this->metadata->of($attachment, 'replaced');
        $diff = $this->metadata->diff($previous, $current);

        $this->record(
            action: 'replaced',
            attachment: $attachment,
            // The full previous metadata, not only the differing fields: it
            // is the last remaining description of a file the application
            // will no longer serve, and it is already bounded.
            oldValues: $previous,
            newValues: $current,
            changedFields: $diff['changed'],
        );
    }

    /**
     * Call BEFORE $attachment->delete(). The metadata captured here is the
     * only remaining description of what was removed — the soft delete keeps
     * the row, but a later force-delete or archive prune would not.
     */
    public function deleted(Attachment $attachment): void
    {
        $this->record(
            action: 'deleted',
            attachment: $attachment,
            oldValues: $this->metadata->of($attachment, 'deleted'),
            newValues: null,
            changedFields: null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $changedFields
     */
    private function record(
        string $action,
        Attachment $attachment,
        ?array $oldValues,
        ?array $newValues,
        ?array $changedFields,
    ): void {
        $this->assertInsideCallerTransaction($action);

        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: $action,
                actor: $this->actorResolver->resolve(),
                subjectType: self::SUBJECT_TYPE,
                subjectKey: $attachment->getKey() === null ? null : (string) $attachment->getKey(),
                subjectLabel: $this->metadata->label($attachment),
                oldValues: $oldValues,
                newValues: $newValues,
                changedFields: $changedFields,
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * Fails closed rather than quietly writing an audit row that could
     * survive a rolled-back financial operation — or, worse, committing
     * attachment metadata whose audit row was lost. A violation is a wiring
     * bug at the call site, never a silently unaudited file action.
     */
    private function assertInsideCallerTransaction(string $action): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(sprintf(
                'An attachment audit event (%s.%s) must be recorded from inside the caller\'s own open DB::transaction().',
                self::EVENT_CATEGORY,
                $action,
            ));
        }
    }
}
