<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Integration tests for cascadeOnCancellation() — mode-aware parent cancel.
 *
 * Covers Stap 7 of plans/2026-05-20-trajectory-cascade-enrollment.md:
 *  - Cohort mode: children cancelled, LD revoked for each child + each
 *    pure-LD entry tied to the parent. Unrelated parents' meta is preserved.
 *  - Self-paced mode: no-op — children + LD access untouched.
 *  - Already cancelled / unknown parent: graceful no-op.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter TrajectoryCascadeCancellation
 */
final class TrajectoryCascadeCancellationTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private TrajectoryCascadeService $cascade;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->cascade = ntdst_get(TrajectoryCascadeService::class);
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
    public function cohortCancelTransitionsAllChildrenAndRevokesLdAccess(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $courseB = $this->createTestCourse();
        $editionB = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseB]]);

        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$editionA, $editionB]);
        $this->assertTrue($this->userHasLdAccess($courseA));
        $this->assertTrue($this->userHasLdAccess($courseB));

        $this->cascade->cascadeOnCancellation($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(2, $children);
        foreach ($children as $child) {
            $this->assertSame(RegistrationStatus::Cancelled->value, $child->status);
            $this->assertNotEmpty($child->cancelled_at);
        }

        $this->assertFalse($this->userHasLdAccess($courseA), 'LD access for child A revoked');
        $this->assertFalse($this->userHasLdAccess($courseB), 'LD access for child B revoked');
    }

    /** @test */
    public function cohortCancelRevokesPureLdGrantsAndClearsMeta(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createTrajectoryWithCourses(TrajectoryMode::Cohort, [
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnEnrollment($parentId);
        $this->assertTrue($this->userHasLdAccess($onlineCourse));
        $this->assertCount(1, $this->readMeta());

        $this->cascade->cascadeOnCancellation($parentId);

        $this->assertFalse($this->userHasLdAccess($onlineCourse));
        $this->assertSame([], $this->readMeta(), 'meta cleared when no entries remain');
    }

    /** @test */
    public function cohortCancelPreservesPureLdEntriesFromOtherParents(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createTrajectoryWithCourses(TrajectoryMode::Cohort, [
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->createParentRegistration($trajectoryId);

        // Pre-existing entry from a different parent (unrelated trajectory).
        $unrelatedEntry = [
            'course_id' => 9999,
            'trajectory_id' => 8888,
            'parent_registration_id' => 7777,
            'granted_at' => '2026-01-01 00:00:00',
        ];
        update_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, [$unrelatedEntry]);

        $this->cascade->cascadeOnEnrollment($parentId);
        $this->assertCount(2, $this->readMeta());

        $this->cascade->cascadeOnCancellation($parentId);

        $remaining = $this->readMeta();
        $this->assertCount(1, $remaining, 'unrelated entry survives the cascade');
        $this->assertSame(9999, (int) $remaining[0]['course_id']);
    }

    /** @test */
    public function selfPacedCancelLeavesChildrenAndLdAccessIntact(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);

        $trajectoryId = $this->createTrajectory(TrajectoryMode::SelfPaced);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);
        $this->assertTrue($this->userHasLdAccess($courseA));

        $this->cascade->cascadeOnCancellation($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $this->assertSame(RegistrationStatus::Confirmed->value, $children[0]->status, 'self-paced children stay confirmed');
        $this->assertEmpty($children[0]->cancelled_at);

        $this->assertTrue($this->userHasLdAccess($courseA), 'self-paced LD access untouched');
    }

    /** @test */
    public function selfPacedCancelLeavesPureLdMetaIntact(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createTrajectoryWithCourses(TrajectoryMode::SelfPaced, [
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnEnrollment($parentId);
        $this->assertCount(1, $this->readMeta());

        $this->cascade->cascadeOnCancellation($parentId);

        $this->assertCount(1, $this->readMeta(), 'self-paced does not touch the pure-LD meta');
        $this->assertTrue($this->userHasLdAccess($onlineCourse));
    }

    /** @test */
    public function isIdempotentOnCohort(): void
    {
        $editionA = $this->createTestEdition();
        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);

        $this->cascade->cascadeOnCancellation($parentId);
        $this->cascade->cascadeOnCancellation($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $this->assertSame(RegistrationStatus::Cancelled->value, $children[0]->status);
    }

    /** @test */
    public function noOpWhenParentIsNotATrajectoryRegistration(): void
    {
        $edition = $this->createTestEdition();
        $orphan = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->createdRegistrationIds[] = $orphan;

        $this->cascade->cascadeOnCancellation($orphan);

        $row = $this->repo->find($orphan);
        $this->assertSame(RegistrationStatus::Confirmed->value, $row->status, 'edition-only registration untouched');
    }

    // === Helpers ===

    private function createTrajectory(TrajectoryMode $mode): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Cancellation cascade trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createTrajectory failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, ['mode' => $mode->value]);

        return $trajectoryId;
    }

    /**
     * @param array<array<string, mixed>> $courses
     */
    private function createTrajectoryWithCourses(TrajectoryMode $mode, array $courses): int
    {
        $trajectoryId = $this->createTrajectory($mode);
        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, ['courses' => $courses]);
        return $trajectoryId;
    }

    private function createParentRegistration(int $trajectoryId): int
    {
        $id = $this->repo->create([
            'user_id' => self::$testUserId,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);
        if (is_wp_error($id)) {
            $this->fail('createParentRegistration failed: ' . $id->get_error_message());
        }
        $this->createdRegistrationIds[] = $id;
        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readMeta(): array
    {
        $raw = get_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, true);
        return is_array($raw) ? array_values($raw) : [];
    }

    private function userHasLdAccess(int $courseId): bool
    {
        if (!function_exists('sfwd_lms_has_access')) {
            return false;
        }
        return (bool) sfwd_lms_has_access($courseId, self::$testUserId);
    }
}
