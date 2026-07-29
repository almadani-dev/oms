<?php

namespace Tests\Feature\Audit\Attachments;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Tables\GeneralExpensesTable;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\GeneralExpense;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Feature\Audit\Financial\FinancialAuditTestCase;

/**
 * OMS Task 9B.5 — `event_category = attachment` WRITE events, driven through
 * the REAL financial write paths (the actual CreateGeneralExpense /
 * EditGeneralExpense / GeneralExpensesTable methods), not a re-implementation.
 *
 * GeneralExpense is used as the representative workflow on purpose: all five
 * workflows funnel every upload through the one AttachmentUploadService::store()
 * choke point and every deletion through the one explicit recorder call next to
 * their soft delete, so proving the contract on one real path proves the shape
 * of all five. The per-workflow financial payloads themselves are already
 * covered by the Task 9B.3 suite and are only re-asserted here to the extent
 * that attachment auditing must not disturb them.
 */
class AttachmentWriteAuditTest extends FinancialAuditTestCase
{
    private const ALIAS = 'attachment';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('attachments');
    }

    // =========================================================
    // helpers
    // =========================================================

    /**
     * @return array<string, mixed>
     */
    private function data(array $fx, array $overrides = []): array
    {
        return array_merge([
            'amount' => 320,
            'currency_id' => $fx['currency']->id,
            'partner_id' => $fx['partner']->id,
            'description' => 'فاتورة كهرباء',
            'transaction_super_type_id' => null,
            'transaction_type_id' => $fx['transactionType']->id,
            'fiscal_year_id' => $fx['fiscalYear']->id,
            'date' => '2026-07-18',
            'notes' => null,
            'debit_account_id' => $fx['debitAccount']->id,
            'debit_account_type_id' => $fx['debitAccount']->account_type_id,
            'debit_bank_type_id' => $fx['debitAccount']->bank_type_id,
            'credit_account_id' => $fx['creditAccount']->id,
            'credit_account_type_id' => $fx['creditAccount']->account_type_id,
            'credit_bank_type_id' => $fx['creditAccount']->bank_type_id,
            'expense_image' => null,
        ], $overrides);
    }

    /** A Filament-shaped private-disk temporary upload. */
    private function upload(string $name = 'incoming.jpg', string $bytes = 'fake-file-bytes'): string
    {
        $path = 'general-expenses/'.$name;

        Storage::disk('attachments')->put($path, $bytes);

        return $path;
    }

    private function create(array $data): GeneralExpense
    {
        return $this->invoke(new CreateGeneralExpense, 'handleRecordCreation', [$data]);
    }

    private function update(GeneralExpense $record, array $data): GeneralExpense
    {
        return $this->invoke(new EditGeneralExpense, 'handleRecordUpdate', [$record, $data]);
    }

    // =========================================================
    // upload
    // =========================================================

    public function test_upload_writes_exactly_one_attachment_event_with_bounded_metadata(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload()]));
        $attachment = $expense->attachments()->first();

        $event = $this->onlyEventFor(self::ALIAS, 'uploaded');
        $new = $event->new_values;

        $this->assertSame('attachment', $event->event_category);
        $this->assertSame((string) $attachment->id, $event->subject_key);
        $this->assertNull($event->old_values);

        $this->assertSame($attachment->id, $new['attachment_id']);
        $this->assertSame('uploaded', $new['operation']);
        $this->assertSame('general_expense', $new['parent_subject']);
        $this->assertSame((string) $expense->id, $new['parent_id']);
        $this->assertSame('مصروف عام', $new['parent_label']);
        $this->assertStringStartsWith('GEN-', $new['transaction_number']);

        // The FINAL deterministic stored name, never the temporary path's segment.
        $this->assertSame("gen_{$attachment->id}_20260718_320.jpg", $new['file_name']);
        $this->assertSame($attachment->file_type, $new['mime_type']);
        $this->assertSame((int) $attachment->file_size, $new['file_size']);
    }

    public function test_a_financial_operation_without_a_file_writes_no_attachment_event(): void
    {
        $fx = $this->fixture();

        $this->create($this->data($fx));

        $this->assertSame(0, AuditEvent::where('event_category', 'attachment')->count());
        $this->assertSame(1, AuditEvent::where('event_category', 'financial')->count());
    }

    // =========================================================
    // replacement
    // =========================================================

    public function test_replacement_writes_one_event_carrying_both_files_metadata(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload('first.jpg')]));
        $original = $expense->attachments()->first();

        AuditEvent::query()->delete();

        // Deliberately a different length as well as a different extension,
        // so file_size genuinely differs and the diff is exercised on all
        // four diffable fields rather than only three.
        $this->update($expense->fresh(), $this->data($fx, [
            'expense_image' => $this->upload('second.pdf', 'a-noticeably-longer-set-of-fake-file-bytes'),
        ]));

        $event = $this->onlyEventFor(self::ALIAS, 'replaced');
        $replacement = Attachment::orderByDesc('id')->first();

        $this->assertNotSame($original->id, $replacement->id);

        $this->assertSame($original->id, $event->old_values['attachment_id']);
        $this->assertSame("gen_{$original->id}_20260718_320.jpg", $event->old_values['file_name']);

        $this->assertSame($replacement->id, $event->new_values['attachment_id']);
        $this->assertSame("gen_{$replacement->id}_20260718_320.pdf", $event->new_values['file_name']);

        // Only meaningful metadata fields — the parent is identical on both
        // sides of a replacement and must not be reported as changed.
        $this->assertEqualsCanonicalizing(
            ['attachment_id', 'file_name', 'mime_type', 'file_size'],
            $event->changed_fields,
        );
        $this->assertNotContains('parent_subject', $event->changed_fields);
        $this->assertNotContains('parent_id', $event->changed_fields);
    }

    /**
     * The diff reports only what genuinely differs: replacing a file with one
     * of the same size and type changes the id and the name, and says so —
     * it does not pad changed_fields with unchanged metadata.
     */
    public function test_replacement_diff_omits_metadata_that_did_not_change(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload('first.jpg')]));

        AuditEvent::query()->delete();

        $this->update($expense->fresh(), $this->data($fx, ['expense_image' => $this->upload('second.jpg')]));

        $event = $this->onlyEventFor(self::ALIAS, 'replaced');

        $this->assertEqualsCanonicalizing(['attachment_id', 'file_name'], $event->changed_fields);
    }

    public function test_a_replacement_never_also_writes_an_uploaded_or_deleted_event(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload('first.jpg')]));

        AuditEvent::query()->delete();

        $this->update($expense->fresh(), $this->data($fx, ['expense_image' => $this->upload('second.jpg')]));

        $attachmentEvents = AuditEvent::where('event_category', 'attachment')->get();

        $this->assertCount(1, $attachmentEvents);
        $this->assertSame('replaced', $attachmentEvents->first()->event_action);
    }

    // =========================================================
    // deletion
    // =========================================================

    public function test_explicit_removal_preserves_pre_delete_metadata(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload()]));
        $attachment = $expense->attachments()->first();

        AuditEvent::query()->delete();

        $this->update($expense->fresh(), $this->data($fx, [
            'expense_image' => null,
            'remove_current_attachment' => true,
        ]));

        $event = $this->onlyEventFor(self::ALIAS, 'deleted');

        $this->assertTrue($attachment->fresh()->trashed());
        $this->assertNull($event->new_values);
        $this->assertSame($attachment->id, $event->old_values['attachment_id']);
        $this->assertSame("gen_{$attachment->id}_20260718_320.jpg", $event->old_values['file_name']);
        $this->assertSame('general_expense', $event->old_values['parent_subject']);
        $this->assertStringStartsWith('GEN-', $event->old_values['transaction_number']);
    }

    /**
     * The workflow delete soft-deletes the Transaction BEFORE it reaches the
     * attachment, which is exactly the ordering AttachmentAuditMetadata's
     * withTrashed() lookups exist to survive — the transaction number must
     * still be recorded.
     */
    public function test_workflow_delete_writes_one_attachment_event_and_one_financial_event(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload()]));
        $attachment = $expense->attachments()->first();

        AuditEvent::query()->delete();

        GeneralExpensesTable::deleteExpense($expense->fresh());

        $this->assertSame(1, AuditEvent::where('event_category', 'attachment')->count());
        $this->assertSame(1, AuditEvent::where('event_category', 'financial')->count());

        $event = $this->onlyEventFor(self::ALIAS, 'deleted');

        $this->assertSame($attachment->id, $event->old_values['attachment_id']);
        $this->assertStringStartsWith('GEN-', $event->old_values['transaction_number']);
    }

    // =========================================================
    // payload policy
    // =========================================================

    public function test_no_path_disk_or_file_content_ever_reaches_a_payload(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload()]));
        $attachment = $expense->attachments()->first();

        $this->update($expense->fresh(), $this->data($fx, ['expense_image' => $this->upload('second.jpg')]));

        $events = AuditEvent::where('event_category', 'attachment')->get();

        $this->assertCount(2, $events);

        foreach ($events as $event) {
            $encoded = json_encode([$event->old_values, $event->new_values], JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('fake-file-bytes', $encoded);
            $this->assertStringNotContainsString('general-expenses/', $encoded);
            $this->assertStringNotContainsString('livewire-tmp', $encoded);
            $this->assertStringNotContainsString(Attachment::DISK_ATTACHMENTS, $encoded);
            $this->assertStringNotContainsString(storage_path(), $encoded);

            foreach ([$event->old_values, $event->new_values] as $payload) {
                if ($payload === null) {
                    continue;
                }

                $this->assertArrayNotHasKey('file_path', $payload);
                $this->assertArrayNotHasKey('disk', $payload);
                $this->assertArrayNotHasKey('temp_path', $payload);
                $this->assertArrayNotHasKey('url', $payload);

                foreach ($payload as $key => $value) {
                    $this->assertIsNotArray($value, "Attachment payload key [{$key}] must be a scalar.");
                }
            }
        }

        // The row itself still stores its real private path — only the audit
        // payload is stripped, never the application data.
        $this->assertStringContainsString('general-expenses/', $attachment->file_path);
    }

    // =========================================================
    // atomicity / fail-closed
    // =========================================================

    public function test_a_required_attachment_audit_failure_rolls_back_the_whole_operation(): void
    {
        $fx = $this->fixture();

        Schema::drop('audit_events');

        try {
            $this->create($this->data($fx, ['expense_image' => $this->upload()]));
            $this->fail('Expected an AuditPersistenceException when the audit row cannot be written.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame(0, GeneralExpense::withTrashed()->count());
    }

    public function test_recording_outside_a_transaction_fails_closed(): void
    {
        $fx = $this->fixture();

        $expense = $this->create($this->data($fx, ['expense_image' => $this->upload()]));
        $attachment = $expense->attachments()->first();

        $this->expectException(LogicException::class);

        app(AttachmentAuditRecorder::class)->deleted($attachment);
    }
}
