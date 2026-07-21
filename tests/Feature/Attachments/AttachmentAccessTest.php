<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\Currency;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * OMS Task 6A: proves the single authenticated attachment route
 * (AttachmentController::show / 'attachments.show') authorizes strictly
 * against the attachment's parent record via the parent's own existing
 * policy, never trusts a request-supplied path/filename/disk, treats a
 * soft-deleted Attachment or an unsupported/unknown disk as unavailable,
 * and serves both legacy ('public') and new ('attachments') disk rows
 * identically during the Task 6B transition window.
 *
 * Uses the same schema-only SQLite migration bootstrap as the existing
 * Permissions test suite (see ResourceHttpAuthorizationTest) plus
 * Storage::fake() for both disks - no real file or real database row is
 * ever touched.
 */
class AttachmentAccessTest extends TestCase
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
    // 1. Guest cannot access an attachment
    // =========================================================

    public function test_guest_cannot_access_an_attachment(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertRedirect();
        $this->assertGuest();
    }

    /**
     * The route uses Filament's own Authenticate middleware specifically so
     * an unauthenticated request redirects to the real panel login page
     * (Filament::getLoginUrl()) rather than throwing (Laravel's default
     * 'auth' alias would call route('login'), which does not exist in this
     * app - only the panel-scoped filament.admin.auth.login does).
     */
    public function test_guest_redirects_through_the_correct_filament_login_route(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertRedirect('http://localhost/admin/login');
    }

    /**
     * Filament's Authenticate middleware runs canAccessPanel() on every
     * request (see User::canAccessPanel()), which denies a deactivated
     * account before the controller - and therefore before Gate::authorize
     * - is ever reached, regardless of what permissions the account holds.
     */
    public function test_inactive_user_cannot_access_attachment_routes(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $user = $this->userWithPermissions(['general_expenses.view']);
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user);

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertForbidden();
    }

    /**
     * Same as above for a soft-deleted acting user - actingAs() bypasses
     * the login form, so this exercises canAccessPanel()'s trashed() check
     * specifically, not "can this account even log in".
     */
    public function test_soft_deleted_user_cannot_access_attachment_routes(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $user = $this->userWithPermissions(['general_expenses.view']);
        $user->delete();

        $this->actingAs($user);

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertForbidden();
    }

    // =========================================================
    // 2 & 3. Authorized user can view inline / download
    // =========================================================

    public function test_authorized_user_can_view_inline(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertOk();
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_authorized_user_can_download(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'download'));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    // =========================================================
    // 4. User without the correct parent permission receives 403
    // =========================================================

    public function test_user_without_parent_permission_is_forbidden(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs(User::factory()->create());

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertForbidden();
    }

    // =========================================================
    // 5. Permission for another financial module does not grant access
    // =========================================================

    public function test_permission_for_another_module_does_not_grant_access(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        // Real permission, but for a different module than the attachment's parent.
        $this->actingAs($this->userWithPermissions(['execution_payments.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertForbidden();
    }

    // =========================================================
    // 6. Super Admin access works
    // =========================================================

    public function test_super_admin_can_access_any_attachment(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->superAdmin());

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertOk();
    }

    // =========================================================
    // 7. Soft-deleted Attachment returns 404
    // =========================================================

    public function test_soft_deleted_attachment_returns_404(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);
        $attachment->delete();

        // Even a fully-authorized Super Admin must not reach a trashed row.
        $this->actingAs($this->superAdmin());

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertNotFound();
    }

    // =========================================================
    // 8. Soft-deleted parent is unavailable
    // =========================================================

    public function test_soft_deleted_parent_is_unavailable(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);
        $expense->delete();

        // Even an otherwise-fully-authorized user must not be able to tell
        // "trashed" apart from "never existed" - the controller checks
        // trashed() before ever calling Gate::authorize(), so this is a 404,
        // not a 403.
        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertNotFound();
    }

    public function test_missing_parent_is_404(): void
    {
        // An attachable_id that has never existed at all.
        $attachment = $this->makeAttachment(GeneralExpense::class, 999999, Attachment::DISK_ATTACHMENTS, 'general-expenses/orphan.jpg');

        $this->actingAs($this->superAdmin());

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertNotFound();
    }

    // =========================================================
    // 9. Missing physical file returns 404
    // =========================================================

    public function test_missing_physical_file_returns_404(): void
    {
        $expense = $this->makeGeneralExpense();

        // Attachment row exists, but no file was ever put on the fake disk.
        $attachment = $this->makeAttachment(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS, 'general-expenses/missing.jpg');

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertNotFound();
    }

    // =========================================================
    // 10. Unknown disk value is rejected safely
    // =========================================================

    public function test_unknown_disk_value_is_rejected_safely(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->makeAttachment(GeneralExpense::class, $expense->id, 's3', 'general-expenses/gen_1.jpg');

        $this->actingAs($this->superAdmin());

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertNotFound();
    }

    // =========================================================
    // 11. Unsupported attachable type is denied
    // =========================================================

    public function test_unsupported_attachable_type_is_denied(): void
    {
        // A real, existing class - just not in the controller's allowlist.
        $attachment = $this->makeAttachment(User::class, 1, Attachment::DISK_ATTACHMENTS, 'anywhere/file.jpg');

        $this->actingAs($this->superAdmin());

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertNotFound();
    }

    // =========================================================
    // 12. Crafted / non-numeric / traversal-style route values return 404
    // =========================================================

    public function test_non_numeric_attachment_identifier_returns_404(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/attachments/abc/view')->assertNotFound();
        $this->get('/attachments/1e5/view')->assertNotFound();
        $this->get('/attachments/../../etc/passwd/view')->assertNotFound();
        $this->get('/attachments/1/../../../etc/passwd')->assertNotFound();
    }

    public function test_invalid_mode_returns_404(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->superAdmin());

        $this->get("/attachments/{$attachment->id}/delete")->assertNotFound();
    }

    // =========================================================
    // 13 & 14 & 15. Filename and Content-Disposition correctness
    // =========================================================

    public function test_inline_content_disposition_contains_the_stored_filename(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS, fileName: 'gen_42_20260701_100.jpg');

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringContainsString('gen_42_20260701_100.jpg', $disposition);
    }

    public function test_download_content_disposition_contains_the_stored_filename(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS, fileName: 'gen_42_20260701_100.jpg');

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'download'));

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('gen_42_20260701_100.jpg', $disposition);
    }

    // =========================================================
    // 16. Safe MIME type is returned
    // =========================================================

    public function test_response_has_a_content_type_header(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $contentType = $response->headers->get('Content-Type');
        $this->assertNotEmpty($contentType);
    }

    // =========================================================
    // 17. Private cache/security headers are present
    // =========================================================

    public function test_response_carries_private_cache_and_security_headers(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        // Symfony's ResponseHeaderBag recomposes Cache-Control directives in
        // its own canonical order, so assert the individual directives
        // rather than a fixed string.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $response->assertHeader('Pragma', 'no-cache');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // =========================================================
    // 18. The controller never needs a request-supplied file path
    // =========================================================

    public function test_route_only_accepts_a_numeric_attachment_id_and_a_view_or_download_mode(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())->first(
            fn ($r) => $r->getName() === 'attachments.show'
        );

        $this->assertNotNull($route);
        $this->assertSame(['attachment', 'mode'], $route->parameterNames());
        $this->assertSame('[0-9]+', $route->wheres['attachment'] ?? null);
    }

    // =========================================================
    // 19. Public and private disk rows can both be securely served
    // =========================================================

    public function test_legacy_public_disk_row_is_served_through_the_same_authenticated_route(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_PUBLIC);

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertOk();
    }

    public function test_new_private_disk_row_is_served_through_the_same_authenticated_route(): void
    {
        $expense = $this->makeGeneralExpense();
        $attachment = $this->putAndAttach(GeneralExpense::class, $expense->id, Attachment::DISK_ATTACHMENTS);

        $this->actingAs($this->userWithPermissions(['general_expenses.view']));

        $response = $this->get($this->url($attachment, 'view'));

        $response->assertOk();
    }

    // =========================================================
    // Coverage across all 5 supported financial parent models
    // =========================================================

    public function test_every_supported_financial_parent_model_authorizes_through_its_own_policy(): void
    {
        $cases = [
            ['general_expenses', fn () => $this->makeGeneralExpense()],
            ['general_exchanges', fn () => $this->makeGeneralExchange()],
            ['project_cost_budgets_payments', fn () => $this->makeProjectCostBudget()],
            ['project_cost_receipts', fn () => $this->makeProjectCostReceipt()],
            ['execution_payments', fn () => $this->makeProjectCostBudgetsPayment()],
        ];

        foreach ($cases as [$module, $factory]) {
            $parent = $factory();
            $attachment = $this->putAndAttach(get_class($parent), $parent->id, Attachment::DISK_ATTACHMENTS);

            $this->actingAs(User::factory()->create());
            $this->get($this->url($attachment, 'view'))->assertForbidden();

            $this->actingAs($this->userWithPermissions(["{$module}.view"]));
            $this->get($this->url($attachment, 'view'))->assertOk();
        }
    }

    // =========================================================
    // helpers
    // =========================================================

    private function url(Attachment $attachment, string $mode): string
    {
        return "/attachments/{$attachment->id}/{$mode}";
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
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

    private function makeGeneralExpense(): GeneralExpense
    {
        return GeneralExpense::create(['amount' => 100, 'date' => '2026-07-01']);
    }

    private function makeGeneralExchange(): GeneralExchange
    {
        return GeneralExchange::create(['original_amount' => 100, 'final_amount' => 100, 'date' => '2026-07-01']);
    }

    private function makeProjectCostBudgetsPayment(): ProjectCostBudgetsPayment
    {
        return ProjectCostBudgetsPayment::create(['amount' => 50, 'date' => '2026-07-01']);
    }

    private function makeProjectCostBudget(): ProjectCostBudget
    {
        $cost = $this->makeProjectCost('ميزانية', 'PCB');

        return ProjectCostBudget::create(['project_cost_id' => $cost->id]);
    }

    private function makeProjectCostReceipt(): ProjectCostReceipt
    {
        $cost = $this->makeProjectCost('إيصال', 'PCR');

        return ProjectCostReceipt::create([
            'project_cost_id' => $cost->id,
            'amount' => 100,
            'currency_id' => $cost->currency_id,
            'date' => '2026-07-01',
        ]);
    }

    /**
     * A distinct $codePrefix per call sidesteps ProjectSuper's own
     * auto-incrementing code generation (boot()'s creating() hook counts
     * existing rows per code_prefix) so unrelated test helpers never
     * collide on the same generated code.
     */
    private function makeProjectCost(string $label, string $codePrefix): ProjectCost
    {
        $super = ProjectSuper::create(['name' => "مشروع رئيسي {$label}", 'code_prefix' => $codePrefix]);
        $status = ProjectStatus::create(['name' => "نشط {$label}"]);
        $project = Project::create([
            'name' => "مشروع {$label}",
            'project_super_id' => $super->id,
            'project_status_id' => $status->id,
        ]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD', 'symbol' => 'USD']);

        return ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $currency->id]);
    }

    /**
     * Create an Attachment row and put a matching fake file on the given
     * disk, so exists()/mimeType() resolve against real (fake) bytes.
     */
    private function putAndAttach(
        string $attachableType,
        int $attachableId,
        string $disk,
        string $fileName = 'gen_1_20260701_100.jpg',
    ): Attachment {
        $path = 'general-expenses/'.$fileName;

        Storage::disk($disk)->put($path, 'fake-file-bytes');

        return $this->makeAttachment($attachableType, $attachableId, $disk, $path, $fileName);
    }

    private function makeAttachment(
        string $attachableType,
        int $attachableId,
        string $disk,
        string $path,
        ?string $fileName = null,
    ): Attachment {
        return Attachment::create([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'file_name' => $fileName ?? basename($path),
            'file_path' => $path,
            'file_type' => 'image/jpeg',
            'file_size' => 16,
            'disk' => $disk,
        ]);
    }
}
