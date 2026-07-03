<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use PHPUnit\Framework\MockObject\MockObject;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAdminController;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;
use ReflectionMethod;
use StrideJsonResponse;

/**
 * AA-1 authorization boundary tests for EditionAdminController AJAX handlers.
 *
 * Two contracts (Cluster 2B):
 *  - 2B.1: the shared verifyAjaxNonce() cap is stride_manage, NOT edit_posts —
 *    an author-level (edit_posts-only) actor is DENIED (403); a stride_manage
 *    actor is allowed.
 *  - 2B.2: each mutating handler enforces per-edition object scope — a
 *    stride_manage actor is DENIED (403) on an edition they cannot edit_post,
 *    and allowed on one they can.
 *
 * The denial path is the assertion (Tier A).
 *
 * Run: ddev exec vendor/bin/phpunit --filter EditionAdminControllerAuthz --testsuite Unit
 */
class EditionAdminControllerAuthzTest extends TestCase
{
    private EditionAdminController $controller;
    /** @var EditionService&MockObject */
    private $editionService;
    /** @var SessionService&MockObject */
    private $sessionService;
    /** @var RegistrationRepository&MockObject */
    private $registrationRepo;

    protected function setUp(): void
    {
        parent::setUp();

        global $current_user_caps, $_test_check_ajax_referer_result;
        $current_user_caps = null;
        $_test_check_ajax_referer_result = true;

        $this->editionService = $this->createMock(EditionService::class);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->registrationRepo = $this->createMock(RegistrationRepository::class);

        // init() early-returns unless is_admin(); the stub returns true so hook
        // registration runs, but that has no bearing on the guard tests below.
        $this->controller = new EditionAdminController(
            $this->editionService,
            $this->createMock(EditionRepository::class),
            $this->sessionService,
            $this->createMock(SessionRepository::class),
            $this->createMock(AttendanceRepository::class),
        );

        // Route ntdst_get(RegistrationRepository) to our mock (confirm/reject/
        // approve resolve the edition from the registration through it).
        global $_test_container;
        $_test_container[RegistrationRepository::class] = $this->registrationRepo;
    }

    protected function tearDown(): void
    {
        global $current_user_caps, $_test_check_ajax_referer_result;
        $current_user_caps = null;
        $_test_check_ajax_referer_result = null;
        parent::tearDown();
    }

    private function invokeVerifyNonce(): bool
    {
        $method = new ReflectionMethod(EditionAdminController::class, 'verifyAjaxNonce');
        $method->setAccessible(true);
        return $method->invoke($this->controller);
    }

    // =========================================================================
    // 2B.1 — verifyAjaxNonce raises the cap to stride_manage
    // =========================================================================

    /**
     * @test
     */
    public function verifyAjaxNonceDeniesEditPostsOnlyActor(): void
    {
        global $current_user_caps;
        // Author-level: has edit_posts but NOT stride_manage.
        $current_user_caps = ['edit_posts' => true, 'stride_manage' => false];

        try {
            $this->invokeVerifyNonce();
            $this->fail('Expected a 403 denial for an edit_posts-only actor.');
        } catch (StrideJsonResponse $e) {
            $this->assertFalse($e->success);
            $this->assertSame(403, $e->status);
        }
    }

    /**
     * @test
     */
    public function verifyAjaxNonceAllowsStrideManageActor(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => true, 'edit_posts' => false];

        $this->assertTrue($this->invokeVerifyNonce());
    }

    // =========================================================================
    // 2B.2 — per-edition object scope (one case per handler class)
    // =========================================================================

    /**
     * Attendance class: ajaxMarkAttendance resolves edition via session.
     *
     * @test
     */
    public function markAttendanceDeniesActorWithoutEditPostOnEdition(): void
    {
        global $current_user_caps;
        // Passes the shared gate (stride_manage) but cannot edit_post edition 99.
        $current_user_caps = ['stride_manage' => true, 'edit_post:99' => false];

        $this->sessionService->method('getSession')
            ->willReturn(['id' => 5, 'edition_id' => 99]);

        $_POST = ['session_id' => 5, 'user_id' => 7, 'status' => 'present', 'nonce' => 'x'];

        try {
            $this->controller->ajaxMarkAttendance();
            $this->fail('Expected a 403 denial: actor cannot edit_post the edition.');
        } catch (StrideJsonResponse $e) {
            $this->assertFalse($e->success, 'Attendance mutation must be denied.');
            $this->assertSame(403, $e->status);
        } finally {
            $_POST = [];
        }
    }

    /**
     * Confirm/reject class: ajaxRejectRegistration resolves edition via the
     * registration row.
     *
     * @test
     */
    public function rejectRegistrationDeniesActorWithoutEditPostOnEdition(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => true, 'edit_post:99' => false];

        $this->registrationRepo->method('find')
            ->willReturn((object) ['id' => 3, 'edition_id' => 99, 'status' => 'pending']);

        $_POST = ['registration_id' => 3, 'nonce' => 'x'];

        try {
            $this->controller->ajaxRejectRegistration();
            $this->fail('Expected a 403 denial: actor cannot edit_post the edition.');
        } catch (StrideJsonResponse $e) {
            $this->assertFalse($e->success, 'Reject mutation must be denied.');
            $this->assertSame(403, $e->status);
        } finally {
            $_POST = [];
        }
    }

    /**
     * Approve class: ajaxApprovePostCourse resolves edition via the
     * registration row.
     *
     * @test
     */
    public function approvePostCourseDeniesActorWithoutEditPostOnEdition(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => true, 'edit_post:99' => false];

        $this->registrationRepo->method('find')
            ->willReturn((object) ['id' => 3, 'edition_id' => 99, 'status' => 'confirmed']);

        $_POST = ['registration_id' => 3, 'nonce' => 'x'];

        try {
            $this->controller->ajaxApprovePostCourse();
            $this->fail('Expected a 403 denial: actor cannot edit_post the edition.');
        } catch (StrideJsonResponse $e) {
            $this->assertFalse($e->success, 'Approve mutation must be denied.');
            $this->assertSame(403, $e->status);
        } finally {
            $_POST = [];
        }
    }

    /**
     * Positive case: a stride_manage actor WHO can edit_post the edition is
     * allowed past the object-scope gate (the guard does not over-deny).
     *
     * @test
     */
    public function markAttendanceAllowsActorWithEditPostOnEdition(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => true, 'edit_post:99' => true];

        $this->sessionService->method('getSession')
            ->willReturn(['id' => 5, 'edition_id' => 99]);

        // Route the AttendanceService the handler resolves via ntdst_get to a
        // mock so the happy path does not fatal past the guard.
        global $_test_container;
        $attendanceService = $this->createMock(\Stride\Modules\Attendance\AttendanceService::class);
        $_test_container[\Stride\Modules\Attendance\AttendanceService::class] = $attendanceService;

        // The handler proceeds to record attendance + return totals; it must NOT
        // raise a 403. Any non-403 terminal response (success, or a downstream
        // 400/404) proves the object-scope gate let the actor through.
        $_POST = ['session_id' => 5, 'user_id' => 7, 'status' => 'present', 'nonce' => 'x'];

        try {
            $this->controller->ajaxMarkAttendance();
            $_POST = [];
            $this->addToAssertionCount(1); // reached the end without terminating
        } catch (StrideJsonResponse $e) {
            $_POST = [];
            $this->assertNotSame(403, $e->status, 'Actor with edit_post must not be denied.');
        }
    }
}
