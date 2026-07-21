<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Attachments\AttachmentResource;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * OMS Task 6A correction #1: the standalone AttachmentResource's own
 * FileUpload resolves to the pre-existing 'local' disk, which sits outside
 * AttachmentController's authorization flow entirely (see the Task 6A
 * audit). Rather than delete the resource or its attachments.*
 * permissions, every mutation ability is hard-overridden to false and only
 * index/view routes are registered - proves that holds even for a real
 * Super Admin (Gate::before bypasses the underlying AttachmentPolicy for
 * Super Admin, so a Policy-only denial would not have been enough; the
 * hard override on the Resource itself is what actually closes this).
 */
class AttachmentResourceHardeningTest extends TestCase
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
    }

    // =========================================================
    // Structural canX() overrides - true regardless of any Gate/Policy
    // =========================================================

    public function test_every_mutation_ability_is_hard_denied(): void
    {
        $attachment = $this->makeAttachment();

        $this->assertFalse(AttachmentResource::canCreate());
        $this->assertFalse(AttachmentResource::canEdit($attachment));
        $this->assertFalse(AttachmentResource::canDelete($attachment));
        $this->assertFalse(AttachmentResource::canDeleteAny());
        $this->assertFalse(AttachmentResource::canForceDelete($attachment));
        $this->assertFalse(AttachmentResource::canForceDeleteAny());
        $this->assertFalse(AttachmentResource::canRestore($attachment));
        $this->assertFalse(AttachmentResource::canRestoreAny());
    }

    public function test_only_index_and_view_routes_are_registered(): void
    {
        $this->assertSame(['index', 'view'], array_keys(AttachmentResource::getPages()));
    }

    public function test_navigation_is_hidden(): void
    {
        $this->assertFalse(AttachmentResource::shouldRegisterNavigation());
    }

    // =========================================================
    // HTTP-level proof, including for a real Super Admin
    // =========================================================

    public function test_super_admin_cannot_reach_the_create_route(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/admin/attachments/create')->assertNotFound();
    }

    public function test_super_admin_cannot_reach_the_edit_route(): void
    {
        $attachment = $this->makeAttachment();

        $this->actingAsSuperAdmin();

        $this->get("/admin/attachments/{$attachment->id}/edit")->assertNotFound();
    }

    public function test_ordinary_user_with_full_attachments_permissions_cannot_reach_create_or_edit(): void
    {
        $attachment = $this->makeAttachment();

        // Every attachments.* permission that exists, granted deliberately -
        // proves the closure is structural, not "just missing a permission".
        $this->actingAs($this->userWithPermissions([
            'attachments.view_any', 'attachments.view', 'attachments.create',
            'attachments.update', 'attachments.delete', 'attachments.restore',
        ]));

        $this->get('/admin/attachments/create')->assertNotFound();
        $this->get("/admin/attachments/{$attachment->id}/edit")->assertNotFound();
    }

    // =========================================================
    // View access is preserved - only mutation is closed
    // =========================================================

    public function test_list_and_view_remain_reachable_with_the_existing_permissions(): void
    {
        $attachment = $this->makeAttachment();

        $this->actingAs($this->userWithPermissions(['attachments.view_any', 'attachments.view']));

        $this->get('/admin/attachments')->assertOk();
        $this->get("/admin/attachments/{$attachment->id}")->assertOk();
    }

    public function test_list_is_blocked_without_the_permission(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/attachments')->assertForbidden();
    }

    private function makeAttachment(): Attachment
    {
        return Attachment::create([
            'attachable_type' => 'App\\Models\\Project',
            'attachable_id' => 1,
            'file_name' => 'sample.jpg',
            'file_path' => 'sample.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 16,
            'disk' => Attachment::DISK_PUBLIC,
        ]);
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

    private function actingAsSuperAdmin(): void
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);
    }
}
