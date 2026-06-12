<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Trajectory;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectorySelection;

/**
 * Integration test: initial_selection snapshot is captured on trajectory enrollment.
 *
 * Run: ddev exec vendor/bin/phpunit --filter TrajectoryInitialSelectionTest --testsuite Integration
 */
final class TrajectoryInitialSelectionTest extends IntegrationTestCase
{
    private TrajectorySelection $selection;
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->selection = ntdst_get(TrajectorySelection::class);
        $this->repo      = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $id) {
            $this->deleteTestRegistration($id);
        }
        $this->createdRegistrationIds = [];
        parent::tearDown();
    }

    /** @test */
    public function trajectoryEnrollmentCapturesEnrollmentPhase(): void
    {
        // Create a trajectory with status=open (required for isEnrollmentOpen).
        $trajectoryId = $this->createOpenTrajectory();

        wp_set_current_user(self::$testUserId);

        $regId = $this->selection->enroll(self::$testUserId, $trajectoryId);

        if (is_wp_error($regId)) {
            $this->fail('enroll() returned WP_Error: ' . $regId->get_error_message());
        }
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        $row = $this->repo->find($regId);
        $initial = $row->enrollment_data['initial_selection'] ?? null;

        $this->assertNotNull($initial, 'initial_selection should be captured on trajectory enroll');
        $this->assertSame('trajectory', $initial['type']);
        $this->assertNotEmpty($initial['phases']);
        $this->assertSame('enrollment', $initial['phases'][0]['phase']);
        $this->assertSame(self::$testUserId, $initial['phases'][0]['captured_by']);
        // edition_ids key present even if empty (no mandatory editions configured in fixture)
        $this->assertArrayHasKey('edition_ids', $initial['phases'][0]);
    }

    /** @test */
    public function trajectoryEnrollmentWithMandatoryEditionCapturesItsId(): void
    {
        $editionId    = $this->createTestEdition();
        $courseId     = $this->createTestCourse();
        $trajectoryId = $this->createOpenTrajectoryWithCourses([
            ['type' => 'edition', 'course_id' => $courseId, 'edition_id' => $editionId, 'required' => true, 'order' => 1],
        ]);

        wp_set_current_user(self::$testUserId);

        $regId = $this->selection->enroll(self::$testUserId, $trajectoryId);

        if (is_wp_error($regId)) {
            $this->fail('enroll() returned WP_Error: ' . $regId->get_error_message());
        }
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        // Also collect any child registrations for cleanup.
        $children = $this->repo->findByParent($regId);
        foreach ($children as $child) {
            $this->createdRegistrationIds[] = (int) $child->id;
        }

        $row     = $this->repo->find($regId);
        $initial = $row->enrollment_data['initial_selection'] ?? null;

        $this->assertNotNull($initial);
        $this->assertSame('trajectory', $initial['type']);
        $this->assertSame([$editionId], $initial['phases'][0]['edition_ids']);
    }

    /** @test */
    public function setSelectionsAppendsNewPhase(): void
    {
        // Requires a trajectory with electives, open choice window, and capacity-checked editions.
        // That fixture is non-trivial; the acceptance test in Task 16 is the real check.
        // Here we document the behavior via skip + note.
        $this->markTestSkipped('Append-only setSelections coverage lives in Task 16 acceptance.');
    }

    // === Fixture helpers ===

    private function createOpenTrajectory(): int
    {
        return $this->createOpenTrajectoryWithCourses([]);
    }

    /**
     * @param array<array<string, mixed>> $courses
     */
    private function createOpenTrajectoryWithCourses(array $courses): int
    {
        $trajectoryId = wp_insert_post([
            'post_type'  => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Initial-selection test trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);

        if (is_wp_error($trajectoryId)) {
            $this->fail('wp_insert_post failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, [
            'status'   => 'open',
            'capacity' => 0,
            'courses'  => $courses,
        ]);

        return $trajectoryId;
    }
}
