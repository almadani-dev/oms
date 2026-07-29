<?php

namespace Tests\Feature\Audit\Attachments;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\GeneralExpense;
use App\Models\Project;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * OMS Task 9B.5 — auditing of the single route that ever serves an
 * attachment's bytes (routes/web.php -> AttachmentController::show).
 *
 * Exercised as REAL authenticated HTTP requests against the real route and
 * the real Filament Authenticate middleware, using the same schema-only
 * SQLite + Storage::fake bootstrap as the Task 6A suite this extends the
 * behaviour of (Tests\Feature\Attachments\AttachmentAccessTest) — the point
 * is to prove the audit contract on the actual response path, including that
 * every pre-existing authorization and 404 outcome is unchanged.
 */
class AttachmentAccessAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        URL::forceRootUrl('http://localhost');

        Storage::fake('public');
        Storage::fake('attachments');
    }

    // =========================================================
    // authorized access
    // =========================================================

    public function test_viewing_writes_exactly_one_event_with_real_request_metadata(): void
    {
        [$expense, $attachment] = $this->expenseWithAttachment();

        $user = $this->userWithPermissions(['general_expenses.view']);
        $this->actingAs($user);

        $this->withHeaders(['User-Agent' => 'OMS-Test-Agent'])
            ->get($this->url($attachment, 'view'))
            ->assertOk();

        $this->assertSame(1, AuditEvent::count());

        $event = AuditEvent::first();

        $this->assertSame('attachment', $event->event_category);
        $this->assertSame('viewed', $event->event_action);
        $this->assertSame('attachment', $event->subject_type);
        $this->assertSame((string) $attachment->id, $event->subject_key);
        $this->assertSame('success', $event->status->value);

        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('attachments.show', $event->route_name);
        $this->assertSame('GET', $event->http_method);
        $this->assertSame('OMS-Test-Agent', $event->user_agent);
        $this->assertNotNull($event->ip_address);

        $this->assertSame('general_expense', $event->new_values['parent_subject']);
        $this->assertSame((string) $expense->id, $event->new_values['parent_id']);
        $this->assertSame('viewed', $event->new_values['operation']);
    }

    /**
     * view and download are two genuinely different, explicitly requested
     * operations in this application — the mode is a route path segment
     * constrained to `view|download`, and it selects an inline preview versus
     * an attachment download. Nothing here is inferred from a browser header.
     */
    public function test_downloading_is_recorded_as_a_distinct_action(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $this->get($this->url($attachment, 'download'))->assertOk();

        $this->assertSame(1, AuditEvent::count());
        $this->assertSame('downloaded', AuditEvent::first()->event_action);
    }

    public function test_two_accesses_write_two_events_and_one_access_writes_one(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $this->get($this->url($attachment, 'view'))->assertOk();

        $this->assertSame(1, AuditEvent::count());

        $this->get($this->url($attachment, 'download'))->assertOk();

        $this->assertSame(2, AuditEvent::count());
        $this->assertSame(
            ['viewed', 'downloaded'],
            AuditEvent::orderBy('id')->pluck('event_action')->all(),
        );
    }

    public function test_the_access_payload_never_carries_a_path_disk_or_file_content(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));
        $this->get($this->url($attachment, 'view'))->assertOk();

        $payload = AuditEvent::first()->new_values;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('fake-file-bytes', $encoded);
        $this->assertStringNotContainsString('general-expenses/', $encoded);
        $this->assertStringNotContainsString(Attachment::DISK_ATTACHMENTS, $encoded);
        $this->assertArrayNotHasKey('file_path', $payload);
        $this->assertArrayNotHasKey('disk', $payload);
    }

    // =========================================================
    // Required: no audit row, no bytes
    // =========================================================

    public function test_a_required_audit_failure_prevents_the_file_from_being_served(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        Schema::drop('audit_events');

        // withoutExceptionHandling() would be the wrong tool here: the point
        // is that the request does NOT succeed, whatever the rendered status.
        $response = $this->get($this->url($attachment, 'view'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // =========================================================
    // denial (403) — BestEffort
    // =========================================================

    public function test_an_unauthorized_access_is_still_denied_and_logs_one_best_effort_event(): void
    {
        [$expense, $attachment] = $this->expenseWithAttachment();

        $this->actingAs(User::factory()->create());

        $this->get($this->url($attachment, 'view'))->assertForbidden();

        $this->assertSame(1, AuditEvent::count());

        $event = AuditEvent::first();

        $this->assertSame('security', $event->event_category);
        $this->assertSame('attachment_access_denied', $event->event_action);
        $this->assertSame('attachment', $event->subject_type);
        $this->assertSame((string) $attachment->id, $event->subject_key);
        $this->assertSame('failure', $event->status->value);
        $this->assertSame((string) $expense->id, $event->new_values['parent_id']);
    }

    public function test_a_denial_survives_an_audit_storage_outage(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        $this->actingAs(User::factory()->create());

        Schema::drop('audit_events');

        // BestEffort: the 403 must stand, never becoming a 500.
        $this->get($this->url($attachment, 'view'))->assertForbidden();
    }

    public function test_a_permission_for_another_module_is_denied_and_recorded_once(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        $this->actingAs($this->userWithPermissions(['project_cost_receipts.view']));

        $this->get($this->url($attachment, 'view'))->assertForbidden();

        $this->assertSame(1, AuditEvent::count());
        $this->assertSame('attachment_access_denied', AuditEvent::first()->event_action);
    }

    // =========================================================
    // 404s are never audited
    // =========================================================

    public function test_an_unknown_attachment_id_creates_no_event(): void
    {
        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $this->get('/attachments/999999/view')->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_soft_deleted_attachment_creates_no_event(): void
    {
        [, $attachment] = $this->expenseWithAttachment();
        $attachment->delete();

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $this->get($this->url($attachment, 'view'))->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_missing_physical_file_creates_no_event(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        Storage::disk(Attachment::DISK_ATTACHMENTS)->delete($attachment->file_path);

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $this->get($this->url($attachment, 'view'))->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_an_unsupported_attachable_type_creates_no_event(): void
    {
        $attachment = $this->makeAttachment(Project::class, 1);

        $this->actingAs($this->superAdmin());

        $this->get($this->url($attachment, 'view'))->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_guest_is_redirected_and_creates_no_event(): void
    {
        [, $attachment] = $this->expenseWithAttachment();

        $this->get($this->url($attachment, 'view'))->assertRedirect('http://localhost/admin/login');

        $this->assertSame(0, AuditEvent::count());
    }

    // =========================================================
    // helpers
    // =========================================================

    /**
     * @return array{0: GeneralExpense, 1: Attachment}
     */
    private function expenseWithAttachment(): array
    {
        $expense = GeneralExpense::create(['amount' => 100, 'date' => '2026-07-01']);

        return [$expense, $this->makeAttachment(GeneralExpense::class, $expense->id)];
    }

    private function makeAttachment(string $attachableType, int $attachableId): Attachment
    {
        $fileName = 'gen_1_20260701_100.jpg';
        $path = 'general-expenses/'.$fileName;

        Storage::disk(Attachment::DISK_ATTACHMENTS)->put($path, 'fake-file-bytes');

        return Attachment::create([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'file_name' => $fileName,
            'file_path' => $path,
            'file_type' => 'image/jpeg',
            'file_size' => 15,
            'disk' => Attachment::DISK_ATTACHMENTS,
        ]);
    }

    private function url(Attachment $attachment, string $mode): string
    {
        return "/attachments/{$attachment->id}/{$mode}";
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }
}
