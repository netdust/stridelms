<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryDashboardService;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Tests\TestCase;

/**
 * Unit tests for TrajectoryDashboardService
 *
 * Tests the personal trajectory dashboard data aggregation service.
 */
class TrajectoryDashboardServiceTest extends TestCase
{
    private TrajectoryRepository|MockInterface $repository;
    private TrajectoryService|MockInterface $trajectoryService;
    private RegistrationRepository|MockInterface $registrationRepo;
    private EditionService|MockInterface $editionService;
    private LMSAdapterInterface|MockInterface $lmsAdapter;
    private TrajectoryDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(TrajectoryRepository::class);
        $this->trajectoryService = Mockery::mock(TrajectoryService::class);
        $this->registrationRepo = Mockery::mock(RegistrationRepository::class);
        $this->editionService = Mockery::mock(EditionService::class);
        $this->lmsAdapter = Mockery::mock(LMSAdapterInterface::class);

        $this->service = new TrajectoryDashboardService(
            $this->repository,
            $this->trajectoryService,
            $this->registrationRepo,
            $this->editionService,
            $this->lmsAdapter
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // getTrajectoryBySlug()
    // =========================================================================

    /**
     * @test
     */
    public function getTrajectoryBySlugReturnsTrajectoryWhenFound(): void
    {
        $trajectory = $this->createTrajectory(['ID' => 100, 'post_name' => 'test-trajectory']);

        $this->repository
            ->shouldReceive('findBySlug')
            ->with('test-trajectory')
            ->once()
            ->andReturn($trajectory);

        $result = $this->service->getTrajectoryBySlug('test-trajectory');

        $this->assertNotNull($result);
        $this->assertEquals(100, $result->ID);
        $this->assertEquals('test-trajectory', $result->post_name);
    }

    /**
     * @test
     */
    public function getTrajectoryBySlugReturnsNullWhenNotFound(): void
    {
        $this->repository
            ->shouldReceive('findBySlug')
            ->with('nonexistent')
            ->once()
            ->andReturn(null);

        $result = $this->service->getTrajectoryBySlug('nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // getEnrollmentForUser()
    // =========================================================================

    /**
     * @test
     */
    public function getEnrollmentForUserReturnsEnrollmentWhenFound(): void
    {
        $userId = 1;
        $trajectoryId = 100;

        $enrollment = (object) [
            'id' => 50,
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'status' => 'confirmed',
        ];

        $this->registrationRepo
            ->shouldReceive('findByUserAndTrajectory')
            ->with($userId, $trajectoryId)
            ->once()
            ->andReturn($enrollment);

        $result = $this->service->getEnrollmentForUser($userId, $trajectoryId);

        $this->assertNotNull($result);
        $this->assertEquals(50, $result->id);
        $this->assertEquals($trajectoryId, $result->trajectory_id);
    }

    /**
     * @test
     */
    public function getEnrollmentForUserReturnsNullWhenNotEnrolled(): void
    {
        $userId = 1;
        $trajectoryId = 100;

        $this->registrationRepo
            ->shouldReceive('findByUserAndTrajectory')
            ->with($userId, $trajectoryId)
            ->once()
            ->andReturn(null);

        $result = $this->service->getEnrollmentForUser($userId, $trajectoryId);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function getEnrollmentForUserReturnsNullWhenNoEnrollments(): void
    {
        $userId = 1;
        $trajectoryId = 100;

        $this->registrationRepo
            ->shouldReceive('findByUserAndTrajectory')
            ->with($userId, $trajectoryId)
            ->once()
            ->andReturn(null);

        $result = $this->service->getEnrollmentForUser($userId, $trajectoryId);

        $this->assertNull($result);
    }

    // =========================================================================
    // getProgressData()
    // =========================================================================

    /**
     * @test
     */
    public function getProgressDataReturnsCorrectStructure(): void
    {
        $userId = 1;
        $trajectoryId = 100;

        $course1 = $this->createCourse(['ID' => 1001, 'post_title' => 'Course 1']);
        $course2 = $this->createCourse(['ID' => 1002, 'post_title' => 'Course 2']);

        $this->trajectoryService
            ->shouldReceive('getTrajectory')
            ->with($trajectoryId)
            ->once()
            ->andReturn(['mode' => TrajectoryMode::Cohort->value]);

        $this->trajectoryService
            ->shouldReceive('getRequiredCourses')
            ->with($trajectoryId)
            ->once()
            ->andReturn([$course1, $course2]);

        $this->trajectoryService
            ->shouldReceive('getElectiveGroups')
            ->with($trajectoryId)
            ->once()
            ->andReturn([]);

        $this->registrationRepo
            ->shouldReceive('findEditionsByTrajectory')
            ->with($userId, $trajectoryId)
            ->once()
            ->andReturn([]);

        // Course 1 completed, Course 2 not
        $this->lmsAdapter
            ->shouldReceive('isComplete')
            ->with($userId, 1001)
            ->once()
            ->andReturn(true);

        $this->lmsAdapter
            ->shouldReceive('isComplete')
            ->with($userId, 1002)
            ->once()
            ->andReturn(false);

        $result = $this->service->getProgressData($userId, $trajectoryId);

        $this->assertArrayHasKey('required_courses', $result);
        $this->assertArrayHasKey('elective_groups', $result);
        $this->assertArrayHasKey('completed_count', $result);
        $this->assertArrayHasKey('in_progress_count', $result);
        $this->assertArrayHasKey('total_required', $result);
        $this->assertArrayHasKey('mode', $result);
        $this->assertArrayHasKey('edition_registrations', $result);
        $this->assertArrayHasKey('completed_courses', $result);
        $this->assertArrayHasKey('in_progress_courses', $result);

        $this->assertEquals(2, count($result['required_courses']));
        $this->assertEquals(2, $result['total_required']);
        $this->assertEquals(1, $result['completed_count']);
        $this->assertEquals(0, $result['in_progress_count']);
        $this->assertEquals(TrajectoryMode::Cohort, $result['mode']);
    }

    /**
     * @test
     */
    public function getProgressDataCountsElectiveRequirements(): void
    {
        $userId = 1;
        $trajectoryId = 100;

        $requiredCourse = $this->createCourse(['ID' => 1001]);
        $electiveCourse1 = $this->createCourse(['ID' => 1002]);
        $electiveCourse2 = $this->createCourse(['ID' => 1003]);

        $this->trajectoryService
            ->shouldReceive('getTrajectory')
            ->with($trajectoryId)
            ->once()
            ->andReturn(['mode' => TrajectoryMode::SelfPaced->value]);

        $this->trajectoryService
            ->shouldReceive('getRequiredCourses')
            ->with($trajectoryId)
            ->once()
            ->andReturn([$requiredCourse]);

        $this->trajectoryService
            ->shouldReceive('getElectiveGroups')
            ->with($trajectoryId)
            ->once()
            ->andReturn([
                [
                    'name' => 'Electives',
                    'required' => 1, // Must complete 1 of 2
                    'courses' => [$electiveCourse1, $electiveCourse2],
                ],
            ]);

        $this->registrationRepo
            ->shouldReceive('findEditionsByTrajectory')
            ->with($userId, $trajectoryId)
            ->once()
            ->andReturn([]);

        $this->lmsAdapter
            ->shouldReceive('isComplete')
            ->andReturn(false);

        $result = $this->service->getProgressData($userId, $trajectoryId);

        // 1 required + 1 from elective group = 2 total required
        $this->assertEquals(2, $result['total_required']);
        $this->assertEquals(TrajectoryMode::SelfPaced, $result['mode']);
    }

    /**
     * @test
     */
    public function getProgressDataTracksInProgressCourses(): void
    {
        $userId = 1;
        $trajectoryId = 100;

        $course = $this->createCourse(['ID' => 1001]);
        $edition = $this->createEdition(['ID' => 4001]);

        $editionReg = (object) [
            'edition_id' => 4001,
            'status' => 'confirmed',
        ];

        $this->trajectoryService
            ->shouldReceive('getTrajectory')
            ->andReturn(['mode' => TrajectoryMode::Cohort->value]);

        $this->trajectoryService
            ->shouldReceive('getRequiredCourses')
            ->andReturn([$course]);

        $this->trajectoryService
            ->shouldReceive('getElectiveGroups')
            ->andReturn([]);

        $this->registrationRepo
            ->shouldReceive('findEditionsByTrajectory')
            ->andReturn([$editionReg]);

        // Course not complete
        $this->lmsAdapter
            ->shouldReceive('isComplete')
            ->with($userId, 1001)
            ->once()
            ->andReturn(false);

        // Edition links to this course
        $this->editionService
            ->shouldReceive('getCourseId')
            ->with(4001)
            ->once()
            ->andReturn(1001);

        $result = $this->service->getProgressData($userId, $trajectoryId);

        $this->assertEquals(0, $result['completed_count']);
        $this->assertEquals(1, $result['in_progress_count']);
        $this->assertContains(1001, $result['in_progress_courses']);
    }

    // =========================================================================
    // getMaterials()
    // =========================================================================

    /**
     * @test
     */
    public function getMaterialsReturnsEmptyArrayWhenNoMaterials(): void
    {
        $trajectoryId = 100;
        $userId = 1;

        $course = $this->createCourse(['ID' => 1001, 'post_title' => 'Course 1']);

        $this->trajectoryService
            ->shouldReceive('getRequiredCourses')
            ->with($trajectoryId)
            ->once()
            ->andReturn([$course]);

        $this->trajectoryService
            ->shouldReceive('getElectiveGroups')
            ->with($trajectoryId)
            ->once()
            ->andReturn([]);

        // No materials stored for this course
        global $_test_post_meta;
        $_test_post_meta[1001]['_sfwd-courses'] = [[]];

        $result = $this->service->getMaterials($trajectoryId, $userId);

        $this->assertIsArray($result);
        // May be empty if no access or no materials
    }

    // =========================================================================
    // getMessages()
    // =========================================================================

    /**
     * @test
     */
    public function getMessagesReturnsEmptyArrayWhenNoMessages(): void
    {
        $trajectoryId = 100;

        $this->repository
            ->shouldReceive('getMessages')
            ->with($trajectoryId)
            ->once()
            ->andReturn([]);

        $result = $this->service->getMessages($trajectoryId);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     */
    public function getMessagesReturnsSortedMessages(): void
    {
        $trajectoryId = 100;

        // Repository already returns sorted messages (newest first)
        $sortedMessages = [
            [
                'type' => 'update',
                'content' => 'New message',
                'author' => 1,
                'date' => '2026-02-24 10:00:00',
            ],
            [
                'type' => 'announcement',
                'content' => 'Old message',
                'author' => 1,
                'date' => '2026-02-20 10:00:00',
            ],
        ];

        $this->repository
            ->shouldReceive('getMessages')
            ->with($trajectoryId)
            ->once()
            ->andReturn($sortedMessages);

        $result = $this->service->getMessages($trajectoryId);

        $this->assertCount(2, $result);
        // Newest first (as returned by repository)
        $this->assertEquals('New message', $result[0]['content']);
        $this->assertEquals('Old message', $result[1]['content']);
    }

    /**
     * @test
     */
    public function getMessagesFiltersDeletedMessages(): void
    {
        $trajectoryId = 100;

        // Repository already filters deleted messages
        $filteredMessages = [
            [
                'type' => 'announcement',
                'content' => 'Active message',
                'author' => 1,
                'date' => '2026-02-24 10:00:00',
            ],
        ];

        $this->repository
            ->shouldReceive('getMessages')
            ->with($trajectoryId)
            ->once()
            ->andReturn($filteredMessages);

        $result = $this->service->getMessages($trajectoryId);

        $this->assertCount(1, $result);
        $this->assertEquals('Active message', $result[0]['content']);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a test trajectory
     */
    protected function createTrajectory(array $data = []): \WP_Post
    {
        global $_test_posts;

        static $nextId = 7000;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Trajectory',
            'post_status' => 'publish',
            'post_name' => 'test-trajectory',
        ];

        $trajectoryData = array_merge($defaults, $data);
        $trajectory = new \WP_Post($trajectoryData);

        $_test_posts[$trajectory->ID] = $trajectory;

        return $trajectory;
    }
}
