<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectorySelection;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Tests\TestCase;
use WP_Error;

/**
 * Unit tests for TrajectorySelection::validateSelections().
 *
 * The validator consumes TrajectoryRepository::getElectiveGroups(), whose
 * shape is array<array{name: string, required: int, courses: array<WP_Post>}>
 * with each course post carrying ->trajectory_config (course_id, edition_id,
 * group, ...). Selections are EDITION ids — validation must count the chosen
 * ids against each group's configured edition ids, not its course post ids.
 */
class TrajectorySelectionValidationTest extends TestCase
{
    private TrajectoryService|MockInterface $trajectories;
    private TrajectoryRepository|MockInterface $trajectoryRepo;
    private RegistrationRepository|MockInterface $registrations;
    private TrajectoryCascadeService|MockInterface $cascade;
    private TrajectorySelection $selection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trajectories = Mockery::mock(TrajectoryService::class);
        $this->trajectoryRepo = Mockery::mock(TrajectoryRepository::class);
        $this->registrations = Mockery::mock(RegistrationRepository::class);
        $this->cascade = Mockery::mock(TrajectoryCascadeService::class);

        $this->selection = new TrajectorySelection(
            $this->trajectories,
            $this->trajectoryRepo,
            $this->registrations,
            $this->cascade,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function acceptsSelectionMeetingGroupRequirement(): void
    {
        $this->stubElectiveGroups([
            $this->group('Verdieping', 1, [[101, 201], [102, 202]]),
        ]);

        $result = $this->selection->validateSelections(55, [201]);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function rejectsTooFewSelectionsNamingTheGroup(): void
    {
        $this->stubElectiveGroups([
            $this->group('Verdieping', 2, [[101, 201], [102, 202], [103, 203]]),
        ]);

        $result = $this->selection->validateSelections(55, [201]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('incomplete_choices', $result->get_error_code());
        $this->assertStringContainsString('Verdieping', $result->get_error_message());
    }

    /**
     * @test
     */
    public function rejectsTooManySelections(): void
    {
        $this->stubElectiveGroups([
            $this->group('Verdieping', 1, [[101, 201], [102, 202]]),
        ]);

        $result = $this->selection->validateSelections(55, [201, 202]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('too_many_choices', $result->get_error_code());
    }

    /**
     * @test
     */
    public function validatesEachGroupIndependently(): void
    {
        $this->stubElectiveGroups([
            $this->group('Basis', 1, [[101, 201], [102, 202]]),
            $this->group('Verdieping', 1, [[103, 203], [104, 204]]),
        ]);

        // Satisfies "Basis" but picks nothing from "Verdieping".
        $result = $this->selection->validateSelections(55, [201]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('incomplete_choices', $result->get_error_code());
        $this->assertStringContainsString('Verdieping', $result->get_error_message());
    }

    /**
     * @test
     *
     * Pure-LD electives carry no edition_id and are not selectable today
     * (deferred to the phased-choices feature). A group with no
     * edition-backed entries must not block an otherwise valid selection.
     */
    public function skipsGroupsWithoutEditionBackedCourses(): void
    {
        $this->stubElectiveGroups([
            $this->group('Online keuze', 1, [[101, 0], [102, 0]]),
        ]);

        $result = $this->selection->validateSelections(55, []);

        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * Admin data with min_choices left at 0 keeps the historic default of
     * "pick exactly one".
     */
    public function treatsZeroRequiredAsPickOne(): void
    {
        $this->stubElectiveGroups([
            $this->group('Verdieping', 0, [[101, 201], [102, 202]]),
        ]);

        $this->assertTrue($this->selection->validateSelections(55, [202]));

        $empty = $this->selection->validateSelections(55, []);
        $this->assertInstanceOf(WP_Error::class, $empty);
        $this->assertSame('incomplete_choices', $empty->get_error_code());
    }

    // === Helpers ===

    private function stubElectiveGroups(array $groups): void
    {
        $this->trajectoryRepo
            ->shouldReceive('getElectiveGroups')
            ->with(55)
            ->andReturn($groups);
    }

    /**
     * Build a group struct exactly as TrajectoryRepository::getElectiveGroups
     * returns it: name + required + WP_Post courses with trajectory_config.
     *
     * @param array<array{0: int, 1: int}> $coursePairs [courseId, editionId]
     */
    private function group(string $name, int $required, array $coursePairs): array
    {
        $courses = [];
        foreach ($coursePairs as [$courseId, $editionId]) {
            $post = new \WP_Post([
                'ID' => $courseId,
                'post_type' => 'sfwd-courses',
                'post_status' => 'publish',
                'post_title' => 'Course ' . $courseId,
            ]);
            $post->trajectory_config = [
                'course_id' => $courseId,
                'edition_id' => $editionId,
                'required' => false,
                'group' => $name,
            ];
            $courses[] = $post;
        }

        return [
            'name' => $name,
            'required' => $required,
            'courses' => $courses,
        ];
    }
}
