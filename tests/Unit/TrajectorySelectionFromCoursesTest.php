<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectorySelection;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Tests\TestCase;
use WP_Error;

/**
 * Unit tests for TrajectorySelection::setSelectionsFromCourses() and
 * getSelectedCourseIds() — the course-ID entry point the keuzes form uses.
 *
 * Contract (plan 2026-06-12-trajectory-wiring):
 * - input is COURSE ids (the form's native shape)
 * - edition-backed picks map to edition ids and ride the existing
 *   setSelections write path (repo + cascade + phase entry + action)
 * - pure-LD picks (trajectory_config without edition_id) grant/revoke LD
 *   access through LMSAdapterInterface (INV-6) based on a diff against the
 *   previous picks
 * - validation: unknown course refused; per-group exact min_choices count
 *   computed over course ids so pure-LD picks count toward the group
 * - guards: window-closed and locked refuse (same single decision points
 *   setSelections uses)
 */
class TrajectorySelectionFromCoursesTest extends TestCase
{
    private const REG_ID = 77;
    private const TRAJ_ID = 55;
    private const USER_ID = 9;

    private TrajectoryService|MockInterface $trajectories;
    private TrajectoryRepository|MockInterface $trajectoryRepo;
    private RegistrationRepository|MockInterface $registrations;
    private TrajectoryCascadeService|MockInterface $cascade;
    private LMSAdapterInterface|MockInterface $lms;
    private TrajectorySelection $selection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trajectories = Mockery::mock(TrajectoryService::class);
        $this->trajectoryRepo = Mockery::mock(TrajectoryRepository::class);
        $this->registrations = Mockery::mock(RegistrationRepository::class);
        $this->cascade = Mockery::mock(TrajectoryCascadeService::class);
        $this->lms = Mockery::mock(LMSAdapterInterface::class);

        $this->selection = new TrajectorySelection(
            $this->trajectories,
            $this->trajectoryRepo,
            $this->registrations,
            $this->cascade,
            $this->lms,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * One elective group "Verdieping" (min_choices 1) with an edition-backed
     * course (101 → edition 201) and a pure-LD course (102, no edition).
     */
    private function stubMixedGroup(): void
    {
        $this->trajectoryRepo
            ->shouldReceive('getElectiveGroups')
            ->with(self::TRAJ_ID)
            ->andReturn([
                $this->group('Verdieping', 1, [[101, 201], [102, 0]]),
            ]);
    }

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
            $config = [
                'course_id' => $courseId,
                'required' => false,
                'group' => $name,
            ];
            if ($editionId > 0) {
                $config['edition_id'] = $editionId;
            }
            $post->trajectory_config = $config;
            $courses[] = $post;
        }

        return ['name' => $name, 'required' => $required, 'courses' => $courses];
    }

    private function registration(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => self::REG_ID,
            'user_id' => self::USER_ID,
            'trajectory_id' => self::TRAJ_ID,
            'edition_id' => null,
            'selections' => [],
            'enrollment_data' => [],
            'status' => 'confirmed',
        ], $overrides);
    }

    private function stubFind(object $registration): void
    {
        $this->registrations->shouldReceive('find')->with(self::REG_ID)->andReturn($registration);
    }

    private function stubGuards(bool $windowOpen = true, bool $locked = false): void
    {
        $this->trajectories->shouldReceive('isChoiceWindowOpen')->with(self::TRAJ_ID)->andReturn($windowOpen);
        $this->registrations->shouldReceive('areSelectionsLocked')->with(self::REG_ID)->andReturn($locked);
        // Concurrency guard (shake-out BUG-3): the course-level write path
        // serializes per registration via the repository's advisory lock.
        $this->registrations->shouldReceive('acquireSelectionLock')->with(self::REG_ID)->andReturn(true)->byDefault();
        $this->registrations->shouldReceive('releaseSelectionLock')->with(self::REG_ID)->byDefault();
    }

    /** Expectations for the shared write path (repo + cascade + phase + action). */
    private function expectWritePath(array $editionIds, array $courseIds): void
    {
        $this->registrations->shouldReceive('setSelections')
            ->once()->with(self::REG_ID, $editionIds)->andReturn(true);
        $this->cascade->shouldReceive('cascadeOnSelection')
            ->once()->with(self::REG_ID, $editionIds)->andReturn(true);
        $this->registrations->shouldReceive('appendInitialSelectionPhase')
            ->once()->withArgs(function (int $regId, array $phase, string $type) use ($editionIds, $courseIds) {
                return $regId === self::REG_ID
                    && $type === 'trajectory'
                    && ($phase['edition_ids'] ?? null) === $editionIds
                    && ($phase['course_ids'] ?? null) === $courseIds;
            })->andReturn(true);
    }

    // =========================================================================
    // Happy paths
    // =========================================================================

    /** @test */
    public function editionBackedPickRidesTheCascadePath(): void
    {
        $this->stubFind($this->registration());
        $this->stubGuards();
        $this->stubMixedGroup();
        $this->expectWritePath([201], [101]);

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101]);

        $this->assertTrue($result);
    }

    /** @test */
    public function pureLdPickGrantsAccessAndSkipsCascadeEditions(): void
    {
        $this->stubFind($this->registration());
        $this->stubGuards();
        $this->stubMixedGroup();
        $this->expectWritePath([], [102]);
        $this->lms->shouldReceive('grantAccess')->once()->with(self::USER_ID, 102);

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [102]);

        $this->assertTrue($result);
    }

    /** @test */
    public function switchingFromPureLdToEditionRevokesAccess(): void
    {
        // Previous pick was the pure-LD course (recorded in the phase entry).
        $this->stubFind($this->registration([
            'enrollment_data' => ['initial_selection' => ['type' => 'trajectory', 'phases' => [
                ['phase' => 'enrollment', 'edition_ids' => [], 'course_ids' => [102]],
            ]]],
        ]));
        $this->stubGuards();
        $this->stubMixedGroup();
        $this->expectWritePath([201], [101]);
        $this->lms->shouldReceive('revokeAccess')->once()->with(self::USER_ID, 102);

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101]);

        $this->assertTrue($result);
    }

    /** @test */
    public function resubmittingSamePureLdPickDoesNotRegrant(): void
    {
        $this->stubFind($this->registration([
            'enrollment_data' => ['initial_selection' => ['type' => 'trajectory', 'phases' => [
                ['phase' => 'enrollment', 'edition_ids' => [], 'course_ids' => [102]],
            ]]],
        ]));
        $this->stubGuards();
        $this->stubMixedGroup();
        $this->expectWritePath([], [102]);
        $this->lms->shouldNotReceive('grantAccess');
        $this->lms->shouldNotReceive('revokeAccess');

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [102]);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Denials (threat-model mitigations 3/4/5)
    // =========================================================================

    /** @test */
    public function unknownCourseIdIsRefusedWithoutAnyWrite(): void
    {
        $this->stubFind($this->registration());
        $this->stubGuards();
        $this->stubMixedGroup();
        $this->registrations->shouldNotReceive('setSelections');
        $this->lms->shouldNotReceive('grantAccess');

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [999]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_choice', $result->get_error_code());
    }

    /** @test */
    public function overPickingTheGroupIsRefused(): void
    {
        $this->stubFind($this->registration());
        $this->stubGuards();
        $this->stubMixedGroup();

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101, 102]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('too_many_choices', $result->get_error_code());
    }

    /** @test */
    public function underPickingTheGroupIsRefused(): void
    {
        $this->stubFind($this->registration());
        $this->stubGuards();
        $this->stubMixedGroup();

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, []);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('incomplete_choices', $result->get_error_code());
    }

    /** @test */
    public function contendedLockRefusesTheSubmission(): void
    {
        // Shake-out BUG-3: two interleaved submissions diffed against the
        // same pre-state and left LD access diverged from the recorded picks.
        // The write path serializes per registration; a contended lock refuses.
        $this->stubFind($this->registration());
        $this->trajectories->shouldReceive('isChoiceWindowOpen')->andReturn(true);
        $this->registrations->shouldReceive('areSelectionsLocked')->andReturn(false);
        $this->registrations->shouldReceive('acquireSelectionLock')->once()->with(self::REG_ID)->andReturn(false);
        $this->registrations->shouldNotReceive('setSelections');
        $this->lms->shouldNotReceive('grantAccess');

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('busy', $result->get_error_code());
    }

    /** @test */
    public function lockIsReleasedEvenWhenValidationRefuses(): void
    {
        $this->stubFind($this->registration());
        $this->trajectories->shouldReceive('isChoiceWindowOpen')->andReturn(true);
        $this->registrations->shouldReceive('areSelectionsLocked')->andReturn(false);
        $this->registrations->shouldReceive('acquireSelectionLock')->once()->with(self::REG_ID)->andReturn(true);
        $this->registrations->shouldReceive('releaseSelectionLock')->once()->with(self::REG_ID);
        $this->stubMixedGroup();

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [999]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_choice', $result->get_error_code());
    }

    /** @test */
    public function cancelledRegistrationCannotSubmitChoices(): void
    {
        // Shake-out BUG-1: a cancelled enrollee could still change choices and
        // gain LD access. Only active registrations may pick.
        $this->stubFind($this->registration(['status' => 'cancelled']));
        $this->registrations->shouldNotReceive('setSelections');
        $this->lms->shouldNotReceive('grantAccess');

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('registration_inactive', $result->get_error_code());
    }

    /** @test */
    public function completedRegistrationCannotSubmitChoices(): void
    {
        $this->stubFind($this->registration(['status' => 'completed']));

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('registration_inactive', $result->get_error_code());
    }

    /** @test */
    public function pendingRegistrationMaySubmitChoices(): void
    {
        // Pending (awaiting approval) enrollees still need to pick before the
        // window closes — only terminal/withdrawn states are refused.
        $this->stubFind($this->registration(['status' => 'pending']));
        $this->stubGuards();
        $this->stubMixedGroup();
        $this->expectWritePath([201], [101]);

        $this->assertTrue($this->selection->setSelectionsFromCourses(self::REG_ID, [101]));
    }

    /** @test */
    public function closedWindowIsRefused(): void
    {
        $this->stubFind($this->registration());
        $this->stubGuards(windowOpen: false);

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('choice_window_closed', $result->get_error_code());
    }

    /** @test */
    public function lockedSelectionsAreRefused(): void
    {
        $this->stubFind($this->registration());
        $this->stubGuards(locked: true);

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [101]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('choices_locked', $result->get_error_code());
    }

    // =========================================================================
    // getSelectedCourseIds — the single display decision point
    // =========================================================================

    /** @test */
    public function selectedCourseIdsCombineEditionBackedAndPureLdPicks(): void
    {
        $this->stubFind($this->registration([
            'selections' => [201], // edition-backed pick stored canonically
            'enrollment_data' => ['initial_selection' => ['type' => 'trajectory', 'phases' => [
                ['phase' => 'enrollment', 'edition_ids' => [], 'course_ids' => [102]],
                ['phase' => 'enrollment', 'edition_ids' => [201], 'course_ids' => [101, 102]],
            ]]],
        ]));
        $this->stubMixedGroup();

        $ids = $this->selection->getSelectedCourseIds(self::REG_ID);

        sort($ids);
        $this->assertSame([101, 102], $ids);
    }

    /** @test */
    public function legacyEditionPathRecordsDerivedCourseIdsInPhaseEntry(): void
    {
        // CR finding: setSelections (legacy, edition ids) appended phase
        // entries WITHOUT course_ids, leaving the pure-LD grant/revoke diff
        // anchored to a stale older entry. Rule: EVERY phase entry records
        // course_ids — the legacy path derives them from the catalog and
        // carries forward the current pure-LD picks it cannot express.
        $this->stubFind($this->registration([
            'enrollment_data' => ['initial_selection' => ['type' => 'trajectory', 'phases' => [
                ['phase' => 'enrollment', 'edition_ids' => [], 'course_ids' => [102]], // pure-LD pick stands
            ]]],
        ]));
        $this->stubGuards();
        $this->stubMixedGroup();

        $this->registrations->shouldReceive('setSelections')
            ->once()->with(self::REG_ID, [201])->andReturn(true);
        $this->cascade->shouldReceive('cascadeOnSelection')
            ->once()->with(self::REG_ID, [201])->andReturn(true);
        $this->registrations->shouldReceive('appendInitialSelectionPhase')
            ->once()->withArgs(function (int $regId, array $phase) {
                $courseIds = $phase['course_ids'] ?? null;
                if (!is_array($courseIds)) {
                    return false;
                }
                sort($courseIds);
                // edition 201 → course 101, plus the carried-forward pure-LD 102
                return $courseIds === [101, 102];
            })->andReturn(true);

        $result = $this->selection->setSelections(self::REG_ID, [201]);

        $this->assertTrue($result);
    }

    /** @test */
    public function failedLdGrantIsLoggedNotSwallowed(): void
    {
        // CR finding: a false return from the adapter left the phase entry
        // recording a pick the user has no LD access to, invisibly. The
        // failure must at least be observable (INV-4: logged once).
        $this->stubFind($this->registration());
        $this->stubGuards();
        $this->stubMixedGroup();
        $this->expectWritePath([], [102]);
        $this->lms->shouldReceive('grantAccess')->once()->with(self::USER_ID, 102)->andReturn(false);

        global $_test_log_entries;
        $_test_log_entries = [];

        $result = $this->selection->setSelectionsFromCourses(self::REG_ID, [102]);

        $this->assertTrue($result, 'selection itself still succeeds');
        $errors = array_filter(
            $_test_log_entries,
            fn(array $entry): bool => ($entry['level'] ?? '') === 'error',
        );
        $this->assertNotEmpty($errors, 'a failed LD grant must be logged at error level');
    }

    /** @test */
    public function groupChosenHelpersCountIntersectionAgainstRequirement(): void
    {
        $group = $this->group('Verdieping', 1, [[101, 201], [102, 0]]);

        $this->assertSame(1, $this->selection->countChosenInGroup($group, [101]));
        $this->assertSame(0, $this->selection->countChosenInGroup($group, [999]));
        $this->assertTrue($this->selection->isGroupChosen($group, [102]));
        $this->assertFalse($this->selection->isGroupChosen($group, []));

        // required=0 keeps the historic "pick exactly one" floor.
        $zeroGroup = $this->group('Optioneel', 0, [[103, 203]]);
        $this->assertFalse($this->selection->isGroupChosen($zeroGroup, []));
        $this->assertTrue($this->selection->isGroupChosen($zeroGroup, [103]));
    }

    /** @test */
    public function selectedCourseIdsEmptyForUnknownRegistration(): void
    {
        $this->registrations->shouldReceive('find')->with(self::REG_ID)->andReturn(null);

        $this->assertSame([], $this->selection->getSelectedCourseIds(self::REG_ID));
    }
}
