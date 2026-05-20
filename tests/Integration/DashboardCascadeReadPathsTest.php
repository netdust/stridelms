<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\OfferingStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectorySelection;
use Stride\Modules\User\UserDashboardService;

/**
 * Integration tests for Stap 11: dashboard read paths after cascade ships.
 *
 * Verifies:
 *  - UserDashboardService::getEnrollmentData() hides cascade children from
 *    the flat `active_editions` list (rendered under parent trajectory
 *    card instead).
 *  - Pure-LD courses granted via trajectory are filtered out of the flat
 *    `active_online` list (same rationale — shown under trajectory card).
 *  - RegistrationRepository::findEditionsByTrajectory() returns cascade
 *    children (joined via parent_registration_id), so
 *    TrajectoryDashboardService can read child rows as source of truth.
 *  - RegistrationRepository::hasActiveRegistrations() returns false for
 *    trajectory-only users — the "Opleidingen" nav tab shouldn't light up.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter DashboardCascadeReadPaths
 */
final class DashboardCascadeReadPathsTest extends IntegrationTestCase
{
    private UserDashboardService $dashboard;
    private TrajectorySelection $selection;
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboard = ntdst_get(UserDashboardService::class);
        $this->selection = ntdst_get(TrajectorySelection::class);
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        delete_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY);

        parent::tearDown();
    }

    /** @test */
    public function cascadeChildrenAreHiddenFromFlatActiveEditions(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;
        $this->assertCount(1, $this->repo->findByParent($parentId), 'child row was created');

        $data = $this->dashboard->getEnrollmentData(self::$testUserId);

        $editionIds = array_column($data['active_editions'], 'edition_id');
        $this->assertNotContains(
            $editionA,
            $editionIds,
            'cascade child must not appear in flat active_editions list'
        );
    }

    /** @test */
    public function directEditionEnrollmentStillAppearsInActiveEditions(): void
    {
        $edition = $this->createTestEdition();
        $directReg = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->createdRegistrationIds[] = $directReg;

        $data = $this->dashboard->getEnrollmentData(self::$testUserId);
        $editionIds = array_column($data['active_editions'], 'edition_id');
        $this->assertContains($edition, $editionIds, 'direct enrollment still listed');
    }

    /** @test */
    public function pureLdTrajectoryCoursesAreHiddenFromFlatActiveOnline(): void
    {
        $onlineCourse = $this->createTestCourse();
        // Mark course as 'online' so buildOnlineCourses picks it up unfiltered.
        wp_set_object_terms($onlineCourse, 'online', 'stride_format');

        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;

        // Sanity: LD access was granted by the cascade (Stap 5).
        $this->assertTrue($this->userHasLdAccess($onlineCourse));

        $data = $this->dashboard->getEnrollmentData(self::$testUserId);
        $onlineCourseIds = array_column($data['active_online'], 'course_id');
        $this->assertNotContains(
            $onlineCourse,
            $onlineCourseIds,
            'trajectory-granted pure-LD course must not appear in flat active_online list',
        );
    }

    /** @test */
    public function directlyEnrolledOnlineCourseStillAppearsInActiveOnline(): void
    {
        $courseId = $this->createTestCourse();
        wp_set_object_terms($courseId, 'online', 'stride_format');

        ntdst_get(\Stride\Contracts\LMSAdapterInterface::class)
            ->grantAccess(self::$testUserId, $courseId);

        $data = $this->dashboard->getEnrollmentData(self::$testUserId);
        $onlineCourseIds = array_column($data['active_online'], 'course_id');
        $this->assertContains(
            $courseId,
            $onlineCourseIds,
            'directly-granted online courses still listed',
        );

        // Cleanup
        ntdst_get(\Stride\Contracts\LMSAdapterInterface::class)
            ->revokeAccess(self::$testUserId, $courseId);
    }

    /** @test */
    public function findEditionsByTrajectoryReturnsCascadeChildren(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;

        $rows = $this->repo->findEditionsByTrajectory(self::$testUserId, $trajectoryId);
        $this->assertCount(1, $rows);
        $this->assertSame($editionA, (int) $rows[0]->edition_id);
        $this->assertSame($parentId, (int) $rows[0]->parent_registration_id);
        // Cascade children have trajectory_id=NULL — confirm we matched via the JOIN.
        $this->assertNull($rows[0]->trajectory_id);
    }

    /** @test */
    public function findEditionsByTrajectoryStillReturnsLegacyRowsWithTrajectoryIdSet(): void
    {
        // Simulate a pre-cascade row: edition_id + trajectory_id both set,
        // no parent_registration_id. This shape exists in production from
        // before cascade shipped; the read path needs to keep finding it.
        $editionA = $this->createTestEdition();
        $trajectoryId = $this->createOpenTrajectory([]);

        $legacyRow = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionA,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);
        $this->createdRegistrationIds[] = $legacyRow;

        $rows = $this->repo->findEditionsByTrajectory(self::$testUserId, $trajectoryId);
        $this->assertCount(1, $rows);
        $this->assertSame($legacyRow, (int) $rows[0]->id);
    }

    /** @test */
    public function trajectoryOnlyUserDoesNotLightUpOpleidingenNav(): void
    {
        $trajectoryId = $this->createOpenTrajectory([]);
        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;

        $this->assertFalse(
            $this->repo->hasActiveRegistrations(self::$testUserId),
            'trajectory-only user should not have "active regular registrations"',
        );
        $this->assertTrue(
            $this->repo->hasTrajectoryEnrollments(self::$testUserId),
            'trajectory enrollment still surfaces under the Trajecten tab',
        );
    }

    /** @test */
    public function userWithBothDirectAndTrajectoryEnrollmentsHasBothNavSignals(): void
    {
        $edition = $this->createTestEdition();
        $direct = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->createdRegistrationIds[] = $direct;

        $trajectoryId = $this->createOpenTrajectory([]);
        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;

        $this->assertTrue($this->repo->hasActiveRegistrations(self::$testUserId));
        $this->assertTrue($this->repo->hasTrajectoryEnrollments(self::$testUserId));
    }

    // === Helpers ===

    /**
     * @param array<array<string, mixed>> $courses
     */
    private function createOpenTrajectory(array $courses): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Dashboard cascade trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createOpenTrajectory failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, [
            'mode' => TrajectoryMode::Cohort->value,
            'status' => OfferingStatus::Open->value,
            'capacity' => 0,
            'courses' => $courses,
        ]);

        return $trajectoryId;
    }

    private function userHasLdAccess(int $courseId): bool
    {
        if (!function_exists('sfwd_lms_has_access')) {
            return false;
        }
        return (bool) sfwd_lms_has_access($courseId, self::$testUserId);
    }
}
