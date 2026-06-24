<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * Integration tests for Stride role-based admin access.
 *
 * Tests that coordinator, supervisor, and subscriber roles have correct
 * access levels to the admin REST API endpoints.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminRoles
 */
class AdminRolesIntegrationTest extends IntegrationTestCase
{
    private static ?int $coordinatorId = null;
    private static ?int $supervisorId = null;
    private static ?int $subscriberId = null;
    private static ?int $adminId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Force-register admin REST API routes.
        // AdminDashboardService has admin_only=true, so AdminAPIController
        // isn't instantiated in CLI/test context. We create it manually
        // and call registerRoutes() directly (rest_api_init already fired).
        $controller = new \Stride\Admin\AdminAPIController(
            new \Stride\Modules\Attendance\AttendanceRepository(),
            new \Stride\Modules\Edition\EditionRepository(),
            new \Stride\Modules\Edition\SessionRepository(),
            new \Stride\Modules\Enrollment\RegistrationRepository(),
        );
        $controller->registerRoutes();

        $ts = time();

        // Create admin
        self::$adminId = wp_create_user("role_admin_{$ts}", 'testpass123', "role_admin_{$ts}@test.local");
        $admin = get_user_by('ID', self::$adminId);
        $admin->set_role('administrator');

        // Create coordinator
        self::$coordinatorId = wp_create_user("role_coord_{$ts}", 'testpass123', "role_coord_{$ts}@test.local");
        $coord = get_user_by('ID', self::$coordinatorId);
        $coord->set_role('stride_coordinator');

        // Create supervisor
        self::$supervisorId = wp_create_user("role_super_{$ts}", 'testpass123', "role_super_{$ts}@test.local");
        $super = get_user_by('ID', self::$supervisorId);
        $super->set_role('stride_supervisor');

        // Create subscriber
        self::$subscriberId = wp_create_user("role_sub_{$ts}", 'testpass123', "role_sub_{$ts}@test.local");
        $sub = get_user_by('ID', self::$subscriberId);
        $sub->set_role('subscriber');
    }

    public static function tearDownAfterClass(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([self::$adminId, self::$coordinatorId, self::$supervisorId, self::$subscriberId] as $id) {
            if ($id) {
                wp_delete_user($id);
            }
        }
        parent::tearDownAfterClass();
    }

    // =========================================================================
    // ADMINISTRATOR — Full access
    // =========================================================================

    /** @test */
    public function adminCanAccessGetEndpoints(): void
    {
        $this->actingAs(self::$adminId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function adminCanAccessPostEndpoints(): void
    {
        $this->actingAs(self::$adminId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        // May return error for invalid session, but NOT 403
        $this->assertNotEquals(403, $response->get_status());
    }

    // =========================================================================
    // COORDINATOR — Full Stride access
    // =========================================================================

    /** @test */
    public function coordinatorCanAccessGetEndpoints(): void
    {
        $this->actingAs(self::$coordinatorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function coordinatorCanAccessEditions(): void
    {
        $this->actingAs(self::$coordinatorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function coordinatorCanAccessPostEndpoints(): void
    {
        $this->actingAs(self::$coordinatorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        // May return error for invalid session, but NOT 403
        $this->assertNotEquals(403, $response->get_status());
    }

    /** @test */
    public function coordinatorCannotAccessSettings(): void
    {
        $this->actingAs(self::$coordinatorId);
        $this->assertFalse(current_user_can('manage_options'));
    }

    // =========================================================================
    // SUPERVISOR — Read-only access
    // =========================================================================

    /** @test */
    public function supervisorCanAccessGetEndpoints(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function supervisorCanAccessEditions(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function supervisorCanAccessQuotes(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/quotes');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function supervisorCannotMarkAttendance(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /** @test */
    public function supervisorCannotApproveRegistration(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/approve-registration');
        $request->set_param('registration_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /** @test */
    public function supervisorCannotApprovePostCourse(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/approve-post-course');
        $request->set_param('registration_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    // =========================================================================
    // SUBSCRIBER — No admin access
    // =========================================================================

    /** @test */
    public function subscriberCannotAccessGetEndpoints(): void
    {
        $this->actingAs(self::$subscriberId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /** @test */
    public function subscriberCannotAccessPostEndpoints(): void
    {
        $this->actingAs(self::$subscriberId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    // =========================================================================
    // ROLE CAPABILITIES VERIFICATION
    // =========================================================================

    /** @test */
    public function administratorHasStrideCaps(): void
    {
        $role = get_role('administrator');
        $this->assertNotNull($role, 'Administrator role should exist');
        $this->assertTrue($role->has_cap('stride_manage'));
        $this->assertTrue($role->has_cap('stride_view'));
    }

    /** @test */
    public function coordinatorHasExpectedCaps(): void
    {
        $role = get_role('stride_coordinator');
        $this->assertNotNull($role, 'stride_coordinator role should exist');
        $this->assertTrue($role->has_cap('stride_manage'));
        $this->assertTrue($role->has_cap('stride_view'));
        $this->assertTrue($role->has_cap('edit_posts'));
        $this->assertTrue($role->has_cap('edit_others_posts'));
        $this->assertTrue($role->has_cap('publish_posts'));
        $this->assertTrue($role->has_cap('delete_posts'));
        $this->assertTrue($role->has_cap('upload_files'));
        $this->assertTrue($role->has_cap('read'));
    }

    /** @test */
    public function supervisorHasOnlyReadCaps(): void
    {
        $role = get_role('stride_supervisor');
        $this->assertNotNull($role, 'stride_supervisor role should exist');
        $this->assertTrue($role->has_cap('stride_view'));
        $this->assertTrue($role->has_cap('read'));
        $this->assertFalse($role->has_cap('stride_manage'));
        $this->assertFalse($role->has_cap('edit_posts'));
        $this->assertFalse($role->has_cap('edit_others_posts'));
    }

    // =========================================================================
    // IMPERSONATE — entry-point authority must be manage_options (S2, threat #2)
    // =========================================================================

    /**
     * Impersonation capability drift (threat-model attack #2).
     *
     * A stride_coordinator HAS stride_manage but NOT manage_options. Before the
     * fix the route gate (canManageAdmin → stride_manage) let them THROUGH and
     * only the body's validateTarget stopped them. The entry-point authority
     * must match the real authority: the coordinator is now DENIED at the route
     * permission_callback (403), not merely inside the handler.
     *
     * @test
     */
    public function coordinatorDeniedAtImpersonateRouteGate(): void
    {
        $this->actingAs(self::$coordinatorId);
        $this->assertTrue(current_user_can('stride_manage'));
        $this->assertFalse(current_user_can('manage_options'));

        $request = new WP_REST_Request('POST', '/stride/v1/admin/users/' . self::$subscriberId . '/impersonate');
        $response = rest_do_request($request);

        $this->assertEquals(
            403,
            $response->get_status(),
            'stride_manage-only coordinator must be denied at the impersonate route gate',
        );
    }

    /**
     * The admin (manage_options) must still PASS the route gate — denial at the
     * gate proves authority; a non-403 (the handler may 4xx for other reasons)
     * proves the gate itself let the full admin through.
     *
     * @test
     */
    public function adminPassesImpersonateRouteGate(): void
    {
        $this->actingAs(self::$adminId);
        $this->assertTrue(current_user_can('manage_options'));

        $request = new WP_REST_Request('POST', '/stride/v1/admin/users/' . self::$subscriberId . '/impersonate');
        $response = rest_do_request($request);

        $this->assertNotEquals(
            403,
            $response->get_status(),
            'manage_options admin must pass the impersonate route gate',
        );
    }

    // =========================================================================
    // REVEAL — per-user PII reveal rate-limit (N1, threat #3)
    // =========================================================================

    /**
     * PII exfil by rate (threat-model attack #3).
     *
     * revealSensitiveField is manage-gated + audited but, before the fix, not
     * rate-limited. N allowed reveals within the window succeed; the (N+1)th
     * returns a 429 WP_Error. The counter is keyed to the CURRENT user, so a
     * different caller's window is independent (no shared throttle).
     *
     * @test
     */
    public function revealSensitiveFieldRateLimitsPerUser(): void
    {
        // Shrink the limit to keep the test fast and deterministic.
        $limit = 3;
        $filter = static fn () => $limit;
        add_filter('stride_pii_reveal_rate_limit', $filter);

        // Clean any leftover counters from a prior run within the window.
        delete_transient('stride_pii_reveal_rl_' . self::$coordinatorId);
        delete_transient('stride_pii_reveal_rl_' . self::$adminId);

        try {
            $this->actingAs(self::$coordinatorId);
            update_user_meta(self::$subscriberId, 'phone', '0470000000');

            // N allowed reveals succeed (200).
            for ($i = 0; $i < $limit; $i++) {
                $request = new WP_REST_Request('GET', '/stride/v1/admin/users/' . self::$subscriberId . '/reveal');
                $request->set_param('field', 'phone');
                $response = rest_do_request($request);
                $this->assertEquals(200, $response->get_status(), "reveal #{$i} within limit should succeed");
            }

            // The (N+1)th reveal is throttled.
            $request = new WP_REST_Request('GET', '/stride/v1/admin/users/' . self::$subscriberId . '/reveal');
            $request->set_param('field', 'phone');
            $overLimit = rest_do_request($request);
            $this->assertEquals(429, $overLimit->get_status(), 'the (N+1)th reveal must be rate-limited');
            $this->assertSame('rate_limited', $overLimit->as_error()->get_error_code());

            // A DIFFERENT user's counter is independent — the admin, who has not
            // revealed at all, is NOT throttled by the coordinator's window.
            $this->actingAs(self::$adminId);
            $adminRequest = new WP_REST_Request('GET', '/stride/v1/admin/users/' . self::$subscriberId . '/reveal');
            $adminRequest->set_param('field', 'phone');
            $adminResponse = rest_do_request($adminRequest);
            $this->assertEquals(200, $adminResponse->get_status(), 'a different user\'s reveal counter must be independent');
        } finally {
            remove_filter('stride_pii_reveal_rate_limit', $filter);
            delete_transient('stride_pii_reveal_rl_' . self::$coordinatorId);
            delete_transient('stride_pii_reveal_rl_' . self::$adminId);
            delete_user_meta(self::$subscriberId, 'phone');
        }
    }
}
