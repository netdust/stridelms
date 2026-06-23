<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Admin\AdminAPIController;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

/**
 * Unit tests for AdminAPIController permission methods.
 *
 * Run: ddev exec vendor/bin/phpunit --filter AdminAPIController --testsuite Unit
 */
class AdminAPIControllerTest extends TestCase
{
    private AdminAPIController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset capability stubs to prevent leaking between tests
        global $current_user_caps;
        $current_user_caps = null;

        $this->controller = new AdminAPIController(
            $this->createMock(AttendanceRepository::class),
            $this->createMock(EditionRepository::class),
            $this->createMock(SessionRepository::class),
            $this->createMock(RegistrationRepository::class),
        );
    }

    protected function tearDown(): void
    {
        global $current_user_caps;
        $current_user_caps = null;

        parent::tearDown();
    }

    // =========================================================================
    // canViewAdmin
    // =========================================================================

    /**
     * @test
     */
    public function canViewAdminReturnsTrueWithStrideView(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_view' => true];

        $this->assertTrue($this->controller->canViewAdmin());
    }

    /**
     * @test
     */
    public function canViewAdminReturnsFalseWithoutStrideView(): void
    {
        global $current_user_caps;
        $current_user_caps = ['read' => true];

        $this->assertFalse($this->controller->canViewAdmin());
    }

    // =========================================================================
    // canManageAdmin
    // =========================================================================

    /**
     * @test
     */
    public function canManageAdminReturnsTrueWithStrideManage(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => true, 'stride_view' => true];

        $this->assertTrue($this->controller->canManageAdmin());
    }

    /**
     * @test
     */
    public function canManageAdminReturnsFalseWithOnlyStrideView(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_view' => true];

        $this->assertFalse($this->controller->canManageAdmin());
    }

    /**
     * @test
     */
    public function canManageAdminReturnsFalseWithNoCaps(): void
    {
        global $current_user_caps;
        $current_user_caps = [];

        $this->assertFalse($this->controller->canManageAdmin());
    }
}
