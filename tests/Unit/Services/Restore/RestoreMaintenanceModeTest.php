<?php

namespace Tests\Unit\Services\Restore;

use App\Services\Restore\Exceptions\RestoreMaintenanceModeException;
use App\Services\Restore\RestoreMaintenanceMode;
use PHPUnit\Framework\TestCase;
use Tests\Support\Restore\FakeMaintenanceModeController;

/**
 * OMS Task 7C.7 — RestoreMaintenanceMode never writes a real maintenance
 * flag file in this suite (FakeMaintenanceModeController stands in for both
 * the Artisan down/up calls and the "is it currently down" inspection).
 */
class RestoreMaintenanceModeTest extends TestCase
{
    public function test_is_active_reflects_the_inspector(): void
    {
        $controller = new FakeMaintenanceModeController(active: true);
        $mode = new RestoreMaintenanceMode($controller, $controller);

        $this->assertTrue($mode->isActive());
    }

    public function test_enter_calls_down_with_a_generic_arabic_message(): void
    {
        $controller = new FakeMaintenanceModeController(active: false);
        $mode = new RestoreMaintenanceMode($controller, $controller);

        $mode->enter();

        $this->assertTrue($mode->isActive());
        $this->assertCount(1, $controller->calls);
        $this->assertSame('down', $controller->calls[0]['command']);
        $this->assertArrayHasKey('--message', $controller->calls[0]['parameters']);
        $this->assertNotSame('', $controller->calls[0]['parameters']['--message']);
    }

    public function test_enter_throws_when_the_artisan_command_fails(): void
    {
        $controller = new FakeMaintenanceModeController(active: false, failDown: true);
        $mode = new RestoreMaintenanceMode($controller, $controller);

        $this->expectException(RestoreMaintenanceModeException::class);

        try {
            $mode->enter();
        } catch (RestoreMaintenanceModeException $e) {
            $this->assertSame('maintenance_enter_failed', $e->reasonCode);

            throw $e;
        }
    }

    public function test_leave_calls_up_and_clears_active_state(): void
    {
        $controller = new FakeMaintenanceModeController(active: true);
        $mode = new RestoreMaintenanceMode($controller, $controller);

        $mode->leave();

        $this->assertFalse($mode->isActive());
        $this->assertSame(['up'], $controller->commandCalls());
    }

    public function test_leave_throws_when_the_artisan_command_fails(): void
    {
        $controller = new FakeMaintenanceModeController(active: true, failUp: true);
        $mode = new RestoreMaintenanceMode($controller, $controller);

        $this->expectException(RestoreMaintenanceModeException::class);

        try {
            $mode->leave();
        } catch (RestoreMaintenanceModeException $e) {
            $this->assertSame('maintenance_leave_failed', $e->reasonCode);

            throw $e;
        }
    }
}
