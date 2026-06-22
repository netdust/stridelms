<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use Stride\Domain\QuoteStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for GET /stride/v1/admin/registrations
 *
 * Task 1.3 — AdminRegistrationQueryService + thin REST route.
 *
 * Tier A. This task:
 *  - registers a new REST route (wiring) → Seam test required
 *  - the permission_callback canViewAdmin is a load-bearing security guard → M1 denial must be RED-first
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminRegistrationsEndpoint
 */
final class AdminRegistrationsEndpointTest extends IntegrationTestCase
{
    private static ?int $coordinatorUserId = null;
    private static ?int $plainUserId = null;
    private static ?int $testEditionId = null;
    private static ?int $testEditionId2 = null;
    private static ?int $testStudentUserId = null;
    private static ?int $testStudentUserId2 = null;
    private static ?int $testQuoteId = null;

    /** @var list<int> */
    private array $testRegistrationIds = [];

    // =========================================================================
    // SUITE SETUP / TEARDOWN
    // =========================================================================

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        // Coordinator with stride_view capability
        $coordinatorUsername = 'coord_t13_' . uniqid();
        self::$coordinatorUserId = wp_create_user(
            $coordinatorUsername,
            'testpass123',
            $coordinatorUsername . '@test.local'
        );
        if (is_wp_error(self::$coordinatorUserId)) {
            throw new \RuntimeException('Could not create coordinator: ' . self::$coordinatorUserId->get_error_message());
        }
        $coord = get_user_by('ID', self::$coordinatorUserId);
        $coord->set_role('stride_coordinator');

        // Plain user WITHOUT stride_view
        $plainUsername = 'plain_t13_' . uniqid();
        self::$plainUserId = wp_create_user(
            $plainUsername,
            'testpass123',
            $plainUsername . '@test.local'
        );
        if (is_wp_error(self::$plainUserId)) {
            throw new \RuntimeException('Could not create plain user: ' . self::$plainUserId->get_error_message());
        }

        // Students to enroll
        $s1 = wp_create_user('student_t13a_' . uniqid(), 'testpass123', 'student_t13a_' . uniqid() . '@test.local');
        $s2 = wp_create_user('student_t13b_' . uniqid(), 'testpass123', 'student_t13b_' . uniqid() . '@test.local');
        if (is_wp_error($s1) || is_wp_error($s2)) {
            throw new \RuntimeException('Could not create student users');
        }
        self::$testStudentUserId  = (int) $s1;
        self::$testStudentUserId2 = (int) $s2;

        update_user_meta(self::$testStudentUserId, 'billing_company', 'Acme Corp');

        // Two editions
        $e1 = wp_insert_post([
            'post_title'  => 'Edition Alpha T13',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $e2 = wp_insert_post([
            'post_title'  => 'Edition Beta T13',
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($e1) || is_wp_error($e2)) {
            throw new \RuntimeException('Could not create editions');
        }
        self::$testEditionId  = (int) $e1;
        self::$testEditionId2 = (int) $e2;
        self::$testPosts[]    = self::$testEditionId;
        self::$testPosts[]    = self::$testEditionId2;

        // Quote (exported) linked to registration — quote_id set later via update after reg is created
        $qId = wp_insert_post([
            'post_title'  => 'Quote T13',
            'post_type'   => 'vad_quote',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($qId)) {
            throw new \RuntimeException('Could not create quote: ' . $qId->get_error_message());
        }
        self::$testQuoteId  = (int) $qId;
        self::$testPosts[]  = self::$testQuoteId;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$coordinatorUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$coordinatorUserId);
        }
        if (self::$plainUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$plainUserId);
        }
        if (self::$testStudentUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$testStudentUserId);
        }
        if (self::$testStudentUserId2) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$testStudentUserId2);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Default: act as coordinator
        $this->actingAs(self::$coordinatorUserId);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->testRegistrationIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        $this->testRegistrationIds = [];
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getRepo(): RegistrationRepository
    {
        return ntdst_get(RegistrationRepository::class);
    }

    private function createReg(array $overrides = []): int
    {
        $defaults = [
            'user_id'         => self::$testStudentUserId,
            'edition_id'      => self::$testEditionId,
            'status'          => 'confirmed',
            'enrollment_path' => 'individual',
        ];
        $id = $this->getRepo()->create(array_merge($defaults, $overrides));
        $this->assertIsInt($id, 'Failed to create registration');
        $this->testRegistrationIds[] = $id;
        return $id;
    }

    private function dispatch(string $method, string $path, array $params = []): \WP_REST_Response|\WP_Error
    {
        $request = new \WP_REST_Request($method, $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_do_request($request);
    }

    // =========================================================================
    // M1 SECURITY: ANONYMOUS DENIAL (load-bearing — RED proof target)
    // =========================================================================

    /**
     * @test
     * Unauthenticated request → 401/403 (M1 permission_callback must deny).
     * This is the load-bearing security assertion for this task.
     */
    public function unauthenticatedRequestIsDenied(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations');

        $this->assertContains(
            $response->get_status(),
            [401, 403],
            'Unauthenticated request must be denied (401 or 403)'
        );
    }

    /**
     * @test
     * User without stride_view capability → 403.
     */
    public function unprivilegedUserIsDenied(): void
    {
        $this->actingAs(self::$plainUserId);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations');

        $this->assertEquals(403, $response->get_status(), 'User without stride_view must be denied');
    }

    // =========================================================================
    // HAPPY PATH: §3.1 composite DTO
    // =========================================================================

    /**
     * @test
     * As coordinator, GET /admin/registrations returns 200 with the composite
     * page DTO — each item carries: id, user, edition, status, offerteStatus,
     * attendancePct, company keys.
     */
    public function coordinatorReceivesCompositeDto(): void
    {
        $regId = $this->createReg();

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
        ]);

        $this->assertEquals(200, $response->get_status(), 'Coordinator must receive 200');

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('perPage', $data);
        $this->assertArrayHasKey('totalPages', $data);

        // Find our registration in the items
        $found = null;
        foreach ($data['items'] as $item) {
            if ((int) $item['id'] === $regId) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, "Registration {$regId} not found in items");

        // §3.1 — every required key must be present
        $this->assertArrayHasKey('id', $found);
        $this->assertArrayHasKey('user', $found);
        $this->assertArrayHasKey('edition', $found);
        $this->assertArrayHasKey('status', $found);
        $this->assertArrayHasKey('offerteStatus', $found);
        $this->assertArrayHasKey('attendancePct', $found);
        $this->assertArrayHasKey('company', $found);

        // User sub-keys
        $this->assertArrayHasKey('id', $found['user']);
        $this->assertArrayHasKey('name', $found['user']);
        $this->assertArrayHasKey('email', $found['user']);

        // Edition sub-keys
        $this->assertArrayHasKey('id', $found['edition']);
        $this->assertArrayHasKey('title', $found['edition']);

        // Company sub-keys
        $this->assertArrayHasKey('id', $found['company']);
        $this->assertArrayHasKey('name', $found['company']);

        // Status label must be a string (RegistrationStatus::label())
        $this->assertArrayHasKey('label', $found['status']);
        $this->assertIsString($found['status']['label']);
    }

    // =========================================================================
    // TWO-STEP OFFERTE RESOLVER
    // =========================================================================

    /**
     * @test
     * A registration with an exported quote → offerteStatus is "Verwerkt" (exported label).
     * A registration with no quote → offerteStatus is "Geen offerte".
     * This proves the two-step resolver is running; NOT a paid flag.
     */
    public function offerteStatusReflectsQuoteWorkflowNotPaymentFlag(): void
    {
        // Registration WITH quote (exported)
        $regWithQuote = $this->createReg([
            'user_id'    => self::$testStudentUserId,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        // Attach quote to registration via postmeta
        update_post_meta(self::$testQuoteId, 'registration_id', $regWithQuote);
        update_post_meta(self::$testQuoteId, 'status', QuoteStatus::Exported->value);

        // Registration WITHOUT any quote
        $regNoQuote = $this->createReg([
            'user_id'    => self::$testStudentUserId2,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
        ]);

        $this->assertEquals(200, $response->get_status());

        $data  = $response->get_data();
        $items = $data['items'];

        $withQuoteItem = null;
        $noQuoteItem   = null;
        foreach ($items as $item) {
            if ((int) $item['id'] === $regWithQuote) {
                $withQuoteItem = $item;
            }
            if ((int) $item['id'] === $regNoQuote) {
                $noQuoteItem = $item;
            }
        }

        $this->assertNotNull($withQuoteItem, "Registration with quote not found in items");
        $this->assertNotNull($noQuoteItem, "Registration without quote not found in items");

        // Exported → "Verwerkt"
        $this->assertEquals(
            QuoteStatus::Exported->label(),
            $withQuoteItem['offerteStatus'],
            'Row with exported quote must show Verwerkt, not a paid flag'
        );

        // No quote → "Geen offerte"
        $this->assertEquals(
            'Geen offerte',
            $noQuoteItem['offerteStatus'],
            'Row with no quote must show "Geen offerte"'
        );

        // Clean up quote link
        delete_post_meta(self::$testQuoteId, 'registration_id');
    }

    // =========================================================================
    // STATUS PARAM PASSES THROUGH TO queryForGrid
    // =========================================================================

    /**
     * @test
     * Passing status=confirmed narrows results to confirmed rows only
     * (proves the param flows through the service to queryForGrid).
     */
    public function statusParamNarrowsResults(): void
    {
        // Confirmed registration
        $confirmedId = $this->createReg([
            'user_id'    => self::$testStudentUserId,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        // Cancelled registration (same edition)
        $cancelledId = $this->createReg([
            'user_id'    => self::$testStudentUserId2,
            'edition_id' => self::$testEditionId,
            'status'     => 'cancelled',
        ]);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
            'status'        => 'confirmed',
        ]);

        $this->assertEquals(200, $response->get_status());

        $data    = $response->get_data();
        $itemIds = array_column($data['items'], 'id');

        // confirmed row is present
        $this->assertContains((string) $confirmedId, array_map('strval', $itemIds));

        // cancelled row is absent when filtering by confirmed
        $this->assertNotContains((string) $cancelledId, array_map('strval', $itemIds));
    }

    // =========================================================================
    // GROUP_BY: returns aggregates, not arbitrary rows
    // =========================================================================

    /**
     * @test
     * When group_by=status is passed, the response items are GROUP AGGREGATES
     * (each item has group_value + count + pct_afgerond), NOT arbitrary flat rows.
     */
    public function groupByReturnsAggregatesNotArbitraryRows(): void
    {
        // Two confirmed registrations for edition 1
        $this->createReg([
            'user_id'    => self::$testStudentUserId,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);
        $this->createReg([
            'user_id'    => self::$testStudentUserId2,
            'edition_id' => self::$testEditionId,
            'status'     => 'confirmed',
        ]);

        $response = $this->dispatch('GET', '/stride/v1/admin/registrations', [
            'edition_scope' => 'all',
            'group_by'      => 'status',
        ]);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('items', $data);

        // Items must be aggregate shape, not flat registration rows
        foreach ($data['items'] as $item) {
            $this->assertArrayHasKey('group_value', $item, 'Grouped items must have group_value key');
            $this->assertArrayHasKey('count', $item, 'Grouped items must have count key');
            $this->assertArrayHasKey('pct_afgerond', $item, 'Grouped items must have pct_afgerond key');
            // Flat-row keys must NOT be present (they would indicate an arbitrary-row response)
            $this->assertArrayNotHasKey('user', $item, 'Grouped items must not carry individual user data');
            $this->assertArrayNotHasKey('offerteStatus', $item, 'Grouped items must not carry offerteStatus per row');
        }

        // Find confirmed group — must aggregate both registrations
        $confirmedGroup = null;
        foreach ($data['items'] as $item) {
            if ($item['group_value'] === 'confirmed') {
                $confirmedGroup = $item;
                break;
            }
        }
        $this->assertNotNull($confirmedGroup, 'confirmed group must appear in grouped response');
        $this->assertGreaterThanOrEqual(2, $confirmedGroup['count'], 'confirmed group must count both registrations');
    }
}
