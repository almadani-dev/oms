<?php

namespace App\Services\Audit\Attachments;

use App\Models\Attachment;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Attachments\FinancialAttachmentRegistry;
use App\Services\Audit\Financial\FinancialAuditSubject;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The single place an Attachment row is turned into the bounded metadata an
 * `event_category = attachment` (or a `security.attachment_access_denied`)
 * payload may carry (OMS Task 9B.5).
 *
 * WHAT IS DELIBERATELY NOT HERE.
 * Never `file_path`, never the stored `disk` name, never the Filament
 * private-disk temporary upload path, never a signed URL, never a token, and
 * never one byte of file content. Those are exactly the values that would
 * turn an audit row into a map of the private attachments disk, and the only
 * way to guarantee they cannot appear is to never read them into a payload
 * at all — the closed field list built by of() is the whole payload, not a
 * denylist applied to a wider one.
 *
 * THE ORIGINAL CLIENT FILENAME IS NOT RECORDED, BECAUSE IT DOES NOT EXIST.
 * Verified against the five financial forms (e.g. GeneralExpenseForm's
 * FileUpload): none calls preserveFilenames(), so Filament stores the upload
 * under a generated storage name and the browser-supplied name is gone before
 * any application code — including AttachmentUploadService — ever sees it.
 * `file_name` below is therefore the FINAL deterministic stored name
 * ("gen_12_20260105_500.pdf"), which is the only authoritative name this
 * application has. Recording an "original_file_name" would mean inventing one.
 *
 * `file_name` is additionally passed through
 * AttachmentStorageService::safeDownloadName() — the same sanitizer that
 * guards Content-Disposition — so a hand-edited or path-shaped stored value
 * can never surface directory segments or control characters into the audit
 * trail. It still goes through AuditRedactor + AuditPayloadBounder afterward
 * like every other payload value.
 *
 * ORDERING-INDEPENDENT BY CONSTRUCTION. Every lookup uses withTrashed(), so
 * of() returns the same metadata whether it is called before or after the
 * parent record and its Transaction have been soft-deleted. That matters
 * because the five workflow delete paths soft-delete the Transaction BEFORE
 * they reach the attachment (see e.g. GeneralExpensesTable::deleteExpense),
 * and a pre-delete snapshot that silently lost its transaction number
 * depending on call order would be worse than none.
 */
final class AttachmentAuditMetadata
{
    /**
     * attachable FQCN => stable workflow alias. Reuses the Task 9B.3 enum on
     * purpose: an attachment's parent IS one of the five financial workflows,
     * and `parent_subject` must name it with the exact same stable alias the
     * parent's own `financial` event uses, so the two events for one action
     * can be correlated. A PHP FQCN is never written to a payload.
     *
     * Deliberately identical to AttachmentController::SUPPORTED_ATTACHABLE_TYPES
     * and FinancialAttachmentRegistry::supportedTypes(); an attachable_type
     * outside this list yields a null alias, never a derived one.
     */
    private const PARENT_SUBJECTS = [
        ProjectCostReceipt::class => FinancialAuditSubject::ProjectCostReceipt,
        ProjectCostBudget::class => FinancialAuditSubject::ProjectDisbursement,
        ProjectCostBudgetsPayment::class => FinancialAuditSubject::ExecutionPayment,
        GeneralExpense::class => FinancialAuditSubject::GeneralExpense,
        GeneralExchange::class => FinancialAuditSubject::GeneralExchange,
    ];

    /**
     * The only metadata fields a REPLACEMENT may report in `changed_fields`.
     * The parent fields are identical on both sides by construction (a
     * replacement always attaches to the same parent), so listing them would
     * add noise to every replacement without describing a change.
     */
    public const DIFFABLE_FIELDS = [
        'attachment_id',
        'file_name',
        'mime_type',
        'file_size',
    ];

    public function __construct(private readonly AttachmentStorageService $storage) {}

    /**
     * The complete, closed metadata payload for one attachment.
     *
     * @return array<string, mixed>
     */
    public function of(Attachment $attachment, string $operation): array
    {
        $parent = $this->parent($attachment);

        return [
            'attachment_id' => $attachment->getKey(),
            'operation' => $operation,
            'parent_subject' => $this->parentSubject($attachment)?->value,
            // Named `parent_id`, NOT `parent_key`. It holds the parent's
            // ordinary primary key and is not key material of any kind, but
            // AuditRedactor is (correctly, globally) segment-based and would
            // redact any field with a bare `key` segment — which would erase
            // the one identifier that links this event to its parent's own
            // financial event. Same resolution the Setting subject uses for
            // its `key` column (see AuditSubjectRegistry's `setting_name`
            // alias): pick a safe semantic name here rather than weaken the
            // redactor for every other subject.
            'parent_id' => $attachment->attachable_id === null
                ? null
                : (string) $attachment->attachable_id,
            'parent_label' => FinancialAttachmentRegistry::labelFor($attachment->attachable_type),
            'transaction_number' => $this->transactionNumber($parent),
            'file_name' => $this->storage->safeDownloadName($attachment),
            'mime_type' => $this->mimeType($attachment),
            'file_size' => is_numeric($attachment->file_size) ? (int) $attachment->file_size : null,
        ];
    }

    /**
     * A short human reference for `subject_label`, e.g.
     * "مصروف عام — gen_12_20260105_500.pdf". AuditLogger bounds it to 255.
     */
    public function label(Attachment $attachment): ?string
    {
        $parts = array_values(array_filter([
            FinancialAttachmentRegistry::labelFor($attachment->attachable_type),
            $this->storage->safeDownloadName($attachment),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /**
     * The metadata fields that genuinely differ between the replaced
     * attachment and its replacement.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array{old: array<string, mixed>, new: array<string, mixed>, changed: array<int, string>}
     */
    public function diff(array $previous, array $current): array
    {
        $old = [];
        $new = [];
        $changed = [];

        foreach (self::DIFFABLE_FIELDS as $field) {
            if (($previous[$field] ?? null) === ($current[$field] ?? null)) {
                continue;
            }

            $old[$field] = $previous[$field] ?? null;
            $new[$field] = $current[$field] ?? null;
            $changed[] = $field;
        }

        return ['old' => $old, 'new' => $new, 'changed' => $changed];
    }

    private function parentSubject(Attachment $attachment): ?FinancialAuditSubject
    {
        $type = $attachment->attachable_type;

        return is_string($type) ? (self::PARENT_SUBJECTS[$type] ?? null) : null;
    }

    /**
     * withTrashed() on purpose — see the class docblock. Returns null for an
     * attachable_type outside the closed allowlist, so an arbitrary class
     * string in the column can never be instantiated or queried here.
     */
    private function parent(Attachment $attachment): ?Model
    {
        $subject = $this->parentSubject($attachment);

        if ($subject === null || $attachment->attachable_id === null) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $attachment->attachable_type;

        return $class::withTrashed()->find($attachment->attachable_id);
    }

    /**
     * withTrashed() again: by the time a workflow delete reaches its
     * attachment, the Transaction it belongs to is already soft-deleted, and
     * the transaction number is the single most useful correlation key on the
     * whole event.
     */
    private function transactionNumber(?Model $parent): ?string
    {
        if ($parent === null) {
            return null;
        }

        try {
            $transaction = $parent->transaction()->withTrashed()->first();
        } catch (Throwable) {
            return null;
        }

        $number = $transaction?->transaction_number;

        return is_string($number) && $number !== '' ? $number : null;
    }

    /**
     * The STORED file_type only, and only when it looks like a genuine MIME
     * token. Deliberately NOT AttachmentStorageService::mimeType(), which
     * reads the file off the disk: building an audit payload must never
     * depend on the filesystem being reachable, and must never touch the
     * bytes of the file it is describing.
     */
    private function mimeType(Attachment $attachment): ?string
    {
        $stored = $attachment->file_type;

        return is_string($stored) && str_contains($stored, '/') ? $stored : null;
    }
}
