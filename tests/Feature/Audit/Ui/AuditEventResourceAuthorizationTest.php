<?php

namespace Tests\Feature\Audit\Ui;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Models\User;
use App\Support\Audit\AuditViewAuthorization;
use App\Support\Permissions\PermissionRegistry;

/**
 * OMS Task 9B.7 §3 — access is limited to the REAL Super Admin role only.
 */
class AuditEventResourceAuthorizationTest extends AuditUiTestCase
{
    public function test_real_super_admin_sees_navigation_and_can_open_the_list(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertTrue(AuditEventResource::canViewAny());
        $this->assertTrue(AuditEventResource::canAccess());
        $this->assertTrue(AuditEventResource::shouldRegisterNavigation());

        $this->get(self::LIST_URL)->assertOk();
    }

    public function test_navigation_metadata_places_the_resource_in_the_system_group(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertSame('النظام', AuditEventResource::getNavigationGroup());
        $this->assertSame('سجل التدقيق', AuditEventResource::getNavigationLabel());
        // After المستخدمون(1), الأدوار/سجل المرفقات(2), الصلاحيات(3), النسخ الاحتياطي(4).
        $this->assertSame(5, AuditEventResource::getNavigationSort());
        $this->assertSame('http://localhost'.self::LIST_URL, AuditEventResource::getUrl(panel: 'admin'));
    }

    /**
     * The real rendered sidebar on a page both actors can open. Filament gates
     * navigation registration on canAccess() (see HasNavigation::
     * registerNavigationItems()), so these two assert the end-user-visible
     * effect rather than the method that produces the item.
     *
     * Deliberately two separate test methods: Filament's Panel object
     * ACCUMULATES navigation items on the container-resolved singleton, and
     * within one test method the application is not rebuilt between requests
     * — so a second request in the same method would still see the item the
     * first one registered, which is a test artefact, not real behaviour.
     */
    public function test_the_sidebar_shows_the_audit_log_to_a_real_super_admin(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/admin')->assertOk()->assertSee('سجل التدقيق');
    }

    public function test_the_sidebar_hides_the_audit_log_from_a_fully_permissioned_non_super_admin(): void
    {
        $this->actingAs($this->privilegedNonSuperAdmin());

        $this->get('/admin')->assertOk()->assertDontSee('سجل التدقيق');
    }

    public function test_real_super_admin_can_view_an_event(): void
    {
        $event = $this->makeEvent();

        $this->actingAs($this->superAdmin());

        $this->get($this->viewUrl($event))->assertOk();
    }

    public function test_navigation_is_hidden_from_a_fully_permissioned_non_super_admin(): void
    {
        $this->actingAs($this->privilegedNonSuperAdmin());

        // canAccess() is what Filament consults before registering the
        // navigation item — see HasNavigation::getNavigationItems().
        $this->assertFalse(AuditEventResource::canViewAny());
        $this->assertFalse(AuditEventResource::canAccess());
    }

    public function test_navigation_is_hidden_from_a_plain_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(AuditEventResource::canAccess());
    }

    public function test_non_super_admin_direct_list_request_is_forbidden(): void
    {
        $this->actingAs($this->privilegedNonSuperAdmin());

        $this->get(self::LIST_URL)->assertForbidden();
    }

    public function test_non_super_admin_direct_view_request_is_forbidden(): void
    {
        $event = $this->makeEvent();

        $this->actingAs($this->privilegedNonSuperAdmin());

        $this->get($this->viewUrl($event))->assertForbidden();
    }

    public function test_plain_user_direct_list_and_view_requests_are_forbidden(): void
    {
        $event = $this->makeEvent();

        $this->actingAs(User::factory()->create());

        $this->get(self::LIST_URL)->assertForbidden();
        $this->get($this->viewUrl($event))->assertForbidden();
    }

    public function test_unauthenticated_list_and_view_requests_redirect_to_login(): void
    {
        $event = $this->makeEvent();

        $this->get(self::LIST_URL)->assertRedirect('/admin/login');
        $this->get($this->viewUrl($event))->assertRedirect('/admin/login');
    }

    /**
     * The check is a plain role comparison — it must never resolve through the
     * Gate, whose Super-Admin `before` bypass would otherwise make it true for
     * the very actor the resource is restricted to, and whose ordinary
     * permission resolution could make it true for anyone holding a
     * conveniently-named permission.
     */
    public function test_a_manually_granted_broad_permission_never_opens_the_audit_log(): void
    {
        $user = $this->privilegedNonSuperAdmin();

        $this->actingAs($user);

        $this->assertFalse(AuditViewAuthorization::check($user));
        $this->assertTrue($user->can('backups.view_any'), 'fixture sanity: the actor really does hold broad permissions');
    }

    public function test_no_audit_permission_was_added_to_the_permission_registry(): void
    {
        foreach (PermissionRegistry::names() as $name) {
            $this->assertStringNotContainsString(
                'audit',
                $name,
                'Task 9B.7 must not register any audit.* permission.',
            );
        }
    }

    public function test_super_admin_gate_bypass_is_unchanged(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin);

        // Unchanged behaviour: Gate::before still grants a real Super Admin
        // every ability, including one that does not exist as a permission.
        $this->assertTrue($superAdmin->can('backups.view_any'));
        $this->assertTrue($superAdmin->can('some.ability.that.does.not.exist'));

        $plain = User::factory()->create();
        $this->actingAs($plain);
        $this->assertFalse($plain->can('some.ability.that.does.not.exist'));
    }
}
