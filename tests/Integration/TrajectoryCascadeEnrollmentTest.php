<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Integration tests for cascadeOnEnrollment() — mandatory editions path.
 *
 * Covers Stap 4 of plans/2026-05-20-trajectory-cascade-enrollment.md:
 *  - Each required course of type=edition produces a child registration.
 *  - Children inherit parent.user_id / company_id / status / enrolled_by.
 *  - Pure-LD courses (type=online) are skipped (Stap 5 will handle them).
 *  - Idempotent: second call doesn't create duplicate children.
 *  - User already on an edition via another path → skip, don't promote.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter TrajectoryCascadeEnrollment
 */
final class TrajectoryCascadeEnrollmentTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private TrajectoryCascadeService $cascade;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    /** @var array<int> */
    private array $createdUserIds = [];

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

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        parent::tearDown();
    }

    /** @test */
    public function mandatoryEditionsProduceChildRegistrations(): void
    {
        $courseA = $this->createTestCourse();
        $courseB = $this->createTestCourse();
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
            ['type' => 'edition', 'course_id' => $courseB, 'edition_id' => $editionB, 'required' => true, 'order' => 2],
        ]);

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(2, $children);

        $editionIds = array_map(fn($c) => (int) $c->edition_id, $children);
        sort($editionIds);
        $expected = [$editionA, $editionB];
        sort($expected);
        $this->assertSame($expected, $editionIds);

        foreach ($children as $child) {
            $this->assertSame(self::$testUserId, (int) $child->user_id);
            $this->assertSame($parentId, (int) $child->parent_registration_id);
            $this->assertNull($child->trajectory_id);
            $this->assertSame(RegistrationRepository::PATH_TRAJECTORY, $child->enrollment_path);
            $this->assertSame(RegistrationStatus::Confirmed->value, $child->status);
        }
    }

    /** @test */
    public function childInheritsParentStatusAndCompany(): void
    {
        $course = $this->createTestCourse();
        $edition = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $course, 'edition_id' => $edition, 'required' => true, 'order' => 1],
        ]);

        $companyId = 4242;
        $parentId = $this->createParentRegistration(
            $trajectoryId,
            self::$testUserId,
            ['status' => RegistrationStatus::Pending->value, 'company_id' => $companyId]
        );

        $this->cascade->cascadeOnEnrollment($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $child = $children[0];
        $this->assertSame(RegistrationStatus::Pending->value, $child->status);
        $this->assertSame($companyId, (int) $child->company_id);
    }

    /** @test */
    public function pureLdCoursesDoNotProduceChildRows(): void
    {
        $course = $this->createTestCourse();
        $onlineCourse = $this->createTestCourse();
        $edition = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $course, 'edition_id' => $edition, 'required' => true, 'order' => 1],
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 2],
        ]);

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children, 'pure-LD course is recorded in user-meta, not as a child row');
        $this->assertSame($edition, (int) $children[0]->edition_id);
    }

    /** @test */
    public function pureLdCourseAppendsToUserMeta(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);

        $entries = $this->readMeta();
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame($onlineCourse, (int) $entry['course_id']);
        $this->assertSame($trajectoryId, (int) $entry['trajectory_id']);
        $this->assertSame($parentId, (int) $entry['parent_registration_id']);
        $this->assertNotEmpty($entry['granted_at']);
    }

    /** @test */
    public function pureLdMetaAppendIsIdempotentPerParent(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);
        $this->cascade->cascadeOnEnrollment($parentId);

        $entries = $this->readMeta();
        $this->assertCount(1, $entries, 'second cascade call must not duplicate the meta entry');
    }

    /** @test */
    public function pureLdMetaPreservesEntriesFromOtherParents(): void
    {
        // Pre-existing entry from an unrelated trajectory enrollment.
        $unrelatedEntry = [
            'course_id' => 9999,
            'trajectory_id' => 8888,
            'parent_registration_id' => 7777,
            'granted_at' => '2026-01-01 00:00:00',
        ];
        update_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, [$unrelatedEntry]);

        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);

        $entries = $this->readMeta();
        $this->assertCount(2, $entries);
        $this->assertSame(9999, (int) $entries[0]['course_id']);
        $this->assertSame($onlineCourse, (int) $entries[1]['course_id']);
    }

    /** @test */
    public function pureLdCourseWithoutCourseIdIsSkipped(): void
    {
        // type=online but with course_id=0 — should not crash and should not
        // record a phantom meta entry.
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Bad config trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $trajectoryId;
        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, ['courses' => [
            ['type' => 'online', 'course_id' => 0, 'required' => true, 'order' => 1],
        ]]);

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);

        $this->assertSame([], $this->readMeta());
        $this->assertSame([], $this->repo->findByParent($parentId));
    }

    /** @test */
    public function electivesAreSkipped(): void
    {
        $courseRequired = $this->createTestCourse();
        $courseElective = $this->createTestCourse();
        $editionRequired = $this->createTestEdition();
        $editionElective = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $courseRequired, 'edition_id' => $editionRequired, 'required' => true, 'order' => 1],
            ['type' => 'edition', 'course_id' => $courseElective, 'edition_id' => $editionElective, 'required' => false, 'group' => 'Keuze A', 'order' => 2],
        ]);

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children, 'electives are handled by cascadeOnSelection, not here');
        $this->assertSame($editionRequired, (int) $children[0]->edition_id);
    }

    /** @test */
    public function isIdempotent(): void
    {
        $course = $this->createTestCourse();
        $edition = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $course, 'edition_id' => $edition, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);
        $this->cascade->cascadeOnEnrollment($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children, 'second cascade call must not duplicate the child');
    }

    /** @test */
    public function skipsWhenUserAlreadyHasRegistrationForEdition(): void
    {
        $course = $this->createTestCourse();
        $edition = $this->createTestEdition();
        $trajectoryId = $this->createTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $course, 'edition_id' => $edition, 'required' => true, 'order' => 1],
        ]);

        // Pre-existing direct enrollment on the same edition.
        $directReg = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($directReg);
        $this->createdRegistrationIds[] = $directReg;

        $parentId = $this->createParentRegistration($trajectoryId, self::$testUserId);

        $this->cascade->cascadeOnEnrollment($parentId);

        $children = $this->repo->findByParent($parentId);
        $this->assertSame([], $children, 'cascade must not promote an existing direct enrollment to a child');

        // The original direct enrollment is untouched.
        $direct = $this->repo->find($directReg);
        $this->assertNull($direct->parent_registration_id);
        $this->assertSame(RegistrationRepository::PATH_INDIVIDUAL, $direct->enrollment_path);
    }

    /** @test */
    public function isNoOpWhenParentIsNotATrajectoryRegistration(): void
    {
        $edition = $this->createTestEdition();
        $orphan = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
        ]);
        $this->assertIsInt($orphan);
        $this->createdRegistrationIds[] = $orphan;

        $this->cascade->cascadeOnEnrollment($orphan);

        $this->assertSame([], $this->repo->findByParent($orphan));
    }

    /**
     * @param array<array<string, mixed>> $courses
     */
    private function createTrajectoryWithCourses(array $courses): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Cascade test trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createTrajectoryWithCourses failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $result = $model->update($trajectoryId, ['courses' => $courses]);
        if (is_wp_error($result)) {
            $this->fail('failed to set courses meta: ' . $result->get_error_message());
        }

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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createParentRegistration(int $trajectoryId, int $userId, array $overrides = []): int
    {
        $data = array_merge([
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ], $overrides);

        $id = $this->repo->create($data);
        if (is_wp_error($id)) {
            $this->fail('createParentRegistration failed: ' . $id->get_error_message());
        }
        $this->createdRegistrationIds[] = $id;
        return $id;
    }
}
