<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Integration tests for Stap 12: backfill of pre-cascade trajectory enrollments.
 *
 * Covers TrajectoryCascadeService::backfillParent() — the per-parent unit of
 * work used by `wp stride trajectory backfill-cascade`.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter TrajectoryCascadeBackfill
 */
final class TrajectoryCascadeBackfillTest extends IntegrationTestCase
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
    public function backfillCreatesMissingMandatoryChildren(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        // Simulate a pre-cascade parent: it exists but never got children.
        $parentId = $this->createOrphanedParent($trajectoryId);

        $report = $this->cascade->backfillParent($parentId);

        $this->assertSame(0, $report['children_before']);
        $this->assertSame(1, $report['children_after']);
        $this->assertNull($report['error']);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $this->assertSame($editionA, (int) $children[0]->edition_id);
    }

    /** @test */
    public function backfillReplaysSelectionsJsonIntoChildren(): void
    {
        $electiveA = $this->createTestEdition();
        $electiveB = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([]);

        // Pre-cascade parent picked two electives — selections JSON has them
        // but no child rows exist.
        $parentId = $this->createOrphanedParent($trajectoryId, [$electiveA, $electiveB]);

        $report = $this->cascade->backfillParent($parentId);

        $this->assertSame(2, $report['children_after']);
        $editionIds = array_map(fn($c) => (int) $c->edition_id, $this->repo->findByParent($parentId));
        sort($editionIds);
        $expected = [$electiveA, $electiveB];
        sort($expected);
        $this->assertSame($expected, $editionIds);
    }

    /** @test */
    public function backfillMaterialisesPureLdGrantsToUserMeta(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->createOrphanedParent($trajectoryId);

        $this->cascade->backfillParent($parentId);

        $meta = get_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, true);
        $this->assertIsArray($meta);
        $this->assertCount(1, $meta);
        $this->assertSame($onlineCourse, (int) $meta[0]['course_id']);
        $this->assertSame($parentId, (int) $meta[0]['parent_registration_id']);
    }

    /** @test */
    public function backfillIsIdempotent(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $electiveB = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->createOrphanedParent($trajectoryId, [$electiveB]);

        $first = $this->cascade->backfillParent($parentId);
        $second = $this->cascade->backfillParent($parentId);

        $this->assertSame(0, $first['children_before']);
        $this->assertSame(2, $first['children_after']);
        $this->assertSame(2, $second['children_before']);
        $this->assertSame(2, $second['children_after'], 're-running backfill must not duplicate children');
    }

    /** @test */
    public function backfillReportsCapacityErrorButDoesNotAbortMandatoryChildren(): void
    {
        $courseRequired = $this->createTestCourse();
        $editionRequired = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseRequired]]);
        // Make this elective FULL — capacity=1 with a confirmed throwaway registration.
        $fullElective = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 1]]);
        $this->fillEdition($fullElective);

        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $courseRequired, 'edition_id' => $editionRequired, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->createOrphanedParent($trajectoryId, [$fullElective]);

        $report = $this->cascade->backfillParent($parentId);

        // Mandatory child created; capacity error reported but not fatal.
        $this->assertNotNull($report['error']);
        $this->assertStringContainsString('edition_full', $report['error']);
        $editionIds = array_map(fn($c) => (int) $c->edition_id, $this->repo->findByParent($parentId));
        $this->assertContains($editionRequired, $editionIds);
        $this->assertNotContains($fullElective, $editionIds);
    }

    /** @test */
    public function backfillSkipsNonTrajectoryRows(): void
    {
        $edition = $this->createTestEdition();
        $directReg = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->assertIsInt($directReg);
        $this->createdRegistrationIds[] = $directReg;

        $report = $this->cascade->backfillParent($directReg);

        $this->assertSame('not_a_trajectory_parent', $report['error']);
        $this->assertSame(0, $report['children_after']);
    }

    /** @test */
    public function backfillCoexistsWithLegacyTrajectoryIdRows(): void
    {
        // Legacy: a pre-cascade trajectory enrollment somehow ALREADY had an
        // edition row materialised the old way (trajectory_id set on the
        // edition row, no parent_registration_id). The user_id+edition_id
        // uniqueness in create() will block the new cascade child for that
        // edition — backfill should report "user already on edition" via the
        // existing-row skip in createChildRegistration and continue.
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);

        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->createOrphanedParent($trajectoryId);

        // Pre-existing legacy row: trajectory_id set on the edition row.
        $legacyEditionRow = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionA,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);
        $this->createdRegistrationIds[] = $legacyEditionRow;

        $report = $this->cascade->backfillParent($parentId);

        // No new cascade child added — the legacy row blocks creation. That's
        // acceptable: findEditionsByTrajectory (Stap 11) finds both shapes.
        $this->assertSame(0, $report['children_after'], 'legacy row blocks new child creation; that is OK because read path is shape-agnostic');
    }

    // === Helpers ===

    /**
     * @param array<array<string, mixed>> $courses
     */
    private function createTrajectoryWithCourses(array $courses): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Backfill trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createTrajectoryWithCourses failed: ' . $trajectoryId->get_error_message());
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

    /**
     * A pre-cascade parent: trajectory enrollment with optional selections JSON
     * but no child rows yet — exactly the shape backfill is meant to fix.
     *
     * @param array<int> $selections
     */
    private function createOrphanedParent(int $trajectoryId, array $selections = []): int
    {
        $data = [
            'user_id' => self::$testUserId,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ];
        if (!empty($selections)) {
            $data['selections'] = $selections;
        }

        $parentId = $this->repo->create($data);
        if (is_wp_error($parentId)) {
            $this->fail('createOrphanedParent failed: ' . $parentId->get_error_message());
        }
        $this->createdRegistrationIds[] = $parentId;
        return $parentId;
    }

    private function fillEdition(int $editionId): void
    {
        $username = 'backfill_filler_' . wp_generate_password(8, false);
        $userId = wp_create_user($username, 'pw', $username . '@test.local');
        $regId = $this->repo->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Confirmed->value,
        ]);
        $this->createdRegistrationIds[] = $regId;
    }
}
