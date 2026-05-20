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
 * Integration tests for cascadeOnStatusChange() — enrollment-status
 * propagation from a cohort parent to its child registrations.
 *
 * Covers Stap 8 of plans/2026-05-20-trajectory-cascade-enrollment.md:
 *  - Cohort: Pending→Confirmed propagates to active children + grants LD.
 *  - Cohort: Confirmed→Pending propagates + revokes LD (access lost).
 *  - Terminal children (Cancelled / Completed) are skipped.
 *  - Self-paced: no-op.
 *  - Cancelled status routes via cascadeOnCancellation, not here.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter TrajectoryCascadeStatusChange
 */
final class TrajectoryCascadeStatusChangeTest extends IntegrationTestCase
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

        parent::tearDown();
    }

    /** @test */
    public function cohortPendingToConfirmedPropagatesAndGrantsLd(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);

        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId, RegistrationStatus::Pending);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);

        // Child inherits parent's Pending status — no LD access yet.
        $childBefore = $this->repo->findByParent($parentId)[0];
        $this->assertSame(RegistrationStatus::Pending->value, $childBefore->status);
        $this->assertFalse($this->userHasLdAccess($courseA), 'Pending children have no LD access');

        // Admin approves the trajectory parent: Pending → Confirmed.
        $this->repo->updateStatus($parentId, RegistrationStatus::Confirmed);
        $this->cascade->cascadeOnStatusChange($parentId, RegistrationStatus::Confirmed->value);

        $childAfter = $this->repo->findByParent($parentId)[0];
        $this->assertSame(RegistrationStatus::Confirmed->value, $childAfter->status);
        $this->assertTrue($this->userHasLdAccess($courseA), 'cascade grants LD when child becomes Confirmed');
    }

    /** @test */
    public function cohortConfirmedToPendingPropagatesAndRevokesLd(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);

        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId, RegistrationStatus::Confirmed);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);
        $this->assertTrue($this->userHasLdAccess($courseA));

        // Demotion: Confirmed → Pending (rare but defined).
        $this->repo->updateStatus($parentId, RegistrationStatus::Pending);
        $this->cascade->cascadeOnStatusChange($parentId, RegistrationStatus::Pending->value);

        $children = $this->repo->findByParent($parentId);
        $this->assertSame(RegistrationStatus::Pending->value, $children[0]->status);
        $this->assertFalse($this->userHasLdAccess($courseA), 'LD revoked when child loses access-bearing status');
    }

    /** @test */
    public function terminalChildrenAreNotTouched(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $courseB = $this->createTestCourse();
        $editionB = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseB]]);

        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId, RegistrationStatus::Pending);

        $this->cascade->cascadeOnSelection($parentId, [$editionA, $editionB]);

        // Manually mark editionB child as Completed (e.g. attendance auto-completes).
        $childB = $this->childForEdition($parentId, $editionB);
        $this->repo->updateStatus((int) $childB->id, RegistrationStatus::Completed);

        // Parent gets approved.
        $this->cascade->cascadeOnStatusChange($parentId, RegistrationStatus::Confirmed->value);

        $childA = $this->childForEdition($parentId, $editionA);
        $childBReread = $this->childForEdition($parentId, $editionB);

        $this->assertSame(RegistrationStatus::Confirmed->value, $childA->status, 'non-terminal child inherits');
        $this->assertSame(RegistrationStatus::Completed->value, $childBReread->status, 'completed child untouched');
    }

    /** @test */
    public function cancelledChildrenAreNotResurrected(): void
    {
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();

        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId, RegistrationStatus::Pending);

        $this->cascade->cascadeOnSelection($parentId, [$editionA, $editionB]);

        // User drops editionB before approval; editionA stays.
        $this->cascade->cascadeOnSelection($parentId, [$editionA]);
        $childB = $this->childForEdition($parentId, $editionB);
        $this->assertSame(RegistrationStatus::Cancelled->value, $childB->status);

        // Now admin approves the parent.
        $this->cascade->cascadeOnStatusChange($parentId, RegistrationStatus::Confirmed->value);

        $childBReread = $this->childForEdition($parentId, $editionB);
        $this->assertSame(RegistrationStatus::Cancelled->value, $childBReread->status, 'cancelled child stays cancelled');
    }

    /** @test */
    public function selfPacedIsNoOp(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);

        $trajectoryId = $this->createTrajectory(TrajectoryMode::SelfPaced);
        $parentId = $this->createParentRegistration($trajectoryId, RegistrationStatus::Confirmed);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);

        // Demote parent — should NOT affect self-paced children.
        $this->cascade->cascadeOnStatusChange($parentId, RegistrationStatus::Pending->value);

        $children = $this->repo->findByParent($parentId);
        $this->assertSame(RegistrationStatus::Confirmed->value, $children[0]->status, 'self-paced child untouched');
        $this->assertTrue($this->userHasLdAccess($courseA));
    }

    /** @test */
    public function passingCancelledIsIgnoredWithWarningLog(): void
    {
        $editionA = $this->createTestEdition();
        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId, RegistrationStatus::Confirmed);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);

        // Wrong call — but cascade should not mutate; cascadeOnCancellation
        // is the correct entry point.
        $this->cascade->cascadeOnStatusChange($parentId, RegistrationStatus::Cancelled->value);

        $children = $this->repo->findByParent($parentId);
        $this->assertSame(RegistrationStatus::Confirmed->value, $children[0]->status);
    }

    /** @test */
    public function noOpOnUnknownStatus(): void
    {
        $editionA = $this->createTestEdition();
        $trajectoryId = $this->createTrajectory(TrajectoryMode::Cohort);
        $parentId = $this->createParentRegistration($trajectoryId, RegistrationStatus::Confirmed);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);

        $this->cascade->cascadeOnStatusChange($parentId, 'not_a_real_status');

        $children = $this->repo->findByParent($parentId);
        $this->assertSame(RegistrationStatus::Confirmed->value, $children[0]->status);
    }

    // === Helpers ===

    private function createTrajectory(TrajectoryMode $mode): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Status cascade trajectory ' . wp_generate_password(6, false),
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

    private function createParentRegistration(int $trajectoryId, RegistrationStatus $status): int
    {
        $id = $this->repo->create([
            'user_id' => self::$testUserId,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
            'status' => $status->value,
        ]);
        if (is_wp_error($id)) {
            $this->fail('createParentRegistration failed: ' . $id->get_error_message());
        }
        $this->createdRegistrationIds[] = $id;
        return $id;
    }

    private function childForEdition(int $parentId, int $editionId): object
    {
        foreach ($this->repo->findByParent($parentId) as $child) {
            if ((int) $child->edition_id === $editionId) {
                return $child;
            }
        }
        $this->fail("No child found for edition {$editionId}");
    }

    private function userHasLdAccess(int $courseId): bool
    {
        if (!function_exists('sfwd_lms_has_access')) {
            return false;
        }
        return (bool) sfwd_lms_has_access($courseId, self::$testUserId);
    }
}
