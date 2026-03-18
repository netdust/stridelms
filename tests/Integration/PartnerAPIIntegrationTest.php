<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\PartnerAPI\PartnerAPIController;

/**
 * Integration tests for Partner API
 *
 * Tests full API flow: auth → endpoint → database → response
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter PartnerAPI
 */
class PartnerAPIIntegrationTest extends IntegrationTestCase
{
    private static ?int $partnerUserId = null;
    private static ?int $companyUserId = null;
    private static int $companyId = 9999;
    private static ?int $testEditionId = null;
    private static array $testRegistrationIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create partner user
        $partnerUsername = 'partner_test_' . time() . '_' . wp_generate_password(4, false);
        self::$partnerUserId = wp_create_user($partnerUsername, 'testpass123', $partnerUsername . '@partner.test');

        if (is_wp_error(self::$partnerUserId)) {
            throw new \RuntimeException('Failed to create partner user: ' . self::$partnerUserId->get_error_message());
        }

        // Add partner role and company_id
        $user = get_user_by('ID', self::$partnerUserId);
        $user->add_role('partner');
        update_user_meta(self::$partnerUserId, '_stride_company_id', self::$companyId);

        // Create company member user
        $memberUsername = 'member_test_' . time() . '_' . wp_generate_password(4, false);
        self::$companyUserId = wp_create_user($memberUsername, 'testpass123', $memberUsername . '@company.test');

        if (is_wp_error(self::$companyUserId)) {
            throw new \RuntimeException('Failed to create company user: ' . self::$companyUserId->get_error_message());
        }

        // Link company member to company
        update_user_meta(self::$companyUserId, '_stride_company_id', self::$companyId);
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        // Clean up registrations
        foreach (self::$testRegistrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }

        // Clean up test edition
        if (self::$testEditionId) {
            wp_delete_post(self::$testEditionId, true);
        }

        // Clean up partner user
        if (self::$partnerUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$partnerUserId);
        }

        // Clean up company user
        if (self::$companyUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$companyUserId);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$partnerUserId);
    }

    // =========================================================================
    // REGISTRATION REPOSITORY - findByCompany
    // =========================================================================

    /**
     * @test
     */
    public function findByCompanyReturnsOnlyCompanyRegistrations(): void
    {
        $repo = $this->getRegistrationRepository();

        // Create test edition
        $editionId = $this->createTestEdition();

        // Create registration for company user
        $regId = $repo->create([
            'user_id' => self::$companyUserId,
            'edition_id' => $editionId,
            'company_id' => self::$companyId,
        ]);

        if (is_wp_error($regId)) {
            $this->fail('Failed to create registration: ' . $regId->get_error_message());
        }

        self::$testRegistrationIds[] = $regId;

        // Query by company
        $result = $repo->findByCompany(self::$companyId);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThanOrEqual(1, $result['total']);

        // Verify our registration is in the results
        $found = false;
        foreach ($result['data'] as $row) {
            if ((int) $row->id === $regId) {
                $found = true;
                $this->assertEquals(self::$companyUserId, (int) $row->user_id);
                $this->assertEquals(self::$companyId, (int) $row->company_id);
                break;
            }
        }
        $this->assertTrue($found, 'Created registration not found in company results');
    }

    /**
     * @test
     */
    public function findByCompanyFiltersbyStatus(): void
    {
        $repo = $this->getRegistrationRepository();
        $editionId = $this->createTestEdition();

        // Create confirmed registration
        $confirmedId = $repo->create([
            'user_id' => self::$companyUserId,
            'edition_id' => $editionId,
            'company_id' => self::$companyId,
            'status' => 'confirmed',
        ]);

        if (!is_wp_error($confirmedId)) {
            self::$testRegistrationIds[] = $confirmedId;
        }

        // Query with status filter
        $result = $repo->findByCompany(self::$companyId, ['status' => 'confirmed']);

        $this->assertArrayHasKey('data', $result);

        // Verify all results have confirmed status
        foreach ($result['data'] as $row) {
            $this->assertEquals('confirmed', $row->status);
        }
    }

    /**
     * @test
     */
    public function findByCompanyFiltersByEdition(): void
    {
        $repo = $this->getRegistrationRepository();

        $edition1 = $this->createTestEdition(['post_title' => 'Edition 1 ' . time()]);
        $edition2 = $this->createTestEdition(['post_title' => 'Edition 2 ' . time()]);

        // Create registrations for different editions
        $reg1 = $repo->create([
            'user_id' => self::$companyUserId,
            'edition_id' => $edition1,
            'company_id' => self::$companyId,
        ]);

        if (!is_wp_error($reg1)) {
            self::$testRegistrationIds[] = $reg1;
        }

        // Query with edition filter
        $result = $repo->findByCompany(self::$companyId, ['edition_id' => $edition1]);

        $this->assertArrayHasKey('data', $result);

        // Verify all results are for the filtered edition
        foreach ($result['data'] as $row) {
            $this->assertEquals($edition1, (int) $row->edition_id);
        }
    }

    /**
     * @test
     */
    public function findByCompanyPaginatesCorrectly(): void
    {
        $repo = $this->getRegistrationRepository();

        // Create multiple editions to avoid duplicate registration error
        $editions = [];
        for ($i = 0; $i < 5; $i++) {
            $editions[] = $this->createTestEdition(['post_title' => "Pagination Test Edition $i " . time()]);
        }

        // Create 5 registrations
        foreach ($editions as $editionId) {
            $regId = $repo->create([
                'user_id' => self::$companyUserId,
                'edition_id' => $editionId,
                'company_id' => self::$companyId,
            ]);

            if (!is_wp_error($regId)) {
                self::$testRegistrationIds[] = $regId;
            }
        }

        // Query page 1 with per_page=2
        $page1 = $repo->findByCompany(self::$companyId, ['page' => 1, 'per_page' => 2]);

        $this->assertLessThanOrEqual(2, count($page1['data']));

        // Query page 2
        $page2 = $repo->findByCompany(self::$companyId, ['page' => 2, 'per_page' => 2]);

        // Page 2 should have different or empty results
        if (count($page1['data']) > 0 && count($page2['data']) > 0) {
            $this->assertNotEquals(
                $page1['data'][0]->id,
                $page2['data'][0]->id,
                'Page 1 and Page 2 should have different first items'
            );
        }
    }

    /**
     * @test
     */
    public function findByCompanyExcludesOtherCompanies(): void
    {
        global $wpdb;
        $repo = $this->getRegistrationRepository();
        $editionId = $this->createTestEdition();

        // Create a registration for a DIFFERENT company directly in DB
        $otherCompanyId = self::$companyId + 1;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => self::$testUserId, // Use base test user
            'edition_id' => $editionId,
            'company_id' => $otherCompanyId,
            'status' => 'confirmed',
        ]);
        $otherRegId = (int) $wpdb->insert_id;
        self::$testRegistrationIds[] = $otherRegId;

        // Query our company
        $result = $repo->findByCompany(self::$companyId);

        // Verify the other company's registration is not in results
        foreach ($result['data'] as $row) {
            $this->assertNotEquals($otherRegId, (int) $row->id, 'Should not include other company registrations');
        }
    }

    // =========================================================================
    // REST API ENDPOINTS
    // =========================================================================

    /**
     * @test
     */
    public function partnerAPIRoutesAreRegistered(): void
    {
        // Force rest_api_init to fire
        do_action('rest_api_init');

        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/stride/v1/partner/users', $routes);
        $this->assertArrayHasKey('/stride/v1/partner/enrollments', $routes);
        $this->assertArrayHasKey('/stride/v1/partner/certificates', $routes);
        $this->assertArrayHasKey('/stride/v1/partner/attendance', $routes);
    }

    /**
     * @test
     */
    public function partnerAPIRejectsUnauthenticated(): void
    {
        wp_set_current_user(0);

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/users');
        $response = rest_do_request($request);

        $this->assertEquals(401, $response->get_status());
    }

    /**
     * @test
     */
    public function partnerAPIRejectsNonPartnerUser(): void
    {
        $this->actingAs(self::$testUserId); // Regular test user, not partner

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/users');
        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /**
     * @test
     */
    public function getUsersEndpointReturnsCompanyUsers(): void
    {
        $this->actingAs(self::$partnerUserId);

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/users');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('per_page', $data);

        // Should include company member
        $found = false;
        foreach ($data['data'] as $user) {
            if ((int) $user['id'] === self::$companyUserId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Company member should be in results');
    }

    /**
     * @test
     */
    public function getEnrollmentsEndpointReturnsCompanyEnrollments(): void
    {
        $repo = $this->getRegistrationRepository();
        $editionId = $this->createTestEdition();

        // Create enrollment
        $regId = $repo->create([
            'user_id' => self::$companyUserId,
            'edition_id' => $editionId,
            'company_id' => self::$companyId,
        ]);

        if (!is_wp_error($regId)) {
            self::$testRegistrationIds[] = $regId;
        }

        $this->actingAs(self::$partnerUserId);

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/enrollments');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);

        // Should include our registration
        $found = false;
        foreach ($data['data'] as $enrollment) {
            if ((int) $enrollment['id'] === $regId) {
                $found = true;
                $this->assertEquals(self::$companyUserId, $enrollment['user_id']);
                break;
            }
        }
        $this->assertTrue($found, 'Created enrollment should be in results');
    }

    /**
     * @test
     */
    public function getSingleEnrollmentEndpointWorks(): void
    {
        $repo = $this->getRegistrationRepository();
        $editionId = $this->createTestEdition();

        $regId = $repo->create([
            'user_id' => self::$companyUserId,
            'edition_id' => $editionId,
            'company_id' => self::$companyId,
        ]);

        if (is_wp_error($regId)) {
            $this->fail('Failed to create registration: ' . $regId->get_error_message());
        }

        self::$testRegistrationIds[] = $regId;

        $this->actingAs(self::$partnerUserId);

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/enrollments/' . $regId);
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertEquals($regId, $data['id']);
        $this->assertEquals(self::$companyUserId, $data['user_id']);
        $this->assertEquals('confirmed', $data['status']);
    }

    /**
     * @test
     */
    public function createEnrollmentEndpointWorks(): void
    {
        $editionId = $this->createTestEdition();

        // Create a new user email for this test
        $testEmail = 'newmember_' . time() . '@company.test';

        $this->actingAs(self::$partnerUserId);

        $request = new \WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_body_params([
            'user_email' => $testEmail,
            'edition_id' => $editionId,
            'create_user' => true,
        ]);
        $response = rest_do_request($request);

        $this->assertEquals(201, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('user_id', $data);
        $this->assertEquals($editionId, $data['edition_id']);
        $this->assertEquals('confirmed', $data['status']);

        // Track for cleanup
        self::$testRegistrationIds[] = $data['id'];

        // Clean up created user
        if ($data['user_id']) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($data['user_id']);
        }
    }

    /**
     * @test
     */
    public function createEnrollmentRejectsDuplicate(): void
    {
        $repo = $this->getRegistrationRepository();
        $editionId = $this->createTestEdition();

        // Create first enrollment
        $regId = $repo->create([
            'user_id' => self::$companyUserId,
            'edition_id' => $editionId,
            'company_id' => self::$companyId,
        ]);

        if (!is_wp_error($regId)) {
            self::$testRegistrationIds[] = $regId;
        }

        $this->actingAs(self::$partnerUserId);

        // Try to create duplicate
        $user = get_userdata(self::$companyUserId);
        $request = new \WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_body_params([
            'user_email' => $user->user_email,
            'edition_id' => $editionId,
        ]);
        $response = rest_do_request($request);

        $this->assertEquals(409, $response->get_status());
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getRegistrationRepository(): RegistrationRepository
    {
        return ntdst_get(RegistrationRepository::class);
    }
}
