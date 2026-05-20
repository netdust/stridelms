<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectorySelection;

/**
 * Integration tests for Stap 10: cascade wired into EnrollmentService.
 *
 * Trajectory module's TrajectoryService listens to
 * stride/registration/cancelled + stride/registration/confirmed events and
 * routes them through TrajectoryCascadeService when the row is a trajectory
 * parent (trajectory_id set, no edition_id, no parent_registration_id).
 *
 * Tests the wiring, not the cascade body (covered in
 * TrajectoryCascade{Cancellation,StatusChange}Test).
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter EnrollmentServiceCascadeWiring
 */
final class EnrollmentServiceCascadeWiringTest extends IntegrationTestCase
{
    private EnrollmentService $enrollment;
    private TrajectorySelection $selection;
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enrollment = ntdst_get(EnrollmentService::class);
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
    public function cancellingTrajectoryParentCascadesToChildren(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory(TrajectoryMode::Cohort, [
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $this->assertSame(RegistrationStatus::Confirmed->value, $children[0]->status);

        // Cancel via the canonical service path.
        $result = $this->enrollment->cancel($parentId);
        $this->assertTrue($result);

        $childAfter = $this->repo->findByParent($parentId)[0];
        $this->assertSame(RegistrationStatus::Cancelled->value, $childAfter->status, 'child cancelled by listener');
        $this->assertFalse($this->userHasLdAccess($courseA), 'LD revoked by cascade');
    }

    /** @test */
    public function cancellingTrajectoryParentRevokesPureLdGrants(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createOpenTrajectory(TrajectoryMode::Cohort, [
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;
        $this->assertTrue($this->userHasLdAccess($onlineCourse));
        $this->assertCount(1, $this->readMeta());

        $this->enrollment->cancel($parentId);

        $this->assertFalse($this->userHasLdAccess($onlineCourse), 'pure-LD revoked via cascade');
        $this->assertSame([], $this->readMeta(), 'pure-LD meta cleaned up by cascade');
    }

    /** @test */
    public function selfPacedParentCancellationDoesNotCascade(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory(TrajectoryMode::SelfPaced, [
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;
        $this->assertTrue($this->userHasLdAccess($courseA));

        $this->enrollment->cancel($parentId);

        $childAfter = $this->repo->findByParent($parentId)[0];
        $this->assertSame(RegistrationStatus::Confirmed->value, $childAfter->status, 'self-paced child stays confirmed');
        $this->assertTrue($this->userHasLdAccess($courseA), 'self-paced LD access preserved');
    }

    /** @test */
    public function cancellingEditionOnlyRegistrationDoesNotInvokeCascade(): void
    {
        // A non-cascade child row: a plain edition registration with no
        // trajectory_id at all. EnrollmentService::cancel() fires the
        // cancelled event; the listener checks "is this a trajectory
        // parent?" and skips. We verify no side effects on any meta.
        $edition = $this->createTestEdition();
        $directReg = $this->enrollment->enroll(self::$testUserId, $edition);
        $this->assertIsInt($directReg);
        $this->createdRegistrationIds[] = $directReg;

        // Seed unrelated meta to make sure the listener doesn't clobber it.
        $unrelated = [[
            'course_id' => 9999,
            'trajectory_id' => 8888,
            'parent_registration_id' => 7777,
            'granted_at' => '2026-01-01 00:00:00',
        ]];
        update_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, $unrelated);

        $this->enrollment->cancel($directReg);

        $this->assertSame($unrelated, $this->readMeta(), 'edition-only cancel does not trigger trajectory cascade');
    }

    /** @test */
    public function confirmingTrajectoryParentCascadesToChildren(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory(TrajectoryMode::Cohort, [
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $parentId;

        // Force parent + child to Pending — the realistic admin-approval scenario.
        $this->repo->updateStatus($parentId, RegistrationStatus::Pending);
        foreach ($this->repo->findByParent($parentId) as $child) {
            $this->repo->updateStatus((int) $child->id, RegistrationStatus::Pending);
            // Revoke any prior grant so the post-confirm state is observable.
            ntdst_get(\Stride\Contracts\LMSAdapterInterface::class)->revokeAccess(self::$testUserId, $courseA);
        }
        $this->assertFalse($this->userHasLdAccess($courseA));

        // Admin confirms via the canonical service path.
        $result = $this->enrollment->confirmRegistration($parentId);
        $this->assertTrue($result);

        $childAfter = $this->repo->findByParent($parentId)[0];
        $this->assertSame(RegistrationStatus::Confirmed->value, $childAfter->status, 'child status propagated');
        $this->assertTrue($this->userHasLdAccess($courseA), 'LD access granted by cascade on confirm');
    }

    /** @test */
    public function confirmingEditionOnlyRegistrationDoesNotInvokeCascade(): void
    {
        // Direct edition enrollment with completion requirements would land
        // in Pending. We seed one manually so we can confirm it and check
        // the trajectory listener stays out.
        $edition = $this->createTestEdition();
        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => RegistrationStatus::Pending->value,
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        $unrelated = [[
            'course_id' => 9999,
            'trajectory_id' => 8888,
            'parent_registration_id' => 7777,
            'granted_at' => '2026-01-01 00:00:00',
        ]];
        update_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, $unrelated);

        $this->enrollment->confirmRegistration($regId);

        $this->assertSame($unrelated, $this->readMeta(), 'edition-only confirm does not trigger trajectory cascade');
    }

    // === Helpers ===

    /**
     * @param array<array<string, mixed>> $courses
     */
    private function createOpenTrajectory(TrajectoryMode $mode, array $courses): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'EnrollmentService wiring trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createOpenTrajectory failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, [
            'mode' => $mode->value,
            'status' => OfferingStatus::Open->value,
            'capacity' => 0,
            'courses' => $courses,
        ]);

        return $trajectoryId;
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
