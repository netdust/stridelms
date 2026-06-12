<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\PartnerAPI\PartnerAPIController;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Unit tests for PartnerAPIController
 *
 * Tests permission checks, input validation, response formatting.
 * Run: ddev exec vendor/bin/phpunit --filter PartnerAPIController
 */
class PartnerAPIControllerTest extends TestCase
{
    private PartnerAPIController $controller;
    private RegistrationRepository $mockRegRepo;
    private AttendanceRepository $mockAttendanceRepo;
    private EditionRepository $mockEditionRepo;
    private SessionRepository $mockSessionRepo;
    private EnrollmentService $mockEnrollmentService;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global test state before each test
        global $_test_users, $_test_user_meta, $_test_current_user_id;
        $_test_users = [];
        $_test_user_meta = [];
        $_test_current_user_id = 0;

        // Create mock dependencies
        $this->mockRegRepo = $this->createMock(RegistrationRepository::class);
        $this->mockAttendanceRepo = $this->createMock(AttendanceRepository::class);
        $this->mockEditionRepo = $this->createMock(EditionRepository::class);
        $this->mockSessionRepo = $this->createMock(SessionRepository::class);
        $this->mockEnrollmentService = $this->createMock(EnrollmentService::class);

        // Register EnrollmentService mock in the DI container
        // (createEnrollment() resolves it via ntdst_get())
        ntdst_set(EnrollmentService::class, $this->mockEnrollmentService);

        // Create controller with mocked dependencies
        $this->controller = new PartnerAPIController(
            $this->mockRegRepo,
            $this->mockAttendanceRepo,
            $this->mockEditionRepo,
            $this->mockSessionRepo
        );
    }

    // =========================================================================
    // PERMISSION CHECKS
    // =========================================================================

    /**
     * @test
     */
    public function checkPermissionRejectsUnauthenticated(): void
    {
        global $_test_current_user_id;
        $_test_current_user_id = 0;

        $result = $this->controller->checkPermission();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('rest_not_logged_in', $result->get_error_code());
    }

    /**
     * @test
     */
    public function checkPermissionRejectsNonPartnerRole(): void
    {
        global $_test_current_user_id, $_test_users;
        $_test_current_user_id = 1;

        // Create user without partner role
        $_test_users[1] = (object) [
            'ID' => 1,
            'user_email' => 'user@test.com',
            'roles' => ['subscriber'],
        ];

        $result = $this->controller->checkPermission();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('rest_forbidden', $result->get_error_code());
    }

    /**
     * @test
     */
    public function checkPermissionRejectsPartnerWithoutCompanyId(): void
    {
        global $_test_current_user_id, $_test_users, $_test_user_meta;
        $_test_current_user_id = 1;

        // Create partner user without company_id
        $_test_users[1] = (object) [
            'ID' => 1,
            'user_email' => 'partner@test.com',
            'roles' => ['partner'],
        ];
        $_test_user_meta[1] = []; // No company_id

        $result = $this->controller->checkPermission();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('rest_forbidden', $result->get_error_code());
    }

    /**
     * @test
     */
    public function checkPermissionAllowsValidPartner(): void
    {
        $this->setupValidPartner(1, 100);

        $result = $this->controller->checkPermission();

        $this->assertTrue($result);
    }

    // =========================================================================
    // GET /partner/users
    // =========================================================================

    /**
     * @test
     */
    public function getUsersReturnsPaginatedResults(): void
    {
        $this->setupValidPartner(1, 100);
        $this->setupCompanyUsers(100, 3); // 3 company users + 1 partner user = 4 total

        $request = new WP_REST_Request('GET', '/stride/v1/partner/users');
        $request->set_param('page', 1);
        $request->set_param('per_page', 20);

        $response = $this->controller->getUsers($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('per_page', $data);
        // 3 company users + 1 partner user (who also belongs to the company)
        $this->assertEquals(4, $data['total']);
    }

    /**
     * @test
     */
    public function getUsersFormatsUserDataCorrectly(): void
    {
        $this->setupValidPartner(1, 100);
        $this->setupCompanyUsers(100, 1, [
            'user_email' => 'jan@company.com',
            'first_name' => 'Jan',
            'last_name' => 'Janssen',
        ]);

        $request = new WP_REST_Request('GET', '/stride/v1/partner/users');

        $response = $this->controller->getUsers($request);
        $data = $response->get_data();

        // 2 users: partner user + 1 company user
        $this->assertCount(2, $data['data']);

        // Find the company user (not the partner)
        $companyUser = null;
        foreach ($data['data'] as $user) {
            if ($user['email'] === 'jan@company.com') {
                $companyUser = $user;
                break;
            }
        }

        $this->assertNotNull($companyUser);
        $this->assertArrayHasKey('id', $companyUser);
        $this->assertArrayHasKey('email', $companyUser);
        $this->assertArrayHasKey('first_name', $companyUser);
        $this->assertArrayHasKey('last_name', $companyUser);
        $this->assertArrayHasKey('registered_at', $companyUser);
        $this->assertEquals('jan@company.com', $companyUser['email']);
    }

    // =========================================================================
    // GET /partner/enrollments
    // =========================================================================

    /**
     * @test
     */
    public function getEnrollmentsRejectsUserFromDifferentCompany(): void
    {
        $this->setupValidPartner(1, 100);

        // Create user from different company
        global $_test_users, $_test_user_meta;
        $_test_users[99] = (object) [
            'ID' => 99,
            'user_email' => 'other@different.com',
            'roles' => ['subscriber'],
        ];
        $_test_user_meta[99] = ['_stride_company_id' => [200]]; // Different company

        $request = new WP_REST_Request('GET', '/stride/v1/partner/enrollments');
        $request->set_param('user_id', 99);

        $response = $this->controller->getEnrollments($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('rest_forbidden', $response->get_error_code());
    }

    /**
     * @test
     */
    public function getEnrollmentsCallsRepositoryWithCorrectFilters(): void
    {
        $this->setupValidPartner(1, 100);

        $this->mockRegRepo
            ->expects($this->once())
            ->method('findByCompany')
            ->with(
                $this->equalTo(100),
                $this->callback(function ($filters) {
                    return $filters['status'] === 'confirmed'
                        && $filters['edition_id'] === 123
                        && $filters['page'] === 2
                        && $filters['per_page'] === 10;
                })
            )
            ->willReturn(['data' => [], 'total' => 0]);

        $request = new WP_REST_Request('GET', '/stride/v1/partner/enrollments');
        $request->set_param('status', 'confirmed');
        $request->set_param('edition_id', 123);
        $request->set_param('page', 2);
        $request->set_param('per_page', 10);

        $this->controller->getEnrollments($request);
    }

    // =========================================================================
    // GET /partner/enrollments/{id}
    // =========================================================================

    /**
     * @test
     */
    public function getEnrollmentReturnsNotFoundForMissing(): void
    {
        $this->setupValidPartner(1, 100);

        $this->mockRegRepo
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $request = new WP_REST_Request('GET', '/stride/v1/partner/enrollments/999');
        $request->set_param('id', 999);

        $response = $this->controller->getEnrollment($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('rest_not_found', $response->get_error_code());
    }

    /**
     * @test
     */
    public function getEnrollmentRejectsDifferentCompanyEnrollment(): void
    {
        $this->setupValidPartner(1, 100);

        // Return registration from different company
        $this->mockRegRepo
            ->method('find')
            ->with(456)
            ->willReturn((object) [
                'id' => 456,
                'user_id' => 2,
                'company_id' => 200, // Different company
                'edition_id' => 789,
                'status' => 'confirmed',
            ]);

        $request = new WP_REST_Request('GET', '/stride/v1/partner/enrollments/456');
        $request->set_param('id', 456);

        $response = $this->controller->getEnrollment($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('rest_forbidden', $response->get_error_code());
    }

    /**
     * @test
     */
    public function getEnrollmentReturnsFullDetailsForOwnCompany(): void
    {
        $this->setupValidPartner(1, 100);
        $this->setupCompanyUsers(100, 1);

        // Setup registration belonging to partner's company
        $this->mockRegRepo
            ->method('find')
            ->with(456)
            ->willReturn((object) [
                'id' => 456,
                'user_id' => 10, // Will be created by setupCompanyUsers
                'company_id' => 100,
                'edition_id' => 789,
                'trajectory_id' => null,
                'status' => 'confirmed',
                'enrollment_path' => 'individual',
                'registered_at' => '2026-02-25 10:00:00',
                'completed_at' => null,
                'notes' => 'Test note',
            ]);

        // Setup edition data - getEdition returns WP_Post
        $editionPost = new \WP_Post();
        $editionPost->ID = 789;
        $editionPost->post_title = 'Test Edition';

        $this->mockEditionRepo
            ->method('find')
            ->with(789)
            ->willReturn($editionPost);

        $this->mockEditionRepo
            ->method('getField')
            ->with(789, 'course_id')
            ->willReturn(1001);

        $request = new WP_REST_Request('GET', '/stride/v1/partner/enrollments/456');
        $request->set_param('id', 456);

        $response = $this->controller->getEnrollment($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();

        $this->assertEquals(456, $data['id']);
        $this->assertEquals('confirmed', $data['status']);
        $this->assertEquals('Test note', $data['notes']);
        $this->assertEquals('individual', $data['enrollment_path']);
    }

    // =========================================================================
    // POST /partner/enrollments
    // =========================================================================

    /**
     * @test
     */
    public function createEnrollmentRequiresUserEmail(): void
    {
        $this->setupValidPartner(1, 100);

        $request = new WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_param('edition_id', 789);
        // No user_email

        $response = $this->controller->createEnrollment($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('invalid_request', $response->get_error_code());
    }

    /**
     * @test
     */
    public function createEnrollmentRequiresEditionOrTrajectory(): void
    {
        $this->setupValidPartner(1, 100);

        $request = new WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_param('user_email', 'test@test.com');
        // No edition_id or trajectory_id

        $response = $this->controller->createEnrollment($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('invalid_request', $response->get_error_code());
    }

    /**
     * @test
     */
    public function createEnrollmentValidatesEditionExists(): void
    {
        $this->setupValidPartner(1, 100);

        // getEdition returns WP_Error when edition not found
        $this->mockEditionRepo
            ->method('find')
            ->with(999)
            ->willReturn(new WP_Error('not_found', 'Edition not found'));

        $request = new WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_param('user_email', 'test@test.com');
        $request->set_param('edition_id', 999);

        $response = $this->controller->createEnrollment($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('not_found', $response->get_error_code());
    }

    /**
     * @test
     */
    public function createEnrollmentRejectsUserFromDifferentCompany(): void
    {
        $this->setupValidPartner(1, 100);

        // Create existing user from different company
        global $_test_users, $_test_user_meta;
        $_test_users[50] = (object) [
            'ID' => 50,
            'user_email' => 'existing@other.com',
            'roles' => ['subscriber'],
        ];
        $_test_user_meta[50] = ['_stride_company_id' => [200]]; // Different company

        $editionPost = new \WP_Post();
        $editionPost->ID = 789;
        $editionPost->post_title = 'Test Edition';

        $this->mockEditionRepo
            ->method('find')
            ->with(789)
            ->willReturn($editionPost);

        $request = new WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_param('user_email', 'existing@other.com');
        $request->set_param('edition_id', 789);

        $response = $this->controller->createEnrollment($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('forbidden', $response->get_error_code());
    }

    /**
     * @test
     */
    public function createEnrollmentCreatesUserWhenRequested(): void
    {
        $this->setupValidPartner(1, 100);

        $editionPost = new \WP_Post();
        $editionPost->ID = 789;
        $editionPost->post_title = 'Test Edition';

        $this->mockEditionRepo
            ->method('find')
            ->with(789)
            ->willReturn($editionPost);

        $this->mockEnrollmentService
            ->method('enroll')
            ->willReturn(123);

        $request = new WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_param('user_email', 'newuser@test.com');
        $request->set_param('edition_id', 789);
        $request->set_param('create_user', true);

        $response = $this->controller->createEnrollment($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(201, $response->get_status());

        // Verify user was created with company_id
        global $_test_users, $_test_user_meta;
        $createdUser = null;
        foreach ($_test_users as $user) {
            if ($user->user_email === 'newuser@test.com') {
                $createdUser = $user;
                break;
            }
        }
        $this->assertNotNull($createdUser);
        $this->assertEquals(100, $_test_user_meta[$createdUser->ID]['_stride_company_id'][0]);
    }

    /**
     * @test
     */
    public function createEnrollmentReturnsCreatedStatus(): void
    {
        $this->setupValidPartner(1, 100);
        $this->setupCompanyUsers(100, 1, ['user_email' => 'member@company.com']);

        $editionPost = new \WP_Post();
        $editionPost->ID = 789;
        $editionPost->post_title = 'Test Edition';

        $this->mockEditionRepo
            ->method('find')
            ->with(789)
            ->willReturn($editionPost);

        $this->mockEnrollmentService
            ->method('enroll')
            ->willReturn(456);

        $request = new WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_param('user_email', 'member@company.com');
        $request->set_param('edition_id', 789);

        $response = $this->controller->createEnrollment($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(201, $response->get_status());

        $data = $response->get_data();
        $this->assertEquals(456, $data['id']);
        $this->assertEquals(789, $data['edition_id']);
        $this->assertEquals('confirmed', $data['status']);
    }

    /**
     * @test
     */
    public function createEnrollmentHandlesDuplicateError(): void
    {
        $this->setupValidPartner(1, 100);
        $this->setupCompanyUsers(100, 1, ['user_email' => 'member@company.com']);

        $editionPost = new \WP_Post();
        $editionPost->ID = 789;
        $editionPost->post_title = 'Test Edition';

        $this->mockEditionRepo
            ->method('find')
            ->with(789)
            ->willReturn($editionPost);

        $this->mockEnrollmentService
            ->method('enroll')
            ->willReturn(new \WP_Error('already_enrolled', 'User is already enrolled in this edition'));

        $request = new WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_param('user_email', 'member@company.com');
        $request->set_param('edition_id', 789);

        $response = $this->controller->createEnrollment($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertEquals('already_enrolled', $response->get_error_code());
        $this->assertEquals(409, $response->get_error_data()['status']);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Setup a valid partner user with company_id
     */
    private function setupValidPartner(int $userId, int $companyId): void
    {
        global $_test_current_user_id, $_test_users, $_test_user_meta;

        $_test_current_user_id = $userId;

        $_test_users[$userId] = (object) [
            'ID' => $userId,
            'user_email' => "partner{$userId}@test.com",
            'first_name' => 'Partner',
            'last_name' => 'User',
            'roles' => ['partner'],
            'user_registered' => date('Y-m-d H:i:s'),
        ];

        $_test_user_meta[$userId] = [
            '_stride_company_id' => [$companyId],
        ];
    }

    /**
     * Setup company users for testing (separate from partner user)
     */
    private function setupCompanyUsers(int $companyId, int $count, array $override = []): void
    {
        global $_test_users, $_test_user_meta;

        // Start at ID 100+ to avoid collision with partner users (1-99)
        static $nextId = 100;

        for ($i = 0; $i < $count; $i++) {
            $userId = $nextId++;
            $defaults = [
                'ID' => $userId,
                'user_email' => "user{$userId}@company{$companyId}.com",
                'first_name' => 'User',
                'last_name' => 'Number' . $userId,
                'roles' => ['subscriber'],
                'user_registered' => date('Y-m-d H:i:s'),
            ];

            $_test_users[$userId] = (object) array_merge($defaults, $override);
            $_test_user_meta[$userId] = [
                '_stride_company_id' => [$companyId],
            ];
        }
    }
}
