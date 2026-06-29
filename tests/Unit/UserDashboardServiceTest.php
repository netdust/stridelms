<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Domain\QuoteStatus;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\EditionCompletion;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\UserDashboardService;
use Stride\Tests\TestCase;
use WP_User;

/**
 * Unit tests for UserDashboardService — getHomeData() and helpers.
 */
class UserDashboardServiceTest extends TestCase
{
    private UserDashboardService $service;
    private RegistrationRepository $regRepo;
    private EditionService $editionService;
    private EditionRepository $editionRepository;
    private SessionService $sessionService;
    private AttendanceService $attendanceService;
    private EditionCompletion $completionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->editionService = $this->createMock(EditionService::class);
        $this->editionRepository = $this->createMock(EditionRepository::class);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->attendanceService = $this->createMock(AttendanceService::class);
        $this->completionService = $this->createMock(EditionCompletion::class);

        $this->service = new UserDashboardService(
            $this->regRepo,
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
            $this->attendanceService,
            $this->completionService,
        );

        // Stub EnrollmentCompletion in test container (used via ntdst_get)
        $mockEnrollmentCompletion = $this->createMock(EnrollmentCompletion::class);
        $mockEnrollmentCompletion->method('getPendingForUser')->willReturn([]);
        $this->registerService(EnrollmentCompletion::class, $mockEnrollmentCompletion);

        // Stub QuoteService
        $mockQuoteService = new class {
            public function getUserQuotes(int $userId): array
            {
                return [];
            }
        };
        $this->registerService(\Stride\Modules\Invoicing\QuoteService::class, $mockQuoteService);

        // Ensure no registrations by default
        $this->regRepo->method('findByUser')->willReturn([]);
        $this->regRepo->method('findTrajectoryEnrollmentsByUser')->willReturn([]);
    }

    // ================================================================
    // getHomeData() structure tests
    // ================================================================

    /** @test */
    public function testGetHomeDataReturnsCorrectStructure(): void
    {
        global $_test_users;

        $user = new WP_User(['ID' => 1, 'first_name' => 'Jan', 'last_name' => 'Peeters', 'user_email' => 'jan@example.com', 'display_name' => 'Jan Peeters']);
        $_test_users[1] = $user;

        $result = $this->service->getHomeData(1);

        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('hero', $result);
        $this->assertArrayHasKey('actions', $result);
        $this->assertArrayHasKey('active_enrollments', $result);
        $this->assertArrayHasKey('active_trajectories', $result);
        $this->assertArrayHasKey('recent_certificates', $result);
        // nav_items was deleted with buildNavItems() + the orphaned
        // nav-dock.php template — its only reader (CR-E4).
        $this->assertArrayNotHasKey('nav_items', $result);
    }

    /** @test */
    public function testGetHomeDataUserInfo(): void
    {
        global $_test_users;

        $user = new WP_User(['ID' => 1, 'first_name' => 'Jan', 'last_name' => 'Peeters', 'user_email' => 'jan@example.com', 'display_name' => 'Jan Peeters']);
        $_test_users[1] = $user;

        $result = $this->service->getHomeData(1);

        $this->assertEquals('Jan Peeters', $result['user']['name']);
        $this->assertEquals('JP', $result['user']['initials']);
        $this->assertEquals('jan@example.com', $result['user']['email']);
    }

    /** @test */
    public function testGetHomeDataHeroIsNullWhenEmpty(): void
    {
        global $_test_users;
        $_test_users[1] = new WP_User(['ID' => 1, 'first_name' => 'Jan', 'last_name' => 'Peeters', 'user_email' => 'jan@example.com', 'display_name' => 'Jan Peeters']);

        $result = $this->service->getHomeData(1);

        $this->assertNull($result['hero']);
    }

    /** @test */
    public function testGetHomeDataRecentCertificatesCappedAtThree(): void
    {
        global $_test_users;
        $_test_users[1] = new WP_User(['ID' => 1, 'first_name' => 'Jan', 'last_name' => 'Peeters', 'user_email' => 'jan@example.com', 'display_name' => 'Jan Peeters']);

        $result = $this->service->getHomeData(1);

        // With empty data, certificates should be empty
        $this->assertCount(0, $result['recent_certificates']);
        $this->assertLessThanOrEqual(3, count($result['recent_certificates']));
    }

    // ================================================================
    // findEnrolledEditionForLesson() — edition-context resolution for the
    // LearnDash "Terug" button (a lesson done via an edition's online
    // session should return to that edition, not the bare course).
    // ================================================================

    /** @test */
    public function testFindEnrolledEditionForLessonReturnsEditionWhenOnlineSessionLinksLesson(): void
    {
        // User enrolled (confirmed) in edition 50, whose online session links lesson 99.
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->method('findByUser')->willReturn([
            (object) ['edition_id' => 50, 'status' => 'confirmed'],
        ]);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->sessionService->method('getSessionsForEdition')->with(50)->willReturn([
            ['type' => 'online', 'lesson_ids' => [99]],
        ]);

        $service = $this->makeService();

        $this->assertSame(50, $service->findEnrolledEditionForLesson(1, 99));
    }

    /** @test */
    public function testFindEnrolledEditionForLessonPicksMostRecentWhenMultipleMatch(): void
    {
        // findByUser returns registrations registered_at DESC — most recent first.
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->method('findByUser')->willReturn([
            (object) ['edition_id' => 70, 'status' => 'confirmed'], // most recent
            (object) ['edition_id' => 50, 'status' => 'confirmed'],
        ]);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->sessionService->method('getSessionsForEdition')->willReturnCallback(
            fn(int $e) => [['type' => 'online', 'lesson_ids' => [99]]],
        );

        $service = $this->makeService();

        $this->assertSame(70, $service->findEnrolledEditionForLesson(1, 99));
    }

    /** @test */
    public function testFindEnrolledEditionForLessonReturnsNullWhenNoSessionLinksLesson(): void
    {
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->method('findByUser')->willReturn([
            (object) ['edition_id' => 50, 'status' => 'confirmed'],
        ]);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->sessionService->method('getSessionsForEdition')->willReturn([
            ['type' => 'online', 'lesson_ids' => [11, 22]], // not 99
        ]);

        $service = $this->makeService();

        $this->assertNull($service->findEnrolledEditionForLesson(1, 99));
    }

    /** @test */
    public function testFindEnrolledEditionForLessonIgnoresNonOnlineSessions(): void
    {
        // An in_person session that happens to carry a lesson_id must NOT match —
        // only online sessions surface lessons as e-learning steps.
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->method('findByUser')->willReturn([
            (object) ['edition_id' => 50, 'status' => 'confirmed'],
        ]);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->sessionService->method('getSessionsForEdition')->willReturn([
            ['type' => 'in_person', 'lesson_ids' => [99]],
        ]);

        $service = $this->makeService();

        $this->assertNull($service->findEnrolledEditionForLesson(1, 99));
    }

    /** @test */
    public function testFindEnrolledEditionForLessonIgnoresNonActiveRegistrations(): void
    {
        // A cancelled registration must not resolve as the return edition.
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->method('findByUser')->willReturn([
            (object) ['edition_id' => 50, 'status' => 'cancelled'],
        ]);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->sessionService->method('getSessionsForEdition')->willReturn([
            ['type' => 'online', 'lesson_ids' => [99]],
        ]);

        $service = $this->makeService();

        $this->assertNull($service->findEnrolledEditionForLesson(1, 99));
    }

    /**
     * Build a UserDashboardService from the current (possibly per-test
     * re-mocked) dependencies.
     */
    private function makeService(): UserDashboardService
    {
        return new UserDashboardService(
            $this->regRepo,
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
            $this->attendanceService,
            $this->completionService,
        );
    }

    // ================================================================
    // getInitials() tests (via reflection)
    // ================================================================

    /** @test */
    public function testGetInitialsWithFullName(): void
    {
        $user = new WP_User(['ID' => 1, 'first_name' => 'Jan', 'last_name' => 'Peeters', 'display_name' => 'Jan Peeters']);

        $result = $this->invokePrivate('getInitials', [$user]);

        $this->assertEquals('JP', $result);
    }

    /** @test */
    public function testGetInitialsWithFirstNameOnly(): void
    {
        $user = new WP_User(['ID' => 1, 'first_name' => 'Jan', 'last_name' => '', 'display_name' => 'Jan']);

        $result = $this->invokePrivate('getInitials', [$user]);

        $this->assertEquals('J', $result);
    }

    /** @test */
    public function testGetInitialsWithLastNameOnly(): void
    {
        $user = new WP_User(['ID' => 1, 'first_name' => '', 'last_name' => 'Peeters', 'display_name' => 'Peeters']);

        $result = $this->invokePrivate('getInitials', [$user]);

        $this->assertEquals('P', $result);
    }

    /** @test */
    public function testGetInitialsFallsBackToDisplayName(): void
    {
        $user = new WP_User(['ID' => 1, 'first_name' => '', 'last_name' => '', 'display_name' => 'admin']);

        $result = $this->invokePrivate('getInitials', [$user]);

        $this->assertEquals('A', $result);
    }

    /** @test */
    public function testGetInitialsReturnsQuestionMarkForNull(): void
    {
        $result = $this->invokePrivate('getInitials', [null]);

        $this->assertEquals('?', $result);
    }

    /** @test */
    public function testGetInitialsIsUppercase(): void
    {
        $user = new WP_User(['ID' => 1, 'first_name' => 'jan', 'last_name' => 'peeters', 'display_name' => 'jan peeters']);

        $result = $this->invokePrivate('getInitials', [$user]);

        $this->assertEquals('JP', $result);
    }

    // ================================================================
    // resolveHero() tests (via reflection)
    // ================================================================

    /** @test */
    public function testResolveHeroReturnsNullWhenEmpty(): void
    {
        $result = $this->invokePrivate('resolveHero', [[], [], [], []]);

        $this->assertNull($result);
    }

    /** @test */
    public function testResolveHeroReturnsUpcomingSessionWhenTomorrow(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $sessions = [
            ['date' => $tomorrow, 'course_title' => 'Test Course', 'edition_id' => 1],
        ];

        $result = $this->invokePrivate('resolveHero', [$sessions, [], [], []]);

        $this->assertNotNull($result);
        $this->assertEquals('upcoming_session', $result['type']);
        $this->assertEquals($tomorrow, $result['data']['date']);
    }

    /** @test */
    public function testResolveHeroReturnsUpcomingSessionWhenToday(): void
    {
        $today = date('Y-m-d');
        $sessions = [
            ['date' => $today, 'course_title' => 'Test Course', 'edition_id' => 1],
        ];

        $result = $this->invokePrivate('resolveHero', [$sessions, [], [], []]);

        $this->assertNotNull($result);
        $this->assertEquals('upcoming_session', $result['type']);
    }

    /** @test */
    public function testResolveHeroIgnoresSessionFarInFuture(): void
    {
        $nextWeek = date('Y-m-d', strtotime('+7 days'));
        $sessions = [
            ['date' => $nextWeek, 'course_title' => 'Test Course', 'edition_id' => 1],
        ];

        $result = $this->invokePrivate('resolveHero', [$sessions, [], [], []]);

        // Next week session should NOT trigger upcoming_session hero
        $this->assertNull($result);
    }

    /** @test */
    public function testResolveHeroReturnsActionRequiredWhenNoImminentSession(): void
    {
        $nextWeek = date('Y-m-d', strtotime('+7 days'));
        $sessions = [
            ['date' => $nextWeek, 'course_title' => 'Test Course'],
        ];
        $actions = [
            ['label' => 'Voltooi inschrijving', 'course_title' => 'EHBO', 'url' => '/test'],
        ];

        $result = $this->invokePrivate('resolveHero', [$sessions, $actions, [], []]);

        $this->assertNotNull($result);
        $this->assertEquals('action_required', $result['type']);
        $this->assertEquals('EHBO', $result['data']['course_title']);
    }

    /** @test */
    public function testResolveHeroReturnsContinueCourseWithProgress(): void
    {
        $enrollments = [
            ['type' => 'online', 'progress' => 42, 'course_title' => 'Online Course'],
        ];

        $result = $this->invokePrivate('resolveHero', [[], [], $enrollments, []]);

        $this->assertNotNull($result);
        $this->assertEquals('continue_course', $result['type']);
        $this->assertEquals(42, $result['data']['progress']);
    }

    /** @test */
    public function testResolveHeroSkipsOnlineCourseWithZeroProgress(): void
    {
        $enrollments = [
            ['type' => 'online', 'progress' => 0, 'course_title' => 'Online Course'],
        ];

        $result = $this->invokePrivate('resolveHero', [[], [], $enrollments, []]);

        // Zero progress online course should fall through to active_enrollment
        $this->assertNotNull($result);
        $this->assertEquals('active_enrollment', $result['type']);
    }

    /** @test */
    public function testResolveHeroReturnsActiveEnrollmentAsFallback(): void
    {
        $enrollments = [
            ['type' => 'edition', 'course_title' => 'Classroom Course'],
        ];

        $result = $this->invokePrivate('resolveHero', [[], [], $enrollments, []]);

        $this->assertNotNull($result);
        $this->assertEquals('active_enrollment', $result['type']);
    }

    /** @test */
    public function testResolveHeroReturnsCertificateAsLastResort(): void
    {
        $certificates = [
            ['course_title' => 'Completed Course', 'certificate_url' => '/cert/1'],
        ];

        $result = $this->invokePrivate('resolveHero', [[], [], [], $certificates]);

        $this->assertNotNull($result);
        $this->assertEquals('certificate_ready', $result['type']);
    }

    /** @test */
    public function testResolveHeroPrioritySessionOverAction(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $sessions = [
            ['date' => $tomorrow, 'course_title' => 'Test Course'],
        ];
        $actions = [
            ['label' => 'Voltooi inschrijving', 'course_title' => 'EHBO', 'url' => '/test'],
        ];

        $result = $this->invokePrivate('resolveHero', [$sessions, $actions, [], []]);

        // Session should take priority over action
        $this->assertEquals('upcoming_session', $result['type']);
    }

    // ================================================================
    // buildActionList() tests (via reflection)
    // ================================================================

    /** @test */
    public function testBuildActionListReturnsEmptyForNoActionItems(): void
    {
        $enrollmentData = [
            'action_items' => [],
        ];
        $quoteData = ['active' => [], 'cancelled' => []];

        $result = $this->invokePrivate('buildActionList', [$enrollmentData, $quoteData]);

        $this->assertCount(0, $result);
    }

    /** @test */
    public function testBuildActionListReturnsActionItems(): void
    {
        $enrollmentData = [
            'action_items' => [
                ['type' => 'enrollment', 'label' => 'Voltooi inschrijving', 'course_title' => 'EHBO', 'url' => '/test'],
            ],
        ];
        $quoteData = ['active' => [], 'cancelled' => []];

        $result = $this->invokePrivate('buildActionList', [$enrollmentData, $quoteData]);

        $this->assertCount(1, $result);
        $this->assertEquals('enrollment', $result[0]['type']);
        $this->assertEquals('EHBO', $result[0]['course_title']);
    }

    /** @test */
    public function testBuildActionListTasksBeforeLessons(): void
    {
        $enrollmentData = [
            'action_items' => [
                ['type' => 'online_lesson', 'label' => 'Les 1', 'course_title' => 'Online', 'url' => '/les1'],
                ['type' => 'enrollment', 'label' => 'Inschrijving', 'course_title' => 'EHBO', 'url' => '/test'],
                ['type' => 'online_lesson', 'label' => 'Les 2', 'course_title' => 'Online', 'url' => '/les2'],
            ],
        ];
        $quoteData = ['active' => [], 'cancelled' => []];

        $result = $this->invokePrivate('buildActionList', [$enrollmentData, $quoteData]);

        $this->assertCount(3, $result);
        // Task first, then lessons
        $this->assertEquals('enrollment', $result[0]['type']);
        $this->assertEquals('online_lesson', $result[1]['type']);
        $this->assertEquals('online_lesson', $result[2]['type']);
    }

    /** @test */
    public function testBuildActionListLimitsLessonsToSixTotal(): void
    {
        $enrollmentData = [
            'action_items' => [
                ['type' => 'enrollment', 'label' => 'Task 1', 'course_title' => 'A', 'url' => '/1'],
                ['type' => 'enrollment', 'label' => 'Task 2', 'course_title' => 'B', 'url' => '/2'],
                ['type' => 'online_lesson', 'label' => 'Les 1', 'course_title' => 'C', 'url' => '/l1'],
                ['type' => 'online_lesson', 'label' => 'Les 2', 'course_title' => 'C', 'url' => '/l2'],
                ['type' => 'online_lesson', 'label' => 'Les 3', 'course_title' => 'C', 'url' => '/l3'],
                ['type' => 'online_lesson', 'label' => 'Les 4', 'course_title' => 'C', 'url' => '/l4'],
                ['type' => 'online_lesson', 'label' => 'Les 5', 'course_title' => 'C', 'url' => '/l5'],
            ],
        ];
        $quoteData = ['active' => [], 'cancelled' => []];

        $result = $this->invokePrivate('buildActionList', [$enrollmentData, $quoteData]);

        // 2 tasks + 4 lessons = 6 (max), 5th lesson dropped
        $this->assertCount(6, $result);
        $this->assertEquals('enrollment', $result[0]['type']);
        $this->assertEquals('enrollment', $result[1]['type']);
        $this->assertEquals('online_lesson', $result[2]['type']);
    }

    /** @test */
    public function testBuildActionListIgnoresQuotesAndSessions(): void
    {
        // buildActionList only processes action_items, not sessions or quotes
        $enrollmentData = [
            'upcoming_sessions' => [
                ['course_title' => 'EHBO', 'date' => '2026-04-01'],
            ],
            'action_items' => [],
            'completed_items' => [
                ['course_title' => 'Done', 'certificate_url' => 'https://example.com/cert'],
            ],
        ];
        $quoteData = [
            'active' => [
                ['status' => QuoteStatus::Draft, 'quote_number' => 'Q-001'],
            ],
            'cancelled' => [],
        ];

        $result = $this->invokePrivate('buildActionList', [$enrollmentData, $quoteData]);

        // Sessions, quotes, certificates are handled separately in getHomeData()
        $this->assertCount(0, $result);
    }

    // ================================================================
    // buildActiveTrajectories() tests (via reflection)
    // ================================================================

    /** @test */
    public function testBuildActiveTrajectoriesReturnsEmptyWhenNone(): void
    {
        $result = $this->invokePrivate('buildActiveTrajectories', [1]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /** @test */
    public function testBuildActiveTrajectoriesReturnsPostData(): void
    {
        global $_test_posts;

        $trajectory = new \WP_Post([
            'ID' => 100,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Verpleegkunde Traject',
            'post_name' => 'verpleegkunde-traject',
            'post_status' => 'publish',
        ]);
        $_test_posts[100] = $trajectory;

        // Override mock to return trajectory enrollment
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->method('findByUser')->willReturn([]);
        $this->regRepo->method('findTrajectoryEnrollmentsByUser')->willReturn([
            (object) ['id' => 50, 'trajectory_id' => 100, 'user_id' => 1, 'status' => 'confirmed'],
        ]);

        // Rebuild service with new mock
        $this->service = new UserDashboardService(
            $this->regRepo,
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
            $this->attendanceService,
            $this->completionService,
        );

        $result = $this->invokePrivate('buildActiveTrajectories', [1]);

        $this->assertCount(1, $result);
        $this->assertEquals(100, $result[0]['id']);
        $this->assertEquals('Verpleegkunde Traject', $result[0]['title']);
        $this->assertEquals('verpleegkunde-traject', $result[0]['slug']);
        $this->assertStringContains('verpleegkunde-traject', $result[0]['url']);
    }

    /** @test */
    public function testBuildActiveTrajectoriesSkipsUnpublished(): void
    {
        global $_test_posts;

        $trajectory = new \WP_Post([
            'ID' => 101,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Draft Traject',
            'post_name' => 'draft-traject',
            'post_status' => 'draft',
        ]);
        $_test_posts[101] = $trajectory;

        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->method('findByUser')->willReturn([]);
        $this->regRepo->method('findTrajectoryEnrollmentsByUser')->willReturn([
            (object) ['id' => 51, 'trajectory_id' => 101, 'user_id' => 1, 'status' => 'confirmed'],
        ]);

        $this->service = new UserDashboardService(
            $this->regRepo,
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
            $this->attendanceService,
            $this->completionService,
        );

        $result = $this->invokePrivate('buildActiveTrajectories', [1]);

        $this->assertEmpty($result);
    }

    // ================================================================
    // Per-request memoization (audit CR-2 / Task E1)
    //
    // getEnrollmentData() + getQuoteData() memoize the ASSEMBLED array per
    // user id (instance property — per-request only, never static), mirroring
    // RegistrationRepository::$findByUserCache including its invalidation
    // point. nav consistency is now structural: page-mijn-account.php builds
    // a static sidebar and getNavData() was deleted with its last consumer
    // (the orphaned nav-dock.php template was the only nav_items reader).
    // ================================================================

    /** @test */
    public function testGetEnrollmentDataIsMemoizedWithinRequest(): void
    {
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->expects($this->once())
            ->method('findByUser')
            ->with(1)
            ->willReturn([]);

        $this->service = new UserDashboardService(
            $this->regRepo,
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
            $this->attendanceService,
            $this->completionService,
        );

        $first  = $this->service->getEnrollmentData(1);
        $second = $this->service->getEnrollmentData(1);

        $this->assertSame($first, $second, 'second call must return the memoized assembled array');
    }

    /** @test */
    public function testGetQuoteDataIsMemoizedWithinRequest(): void
    {
        $quoteService = $this->makeCountingQuoteService();
        $this->registerService(\Stride\Modules\Invoicing\QuoteService::class, $quoteService);

        $first  = $this->service->getQuoteData(1);
        $second = $this->service->getQuoteData(1);

        $this->assertSame([1], $quoteService->calls, 'QuoteService must be hit exactly once for one user');
        $this->assertSame($first, $second);
    }

    /** @test */
    public function testMemoIsIsolatedPerUser(): void
    {
        $quoteService = $this->makeCountingQuoteService();
        $this->registerService(\Stride\Modules\Invoicing\QuoteService::class, $quoteService);

        $userA = $this->service->getQuoteData(1);
        $userB = $this->service->getQuoteData(2);
        $userARepeat = $this->service->getQuoteData(1);

        // User A's memo is never returned for user B and vice versa.
        $this->assertSame('Q-1', $userA['active'][0]['quote_number']);
        $this->assertSame('Q-2', $userB['active'][0]['quote_number']);
        $this->assertSame($userA, $userARepeat, 'user A memo survives an interleaved user B read');
        $this->assertSame([1, 2], $quoteService->calls, 'one fetch per user, no cross-user reuse');
    }

    /** @test */
    public function testRegistrationCacheClearedActionInvalidatesEnrollmentMemo(): void
    {
        $this->regRepo = $this->createMock(RegistrationRepository::class);
        $this->regRepo->expects($this->exactly(2))
            ->method('findByUser')
            ->with(1)
            ->willReturn([]);

        $this->service = new UserDashboardService(
            $this->regRepo,
            $this->editionService,
            $this->editionRepository,
            $this->sessionService,
            $this->attendanceService,
            $this->completionService,
        );

        // Fill memo (two calls = one fetch), then a registration write path
        // fires RegistrationRepository::clearCache()'s action — the memo must
        // be dropped so the next read sees fresh data.
        $this->service->getEnrollmentData(1);
        $this->service->getEnrollmentData(1);

        do_action('stride/registration/cache_cleared');

        $this->service->getEnrollmentData(1);
    }

    /** @test */
    public function testQuoteDataChangedActionInvalidatesQuoteMemo(): void
    {
        $quoteService = $this->makeCountingQuoteService();
        $this->registerService(\Stride\Modules\Invoicing\QuoteService::class, $quoteService);

        $this->service->getQuoteData(1);
        $this->service->getQuoteData(1);

        do_action('stride/quote/data_changed');

        $this->service->getQuoteData(1);

        $this->assertSame([1, 1], $quoteService->calls, 'quote write must invalidate the memo within the request');
    }

    /**
     * QuoteService stub that records which user ids were fetched and returns
     * a distinct quote per user (so cross-user leaks are observable).
     */
    private function makeCountingQuoteService(): object
    {
        return new class {
            /** @var array<int> */
            public array $calls = [];

            public function getUserQuotes(int $userId): array
            {
                $this->calls[] = $userId;

                return [[
                    'ID'             => 100 + $userId,
                    'quote_number'   => 'Q-' . $userId,
                    'post_title'     => 'Offerte ' . $userId,
                    'status_enum'    => QuoteStatus::Draft,
                    'total_money'    => null,
                    'subtotal_money' => null,
                    'discount_money' => null,
                    'tax_money'      => null,
                    'items'          => [],
                    'valid_until'    => '',
                    'post_date'      => '2026-01-01',
                    'voucher_code'   => '',
                ]];
            }
        };
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * Invoke a private method on the service for testing.
     */
    private function invokePrivate(string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod(UserDashboardService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->service, ...$args);
    }

    /**
     * Assert string contains substring (helper for readability).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
