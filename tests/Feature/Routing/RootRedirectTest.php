<?php

namespace Tests\Feature\Routing;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Proves the application root redirects to the Filament Admin panel's own
 * login screen, and that it does so through the named route
 * (filament.admin.auth.login) rather than a hard-coded path - so the
 * redirect keeps whatever scheme, host, port and base path the request
 * arrived on. That last property is what makes the same route work both
 * for root-domain hosting and for a subdirectory deployment served from
 * /oms/public.
 *
 * URL::forceRootUrl('http://localhost') in setUp() follows the existing
 * HTTP test convention in this suite (see AttachmentAccessTest): this
 * app's APP_URL carries a subdirectory base path, and Laravel's console
 * bootstrap builds test request URLs from it, so without the override even
 * `$this->get('/')` would be dispatched at '/oms/public' and 404.
 *
 * No database access is needed: the root route only issues a redirect, and
 * the panel login page renders with the array session/cache drivers the
 * test environment already configures (see phpunit.xml).
 */
class RootRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
    }

    // =========================================================
    // 1. Root redirects to the Filament Admin login route
    // =========================================================

    public function test_root_redirects_to_the_filament_admin_login_route(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('filament.admin.auth.login'));
        $response->assertRedirect('http://localhost/admin/login');
    }

    // =========================================================
    // 2. The redirect target follows the request's own base path
    //
    // Simulates the local deployment: the app served from under a
    // /oms/public base path, which Symfony's Request derives from the
    // SCRIPT_NAME/SCRIPT_FILENAME pair below. The root URL is deliberately
    // NOT forced here - the point is that the redirect is derived from the
    // incoming request itself, not from APP_URL or a literal string.
    //
    // The server variables name the base path without a trailing
    // '/index.php' because Laravel's own test helper strips the trailing
    // slash off the requested URL (prepareUrlForRequest), which would stop
    // Symfony from recognising '/oms/public/index.php' as the prefix of a
    // '/oms/public' request URI. The mechanism under test - request base
    // path -> Request::root() -> route() - is exercised either way.
    // =========================================================

    public function test_root_redirect_preserves_a_subdirectory_base_path(): void
    {
        URL::forceRootUrl(null);

        $response = $this->withServerVariables([
            'SCRIPT_FILENAME' => '/var/www/oms/public',
            'SCRIPT_NAME' => '/oms/public',
            'PHP_SELF' => '/oms/public',
        ])->get('http://172.16.0.100/oms/public');

        $response->assertRedirect('http://172.16.0.100/oms/public/admin/login');
    }

    // =========================================================
    // 3. The login route itself still responds normally
    // =========================================================

    public function test_the_admin_login_route_still_responds_to_a_guest(): void
    {
        $response = $this->get(route('filament.admin.auth.login'));

        $response->assertOk();
    }
}
