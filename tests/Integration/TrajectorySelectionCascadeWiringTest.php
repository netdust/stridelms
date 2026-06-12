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
use Stride\Modules\Trajectory\TrajectorySelection;

/**
 * Integration tests for Stap 9: cascade wired into TrajectorySelection.
 *
 * Tests the wiring, not the cascade itself (covered in
 * TrajectoryCascade{Enrollment,Selection,Cancellation,StatusChange}Test).
 * Confirms enroll() triggers cascadeOnEnrollment and setSelections()
 * triggers cascadeOnSelection through the public surface used by
 * controllers/handlers.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter TrajectorySelectionCascadeWiring
 */
final class TrajectorySelectionCascadeWiringTest extends IntegrationTestCase
{
    private TrajectorySelection $selection;
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
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
    public function enrollMaterialisesMandatoryChildren(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->assertIsInt($registrationId);
        $this->createdRegistrationIds[] = $registrationId;

        $children = $this->repo->findByParent($registrationId);
        $this->assertCount(1, $children, 'cascadeOnEnrollment ran from inside enroll()');
        $this->assertSame($editionA, (int) $children[0]->edition_id);
    }

    /** @test */
    public function enrollMaterialisesPureLdGrantsToUserMeta(): void
    {
        $onlineCourse = $this->createTestCourse();
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 1],
        ]);

        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->assertIsInt($registrationId);
        $this->createdRegistrationIds[] = $registrationId;

        $entries = $this->readMeta();
        $this->assertCount(1, $entries);
        $this->assertSame($onlineCourse, (int) $entries[0]['course_id']);
        $this->assertSame($registrationId, (int) $entries[0]['parent_registration_id']);
    }

    /** @test */
    public function setSelectionsReachesCascadeWhenValidationPasses(): void
    {
        // No elective groups → validateSelections() short-circuits to true,
        // so setSelections() reaches the cascade. The cascade itself is
        // covered by TrajectoryCascadeSelectionTest; here we only confirm
        // the wiring fires.
        $trajectoryId = $this->createOpenTrajectory(
            [],
            ['choice_available_date' => date('Y-m-d', strtotime('-1 day'))]
        );
        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $registrationId;

        $extraEdition = $this->createTestEdition();
        $result = $this->selection->setSelections($registrationId, [$extraEdition]);

        $this->assertTrue($result);
        $children = $this->repo->findByParent($registrationId);
        $this->assertCount(1, $children, 'cascadeOnSelection ran during setSelections()');
        $this->assertSame($extraEdition, (int) $children[0]->edition_id);
    }

    /** @test */
    public function setSelectionsSuppressesChoicesEventWhenCascadeReturnsError(): void
    {
        // Configure cascade to fail by selecting a full edition. Trajectory
        // has no elective groups → validation passes → cascade runs → full
        // edition trips edition_full.
        $fullEdition = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 1]]);
        $this->fillEdition($fullEdition);

        $trajectoryId = $this->createOpenTrajectory(
            [],
            ['choice_available_date' => date('Y-m-d', strtotime('-1 day'))]
        );
        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $registrationId;

        $eventFired = false;
        $handler = function () use (&$eventFired) { $eventFired = true; };
        add_action('stride/trajectory/choices_updated', $handler);

        $result = $this->selection->setSelections($registrationId, [$fullEdition]);

        remove_action('stride/trajectory/choices_updated', $handler);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('edition_full', $result->get_error_code());
        $this->assertFalse($eventFired, 'choices_updated must not fire when cascade returned WP_Error');
    }

    /** @test */
    public function setSelectionsRemovingEditionCancelsItsChild(): void
    {
        $trajectoryId = $this->createOpenTrajectory(
            [],
            ['choice_available_date' => date('Y-m-d', strtotime('-1 day'))]
        );
        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->createdRegistrationIds[] = $registrationId;

        $editionA = $this->createTestEdition();
        $this->selection->setSelections($registrationId, [$editionA]);
        $this->assertCount(1, $this->repo->findByParent($registrationId));

        // Remove it.
        $this->selection->setSelections($registrationId, []);

        $children = $this->repo->findByParent($registrationId);
        $this->assertCount(1, $children, 'cancelled row persists');
        $this->assertSame(RegistrationStatus::Cancelled->value, $children[0]->status);
    }

    // === Helpers ===

    /**
     * @param array<array<string, mixed>> $courses
     * @param array<string, mixed> $extraMeta
     */
    private function createOpenTrajectory(array $courses, array $extraMeta = []): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Wiring trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createOpenTrajectory failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $meta = array_merge([
            'mode' => TrajectoryMode::Cohort->value,
            'status' => OfferingStatus::Open->value,
            'capacity' => 0, // unlimited
            'courses' => $courses,
        ], $extraMeta);
        $model->update($trajectoryId, $meta);

        return $trajectoryId;
    }

    /**
     * Fill an edition by creating a confirmed registration for a throwaway user.
     */
    private function fillEdition(int $editionId): void
    {
        $username = 'wiring_filler_' . wp_generate_password(8, false);
        $userId = wp_create_user($username, 'pw', $username . '@test.local');
        if (is_wp_error($userId)) {
            $this->fail('fillEdition wp_create_user: ' . $userId->get_error_message());
        }
        $regId = $this->repo->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Confirmed->value,
        ]);
        if (is_wp_error($regId)) {
            $this->fail('fillEdition repo->create: ' . $regId->get_error_message());
        }
        $this->createdRegistrationIds[] = $regId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readMeta(): array
    {
        $raw = get_user_meta(self::$testUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, true);
        return is_array($raw) ? array_values($raw) : [];
    }
}
