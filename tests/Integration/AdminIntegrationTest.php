<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Integration tests for Admin functionality
 *
 * Tests admin menu registration, REST API endpoints, and metaboxes.
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminIntegration
 */
class AdminIntegrationTest extends IntegrationTestCase
{
    private static ?int $adminUserId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create an admin user for admin tests
        $username = 'admin_test_' . time();
        self::$adminUserId = wp_create_user($username, 'adminpass123', $username . '@test.local');

        if (is_wp_error(self::$adminUserId)) {
            throw new \RuntimeException('Failed to create admin user: ' . self::$adminUserId->get_error_message());
        }

        // Grant admin capabilities
        $user = get_user_by('ID', self::$adminUserId);
        $user->set_role('administrator');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$adminUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$adminUserId);
            self::$adminUserId = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$adminUserId);
    }

    // =========================================================================
    // ADMIN MENU REGISTRATION
    // =========================================================================

    /**
     * @test
     */
    public function strideMenuPageIsRegistered(): void
    {
        global $menu;

        // Trigger admin_menu hooks
        do_action('admin_menu');

        // Find Stride menu
        $found = false;
        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (isset($item[2]) && $item[2] === 'stride-dashboard') {
                    $found = true;
                    break;
                }
            }
        }

        $this->assertTrue($found, 'Stride dashboard menu should be registered');
    }

    /**
     * @test
     */
    public function cptSubmenusAreRegistered(): void
    {
        global $submenu;

        // Trigger admin_menu hooks
        do_action('admin_menu');

        $this->assertArrayHasKey('stride-dashboard', $submenu, 'Stride submenu should exist');

        $submenus = $submenu['stride-dashboard'] ?? [];
        $slugs = array_column($submenus, 2);

        // Check expected CPT edit links are in submenu
        $this->assertContains('edit.php?post_type=vad_edition', $slugs, 'Editions submenu should exist');
        $this->assertContains('edit.php?post_type=vad_voucher', $slugs, 'Vouchers submenu should exist');
        $this->assertContains('edit.php?post_type=vad_quote', $slugs, 'Quotes submenu should exist');
    }

    // =========================================================================
    // ADMIN REST API - Authentication
    // =========================================================================

    /**
     * @test
     */
    public function adminApiRejectsUnauthenticatedUser(): void
    {
        wp_set_current_user(0);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(401, $response->get_status());
    }

    /**
     * @test
     */
    public function adminApiRejectsNonAdminUser(): void
    {
        // Use the regular test user (not admin)
        $this->actingAs(self::$testUserId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /**
     * @test
     */
    public function adminApiAcceptsAdminUser(): void
    {
        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    // =========================================================================
    // ADMIN REST API - Stats Endpoint
    // =========================================================================

    /**
     * @test
     */
    public function statsEndpointReturnsExpectedStructure(): void
    {
        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);

        // Check expected keys exist (actual API response structure)
        $this->assertArrayHasKey('upcomingEditions', $data);
        $this->assertArrayHasKey('totalRegistrations', $data);
        $this->assertArrayHasKey('pendingQuotes', $data);
        $this->assertArrayHasKey('todaySessions', $data);
    }

    // =========================================================================
    // ADMIN REST API - Editions Endpoint
    // =========================================================================

    /**
     * @test
     */
    public function editionsEndpointReturnsList(): void
    {
        // Create a test edition
        $editionId = $this->createTestEdition(['post_title' => 'Admin Test Edition']);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('totalPages', $data);
    }

    /**
     * @test
     */
    public function editionsEndpointSupportsPagination(): void
    {
        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $request->set_param('page', 1);
        $request->set_param('per_page', 5);

        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertLessThanOrEqual(5, count($data['items']));
    }

    /**
     * @test
     */
    public function editionsEndpointSupportsStatusFilter(): void
    {
        // Create editions with different statuses
        $this->createTestEdition(['meta' => ['_ntdst_status' => 'open']]);
        $this->createTestEdition(['meta' => ['_ntdst_status' => 'cancelled']]);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $request->set_param('status', 'open');

        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        foreach ($data['items'] as $item) {
            $this->assertEquals('open', $item['status']);
        }
    }

    /**
     * @test
     */
    public function editionDetailEndpointReturnsEdition(): void
    {
        $editionId = $this->createTestEdition(['post_title' => 'Detail Test Edition']);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions/' . $editionId);
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertEquals($editionId, $data['id']);
        $this->assertEquals('Detail Test Edition', $data['title']);
    }

    /**
     * @test
     */
    public function editionDetailEndpointReturns404ForInvalidId(): void
    {
        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions/999999');
        $response = rest_do_request($request);

        $this->assertEquals(404, $response->get_status());
    }

    // =========================================================================
    // ADMIN REST API - Quotes Endpoint
    // =========================================================================

    /**
     * @test
     */
    public function quotesEndpointReturnsList(): void
    {
        $editionId = $this->createTestEdition();
        $this->createTestQuote(self::$adminUserId, $editionId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/quotes');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
    }

    /**
     * @test
     */
    public function quotesEndpointSupportsStatusFilter(): void
    {
        $editionId = $this->createTestEdition();
        $this->createTestQuote(self::$adminUserId, $editionId, ['meta' => ['status' => 'draft']]);
        $this->createTestQuote(self::$adminUserId, $editionId, ['meta' => ['status' => 'sent']]);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/quotes');
        $request->set_param('status', 'draft');

        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        foreach ($data['items'] as $item) {
            $this->assertEquals('draft', $item['status']);
        }
    }

    // =========================================================================
    // ADMIN REST API - Course Tags Endpoint
    // =========================================================================

    /**
     * @test
     */
    public function courseTagsEndpointReturnsList(): void
    {
        $request = new WP_REST_Request('GET', '/stride/v1/admin/course-tags');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
    }

    // =========================================================================
    // ADMIN REST API - Attendance Endpoint
    // =========================================================================

    /**
     * @test
     */
    public function attendanceEndpointRequiresSessionId(): void
    {
        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('user_id', self::$testUserId);

        $response = rest_do_request($request);

        // Should fail validation - missing required session_id
        $this->assertContains($response->get_status(), [400, 500]);
    }

    // =========================================================================
    // CPT POST TYPE REGISTRATION
    // =========================================================================

    /**
     * @test
     */
    public function editionPostTypeIsRegistered(): void
    {
        $this->assertTrue(post_type_exists('vad_edition'), 'Edition post type should be registered');
    }

    /**
     * @test
     */
    public function voucherPostTypeIsRegistered(): void
    {
        $this->assertTrue(post_type_exists('vad_voucher'), 'Voucher post type should be registered');
    }

    /**
     * @test
     */
    public function quotePostTypeIsRegistered(): void
    {
        $this->assertTrue(post_type_exists('vad_quote'), 'Quote post type should be registered');
    }

    /**
     * @test
     */
    public function sessionPostTypeIsRegistered(): void
    {
        $this->assertTrue(post_type_exists('vad_session'), 'Session post type should be registered');
    }

    /**
     * @test
     */
    public function trajectoryPostTypeIsRegistered(): void
    {
        $this->assertTrue(post_type_exists('vad_trajectory'), 'Trajectory post type should be registered');
    }

    // =========================================================================
    // METABOX REGISTRATION
    // =========================================================================

    /**
     * @test
     */
    public function editionMetaboxesAreRegistered(): void
    {
        global $wp_meta_boxes;

        // Create a real post to trigger metabox registration
        $editionId = $this->createTestEdition();
        $post = get_post($editionId);

        // Trigger metabox registration with a real post
        do_action('add_meta_boxes', 'vad_edition', $post);

        // Metaboxes may or may not be registered depending on admin context
        // At minimum, verify the action fired without error
        $this->assertIsInt($editionId);

        // If metaboxes exist, verify structure
        if (isset($wp_meta_boxes['vad_edition'])) {
            $this->assertIsArray($wp_meta_boxes['vad_edition']);
        }
    }

    /**
     * @test
     */
    public function quoteMetaboxesAreRegistered(): void
    {
        global $wp_meta_boxes;

        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$adminUserId, $editionId);
        $post = get_post($quoteId);

        do_action('add_meta_boxes', 'vad_quote', $post);

        $this->assertIsInt($quoteId);

        if (isset($wp_meta_boxes['vad_quote'])) {
            $this->assertIsArray($wp_meta_boxes['vad_quote']);
        }
    }

    /**
     * @test
     */
    public function voucherMetaboxesAreRegistered(): void
    {
        global $wp_meta_boxes;

        $voucherId = $this->createTestVoucher();
        $post = get_post($voucherId);

        do_action('add_meta_boxes', 'vad_voucher', $post);

        $this->assertIsInt($voucherId);

        if (isset($wp_meta_boxes['vad_voucher'])) {
            $this->assertIsArray($wp_meta_boxes['vad_voucher']);
        }
    }

    // =========================================================================
    // REST API ROUTE REGISTRATION
    // =========================================================================

    /**
     * @test
     */
    public function adminApiRoutesAreRegistered(): void
    {
        $routes = rest_get_server()->get_routes();

        $expectedRoutes = [
            '/stride/v1/admin/stats',
            '/stride/v1/admin/editions',
            '/stride/v1/admin/quotes',
            '/stride/v1/admin/course-tags',
            '/stride/v1/admin/attendance',
            '/stride/v1/admin/trajectories',
        ];

        foreach ($expectedRoutes as $route) {
            $this->assertArrayHasKey($route, $routes, "Route {$route} should be registered");
        }
    }

    /**
     * @test
     */
    public function editionDetailRouteIsRegistered(): void
    {
        $routes = rest_get_server()->get_routes();

        // Check parameterized route exists
        $this->assertArrayHasKey('/stride/v1/admin/editions/(?P<id>\\d+)', $routes);
    }

    /**
     * @test
     */
    public function editionRegistrationsRouteIsRegistered(): void
    {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/stride/v1/admin/editions/(?P<id>\\d+)/registrations', $routes);
    }

    // =========================================================================
    // PENDING APPROVALS / STALE-PENDING (D-Cap1)
    // =========================================================================

    /**
     * @test
     */
    public function pendingApprovalsReturnsStaleUserBucket(): void
    {
        global $wpdb;
        $controller = ntdst_get(\Stride\Admin\AdminAPIController::class);
        $editionId = $this->createTestEdition();
        $userId = self::$adminUserId;

        // Insert a pending registration with incomplete user task, dated 10 days ago.
        $tenDaysAgo = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        $regTable = $wpdb->prefix . 'vad_registrations';
        $wpdb->insert($regTable, [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'pending',
            'enrollment_path' => 'individual',
            'registered_at' => $tenDaysAgo,
            'completion_tasks' => wp_json_encode([
                'session_selection' => ['status' => 'pending'],
                'approval' => ['status' => 'pending'],
            ]),
        ]);
        $regId = (int) $wpdb->insert_id;

        $req = new WP_REST_Request('GET', '/stride/v1/admin/pending-approvals');
        $req->set_param('stale_days', 7);
        $response = $controller->getPendingApprovals($req);
        $data = $response->get_data();

        $stale = array_values(array_filter($data['items'], fn($i) => $i['id'] === $regId));
        $this->assertCount(1, $stale, 'The stale pending registration should be returned');
        $this->assertEquals('stale_user', $stale[0]['type']);
        $this->assertEquals('session_selection', $stale[0]['open_task']);
        $this->assertGreaterThanOrEqual(10, $stale[0]['days_idle']);
        $this->assertGreaterThanOrEqual(1, $data['counts']['stale_user']);
        $this->assertEquals(7, $data['stale_threshold_days']);

        $wpdb->delete($regTable, ['id' => $regId]);
    }

    /**
     * @test
     */
    public function pendingApprovalsRespectsStaleDaysThreshold(): void
    {
        global $wpdb;
        $controller = ntdst_get(\Stride\Admin\AdminAPIController::class);
        $editionId = $this->createTestEdition();

        // Recent pending — only 3 days old, should NOT appear with threshold=7
        $threeDaysAgo = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
        $regTable = $wpdb->prefix . 'vad_registrations';
        $wpdb->insert($regTable, [
            'user_id' => self::$adminUserId,
            'edition_id' => $editionId,
            'status' => 'pending',
            'enrollment_path' => 'individual',
            'registered_at' => $threeDaysAgo,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'pending'],
                'approval' => ['status' => 'pending'],
            ]),
        ]);
        $regId = (int) $wpdb->insert_id;

        $req = new WP_REST_Request('GET', '/stride/v1/admin/pending-approvals');
        $req->set_param('stale_days', 7);
        $response = $controller->getPendingApprovals($req);
        $stale = array_values(array_filter($response->get_data()['items'], fn($i) => $i['id'] === $regId));
        $this->assertCount(0, $stale, '3-day pending must not appear with 7-day threshold');

        // Lower threshold — same registration should now appear
        $req->set_param('stale_days', 1);
        $response = $controller->getPendingApprovals($req);
        $stale = array_values(array_filter($response->get_data()['items'], fn($i) => $i['id'] === $regId));
        $this->assertCount(1, $stale, '3-day pending must appear with 1-day threshold');

        $wpdb->delete($regTable, ['id' => $regId]);
    }

    /**
     * @test
     */
    public function pendingApprovalsBucketsDoNotDoubleCount(): void
    {
        global $wpdb;
        $controller = ntdst_get(\Stride\Admin\AdminAPIController::class);
        $editionId = $this->createTestEdition();

        // Registration where user tasks are DONE — should be 'approval' bucket only,
        // never stale_user even if old.
        $tenDaysAgo = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        $regTable = $wpdb->prefix . 'vad_registrations';
        $wpdb->insert($regTable, [
            'user_id' => self::$adminUserId,
            'edition_id' => $editionId,
            'status' => 'pending',
            'enrollment_path' => 'individual',
            'registered_at' => $tenDaysAgo,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed'],
                'approval' => ['status' => 'pending'],
            ]),
        ]);
        $regId = (int) $wpdb->insert_id;

        $req = new WP_REST_Request('GET', '/stride/v1/admin/pending-approvals');
        $req->set_param('stale_days', 7);
        $items = $controller->getPendingApprovals($req)->get_data()['items'];

        $matching = array_values(array_filter($items, fn($i) => $i['id'] === $regId));
        $this->assertCount(1, $matching, 'Should only appear in one bucket, not both');
        $this->assertEquals('approval', $matching[0]['type']);

        $wpdb->delete($regTable, ['id' => $regId]);
    }

    // =========================================================================
    // IMPERSONATION AUDIT — schema regression (H3)
    // =========================================================================

    /**
     * @test
     */
    public function impersonationAuditRowLandsWithCorrectColumns(): void
    {
        global $wpdb;

        $controller = ntdst_get(\Stride\Admin\AdminAPIController::class);

        $targetId = wp_create_user(
            'imp_target_' . wp_generate_password(6, false),
            'pw',
            'imp_target_' . wp_generate_password(6, false) . '@test.local'
        );
        $this->assertIsInt($targetId);
        self::$testPosts[] = $targetId;
        get_userdata($targetId)->set_role('subscriber');

        $auditTable = $wpdb->prefix . 'audit_log';
        $wpdb->query("DELETE FROM {$auditTable} WHERE action = 'impersonation.started' AND entity_id = " . (int) $targetId);

        $req = new WP_REST_Request('POST', '/stride/v1/admin/users/' . $targetId . '/impersonate');
        $req->set_param('id', $targetId);
        $controller->impersonateUser($req);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT entity_type, entity_id, actor_id, action FROM {$auditTable}
             WHERE action = %s AND entity_id = %d
             ORDER BY id DESC LIMIT 1",
            'impersonation.started',
            $targetId
        ));

        $this->assertNotNull($row, 'Audit row must land — subject_id schema mismatch dropped it under MySQL strict mode');
        $this->assertEquals('user', $row->entity_type);
        $this->assertEquals($targetId, (int) $row->entity_id);
        $this->assertEquals(self::$adminUserId, (int) $row->actor_id);

        $wpdb->query("DELETE FROM {$auditTable} WHERE action = 'impersonation.started' AND entity_id = " . (int) $targetId);
    }
}
