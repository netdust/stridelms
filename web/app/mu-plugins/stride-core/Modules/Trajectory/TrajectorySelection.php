<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Trajectory enrollment and elective selection.
 *
 * Handles user joining trajectories and picking elective editions.
 * Selections stored as JSON on registration record.
 */
final class TrajectorySelection
{
    public function __construct(
        private readonly TrajectoryService $trajectories,
        private readonly TrajectoryRepository $trajectoryRepo,
        private readonly RegistrationRepository $registrations,
        private readonly TrajectoryCascadeService $cascade,
        private readonly \Stride\Contracts\LMSAdapterInterface $lms,
    ) {}

    // === Enrollment ===

    /**
     * Enroll user in trajectory.
     */
    public function enroll(int $userId, int $trajectoryId, array $options = []): int|WP_Error
    {
        // Check trajectory allows enrollment
        if (!$this->trajectories->isEnrollmentOpen($trajectoryId)) {
            return new WP_Error('enrollment_closed', 'Enrollment is not open for this trajectory');
        }

        // Check capacity
        if (!$this->hasCapacity($trajectoryId)) {
            return new WP_Error('no_capacity', 'Trajectory is full');
        }

        // Create trajectory enrollment (edition_id = null)
        $data = [
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ];

        if (!empty($options['company_id'])) {
            $data['company_id'] = (int) $options['company_id'];
        }

        $registrationId = $this->registrations->create($data);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Materialise child registrations for the trajectory's required courses
        // (mandatory editions + pure-LD courses). Electives wait for setSelections().
        $this->cascade->cascadeOnEnrollment($registrationId);

        // Snapshot the mandatory editions chosen at enrollment time.
        $mandatoryEditionIds = $this->getMandatoryEditionIds($trajectoryId);
        $this->registrations->appendInitialSelectionPhase(
            $registrationId,
            [
                'phase'       => 'enrollment',
                'edition_ids' => $mandatoryEditionIds,
            ],
            'trajectory',
        );

        do_action('stride/trajectory/enrolled', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
        ]);

        return $registrationId;
    }

    /**
     * Check if trajectory has capacity.
     */
    public function hasCapacity(int $trajectoryId): bool
    {
        $trajectory = $this->trajectories->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        // No capacity limit
        if ($trajectory['capacity'] === 0) {
            return true;
        }

        $enrollments = $this->registrations->findByTrajectory($trajectoryId, 'confirmed');

        return count($enrollments) < $trajectory['capacity'];
    }

    // === Elective Selection ===

    /**
     * Set elective choices for enrollment.
     *
     * @param array<int> $editionIds
     */
    public function setSelections(int $registrationId, array $editionIds): true|WP_Error
    {
        $registration = $this->registrations->find($registrationId);
        if (!$registration) {
            return new WP_Error('enrollment_not_found', 'Enrollment not found');
        }

        $trajectoryId = (int) $registration->trajectory_id;

        // Only ACTIVE registrations may change choices (see setSelectionsFromCourses).
        if (!in_array((string) $registration->status, ['confirmed', 'pending'], true)) {
            return new WP_Error('registration_inactive', 'Registration is not active');
        }

        // Check choice window is open
        if (!$this->trajectories->isChoiceWindowOpen($trajectoryId)) {
            return new WP_Error('choice_window_closed', 'Choice window is not open');
        }

        // Check selections are not locked
        if ($this->registrations->areSelectionsLocked($registrationId)) {
            return new WP_Error('choices_locked', 'Choices are locked');
        }

        // Validate choices
        $validation = $this->validateSelections($trajectoryId, $editionIds);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // EVERY phase entry records course_ids (CR 2026-06-12): the pure-LD
        // grant/revoke diff reads the latest entry's course_ids, so a legacy
        // edition-id write that skipped them would leave the diff anchored to
        // a stale older entry. Derive the course-level equivalent: the picked
        // editions mapped back to their courses, plus the current pure-LD
        // picks this entry point cannot express (carried forward unchanged).
        $catalog = $this->buildElectiveCatalog($trajectoryId);
        $derivedCourseIds = [];
        $editionIdsInt = array_map('intval', $editionIds);
        foreach ($catalog['courses'] as $courseId => $mappedEditionId) {
            if ($mappedEditionId > 0 && in_array($mappedEditionId, $editionIdsInt, true)) {
                $derivedCourseIds[] = $courseId;
            }
        }
        foreach ($this->getSelectedPureLdCourseIds($registration, $catalog) as $courseId) {
            $derivedCourseIds[] = $courseId;
        }

        return $this->applySelections($registrationId, $trajectoryId, $editionIds, array_values(array_unique($derivedCourseIds)));
    }

    /**
     * Set elective choices from COURSE ids — the keuzes form's native shape.
     *
     * Maps edition-backed electives (trajectory_config.edition_id) onto the
     * existing edition-id write path (repo + cascade + phase entry + event),
     * and pure-LD electives (no edition_id) onto direct LD access through
     * the LMS adapter (INV-6), diffed against the previous picks so a
     * deselected course is revoked and an unchanged one is not re-granted.
     *
     * Validation is catalog-bound and course-level: unknown ids refuse the
     * whole submission, and each group's min_choices is counted over course
     * ids so pure-LD picks count toward the requirement.
     *
     * @param array<int> $courseIds
     */
    public function setSelectionsFromCourses(int $registrationId, array $courseIds): true|WP_Error
    {
        $registration = $this->registrations->find($registrationId);
        if (!$registration) {
            return new WP_Error('enrollment_not_found', 'Enrollment not found');
        }

        $trajectoryId = (int) $registration->trajectory_id;

        // Only ACTIVE registrations may change choices (shake-out BUG-1:
        // a cancelled enrollee could still submit and gain LD access).
        // Pending stays allowed — approval can land after the choice window.
        if (!in_array((string) $registration->status, ['confirmed', 'pending'], true)) {
            return new WP_Error('registration_inactive', 'Registration is not active');
        }

        // Same single decision points as setSelections — window, then lock.
        if (!$this->trajectories->isChoiceWindowOpen($trajectoryId)) {
            return new WP_Error('choice_window_closed', 'Choice window is not open');
        }
        if ($this->registrations->areSelectionsLocked($registrationId)) {
            return new WP_Error('choices_locked', 'Choices are locked');
        }

        // Serialize per registration (shake-out BUG-3): two interleaved
        // submissions both diffed grants against the same pre-state, leaving
        // LD access diverged from the recorded picks.
        if (!$this->registrations->acquireSelectionLock($registrationId)) {
            return new WP_Error('busy', 'Er wordt al een keuze verwerkt — probeer het opnieuw.');
        }

        try {
            return $this->applyCourseSelections($registrationId, $trajectoryId, $courseIds);
        } finally {
            $this->registrations->releaseSelectionLock($registrationId);
        }
    }

    /**
     * The locked section of setSelectionsFromCourses: re-reads the
     * registration (the grant/revoke diff base must reflect any submission
     * that held the lock before us), validates and applies.
     *
     * @param array<int> $courseIds
     */
    private function applyCourseSelections(int $registrationId, int $trajectoryId, array $courseIds): true|WP_Error
    {
        // Fresh read inside the lock.
        $registration = $this->registrations->find($registrationId);
        if (!$registration) {
            return new WP_Error('enrollment_not_found', 'Enrollment not found');
        }

        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));

        $catalog = $this->buildElectiveCatalog($trajectoryId);

        // Catalog-bound: every submitted id must be an elective of THIS
        // trajectory (threat-model mitigation 3 — no partial application).
        foreach ($courseIds as $courseId) {
            if (!array_key_exists($courseId, $catalog['courses'])) {
                return new WP_Error('invalid_choice', sprintf('Course %d is not a choice in this trajectory', $courseId));
            }
        }

        // Per-group exact min_choices count over COURSE ids (mitigation 5).
        $validation = $this->validateCourseSelections($catalog['groups'], $courseIds);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Partition into edition-backed picks and pure-LD picks.
        $editionIds = [];
        $pureLdCourseIds = [];
        foreach ($courseIds as $courseId) {
            $editionId = $catalog['courses'][$courseId];
            if ($editionId > 0) {
                $editionIds[] = $editionId;
            } else {
                $pureLdCourseIds[] = $courseId;
            }
        }

        // Previous pure-LD picks BEFORE the new phase entry overwrites the
        // record — the grant/revoke diff is computed against these.
        $previousPureLd = $this->getSelectedPureLdCourseIds($registration, $catalog);

        $applied = $this->applySelections($registrationId, $trajectoryId, $editionIds, $courseIds);
        if (is_wp_error($applied)) {
            return $applied;
        }

        // Pure-LD grant/revoke diff via the adapter only (INV-6). A false
        // return must be observable (INV-4): the phase entry already records
        // the pick, so a silent failure means the UI shows a course the user
        // cannot open (grant) or keeps access they deselected (revoke).
        $userId = (int) $registration->user_id;
        foreach (array_diff($pureLdCourseIds, $previousPureLd) as $courseId) {
            if (!$this->lms->grantAccess($userId, $courseId)) {
                ntdst_log('enrollment')->error('Pure-LD elective grant failed', [
                    'registration_id' => $registrationId,
                    'user_id' => $userId,
                    'course_id' => $courseId,
                ]);
            }
        }
        foreach (array_diff($previousPureLd, $pureLdCourseIds) as $courseId) {
            if (!$this->lms->revokeAccess($userId, $courseId)) {
                ntdst_log('enrollment')->error('Pure-LD elective revoke failed', [
                    'registration_id' => $registrationId,
                    'user_id' => $userId,
                    'course_id' => $courseId,
                ]);
            }
        }

        return true;
    }

    /**
     * Single decision point for "which elective COURSES did this registration
     * pick" — every template renders selection state through this, never by
     * re-deriving from the raw selections column (whose shape is edition ids).
     *
     * @return array<int>
     */
    public function getSelectedCourseIds(int $registrationId): array
    {
        $registration = $this->registrations->find($registrationId);
        if (!$registration || empty($registration->trajectory_id)) {
            return [];
        }

        $catalog = $this->buildElectiveCatalog((int) $registration->trajectory_id);

        // Edition-backed picks: map the canonical edition ids back to courses.
        $selections = is_array($registration->selections ?? null)
            ? array_map('intval', $registration->selections)
            : [];
        $ids = [];
        foreach ($catalog['courses'] as $courseId => $editionId) {
            if ($editionId > 0 && in_array($editionId, $selections, true)) {
                $ids[] = $courseId;
            }
        }

        // Pure-LD picks: recorded in the latest initial_selection phase entry.
        foreach ($this->getSelectedPureLdCourseIds($registration, $catalog) as $courseId) {
            $ids[] = $courseId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * How many of a group's courses are among the selected course ids.
     *
     * The one derivation templates use for per-group chosen state — pair it
     * with getSelectedCourseIds() so the threshold rule (`required` =
     * min_choices) can never drift per template.
     *
     * @param array<string, mixed> $group One getElectiveGroups() struct
     * @param array<int>           $selectedCourseIds
     */
    public function countChosenInGroup(array $group, array $selectedCourseIds): int
    {
        $groupCourseIds = array_map(
            static fn($coursePost): int => (int) $coursePost->ID,
            $group['courses'] ?? [],
        );

        return count(array_intersect($groupCourseIds, $selectedCourseIds));
    }

    /**
     * Whether a group's requirement is met by the selected course ids.
     *
     * @param array<string, mixed> $group
     * @param array<int>           $selectedCourseIds
     */
    public function isGroupChosen(array $group, array $selectedCourseIds): bool
    {
        return $this->countChosenInGroup($group, $selectedCourseIds)
            >= max(1, (int) ($group['required'] ?? 0));
    }

    /**
     * The shared write path behind both selection entry points: canonical
     * selections write → cascade reconcile → append-only phase entry →
     * choices_updated event. Never duplicated (single guard/cascade sequence).
     *
     * @param array<int> $editionIds
     * @param array<int> $courseIds The course-level picks this write
     *                              expresses — submitted directly (course
     *                              entry point) or derived (edition entry
     *                              point). ALWAYS recorded on the phase
     *                              entry; the pure-LD grant/revoke diff
     *                              depends on every entry carrying them.
     */
    private function applySelections(int $registrationId, int $trajectoryId, array $editionIds, array $courseIds): true|WP_Error
    {
        // Save selections
        $result = $this->registrations->setSelections($registrationId, $editionIds);

        if (!$result) {
            return new WP_Error('db_error', 'Failed to save choices');
        }

        // Cascade: add/remove child registrations to match the new selection.
        // The selections JSON is the user's record of what they picked; the
        // child rows are the authoritative "where they're actually enrolled."
        // A capacity failure (`edition_full`) returns early without firing
        // the choices_updated event — the selection state is inconsistent
        // with reality and the caller surfaces the error.
        $cascadeResult = $this->cascade->cascadeOnSelection($registrationId, $editionIds);
        if (is_wp_error($cascadeResult)) {
            return $cascadeResult;
        }

        // Append-only: every call records a new phase entry. The phased-choices
        // feature (future) will pass distinct phase labels; today all calls use
        // 'enrollment'.
        $this->registrations->appendInitialSelectionPhase($registrationId, [
            'phase'       => 'enrollment',
            'edition_ids' => array_values(array_map('intval', $editionIds)),
            'course_ids'  => array_values(array_map('intval', $courseIds)),
        ], 'trajectory');

        do_action('stride/trajectory/choices_updated', [
            'registration_id' => $registrationId,
            'trajectory_id' => $trajectoryId,
            'edition_ids' => $editionIds,
        ]);

        return true;
    }

    /**
     * Elective catalog for a trajectory: courseId => editionId (0 = pure-LD),
     * plus the group structs for count validation.
     *
     * @return array{courses: array<int, int>, groups: array<int, array<string, mixed>>}
     */
    private function buildElectiveCatalog(int $trajectoryId): array
    {
        $groups = $this->trajectoryRepo->getElectiveGroups($trajectoryId);

        $courses = [];
        foreach ($groups as $group) {
            foreach ($group['courses'] ?? [] as $coursePost) {
                $courseId = (int) $coursePost->ID;
                if (array_key_exists($courseId, $courses)) {
                    // Misconfiguration: a course in two elective groups would
                    // double-count toward both groups' requirements. Surface
                    // it; keep the first mapping deterministic.
                    ntdst_log('enrollment')->warning('Course appears in multiple elective groups', [
                        'trajectory_id' => $trajectoryId,
                        'course_id' => $courseId,
                    ]);
                    continue;
                }
                $config = $coursePost->trajectory_config ?? [];
                $courses[$courseId] = (int) ($config['edition_id'] ?? 0);
            }
        }

        return ['courses' => $courses, 'groups' => $groups];
    }

    /**
     * Per-group exact-count validation over COURSE ids — the course-level
     * mirror of validateSelections(), where pure-LD picks also count.
     *
     * @param array<int, array<string, mixed>> $groups
     * @param array<int>                       $courseIds
     */
    private function validateCourseSelections(array $groups, array $courseIds): true|WP_Error
    {
        foreach ($groups as $group) {
            $groupName = (string) ($group['name'] ?? 'Keuze');
            $required = max(1, (int) ($group['required'] ?? 0));

            $groupCourseIds = array_map(
                static fn($coursePost): int => (int) $coursePost->ID,
                $group['courses'] ?? [],
            );
            if ($groupCourseIds === []) {
                continue;
            }

            $chosenInGroup = count(array_intersect($courseIds, $groupCourseIds));

            if ($chosenInGroup < $required) {
                return new WP_Error(
                    'incomplete_choices',
                    sprintf('Group "%s" requires %d selection(s), got %d', $groupName, $required, $chosenInGroup),
                );
            }
            if ($chosenInGroup > $required) {
                return new WP_Error(
                    'too_many_choices',
                    sprintf('Group "%s" allows %d selection(s), got %d', $groupName, $required, $chosenInGroup),
                );
            }
        }

        return true;
    }

    /**
     * The registration's current pure-LD picks: the latest phase entry's
     * course_ids, narrowed to courses the catalog marks as pure-LD.
     *
     * @param array{courses: array<int, int>} $catalog
     * @return array<int>
     */
    private function getSelectedPureLdCourseIds(object $registration, array $catalog): array
    {
        $data = is_array($registration->enrollment_data ?? null) ? $registration->enrollment_data : [];
        $phases = $data['initial_selection']['phases'] ?? [];

        $latestCourseIds = [];
        foreach ($phases as $phase) {
            if (array_key_exists('course_ids', (array) $phase)) {
                $latestCourseIds = array_map('intval', (array) $phase['course_ids']);
            }
        }

        return array_values(array_filter(
            $latestCourseIds,
            fn(int $courseId): bool => ($catalog['courses'][$courseId] ?? -1) === 0,
        ));
    }

    /**
     * Lock elective choices.
     */
    public function lockSelections(int $registrationId): true|WP_Error
    {
        if (!$this->registrations->lockSelections($registrationId)) {
            return new WP_Error('db_error', 'Failed to lock choices');
        }

        return true;
    }

    /**
     * Check if choices are locked.
     */
    public function areSelectionsLocked(int $registrationId): bool
    {
        $registration = $this->registrations->find($registrationId);

        if (!$registration) {
            return false;
        }

        // Manually locked
        if ($this->registrations->areSelectionsLocked($registrationId)) {
            return true;
        }

        // Deadline passed
        return $this->trajectories->areChoicesLocked((int) $registration->trajectory_id);
    }

    // === Validation ===

    /**
     * Validate elective choices meet trajectory requirements.
     *
     * @param array<int> $editionIds
     */
    public function validateSelections(int $trajectoryId, array $editionIds): true|WP_Error
    {
        $editionIds = array_map('intval', $editionIds);
        $electiveGroups = $this->trajectoryRepo->getElectiveGroups($trajectoryId);

        foreach ($electiveGroups as $group) {
            $groupName = (string) ($group['name'] ?? 'Keuze');
            // `required` carries the group's min_choices; unset/0 keeps the
            // historic default of "pick exactly one".
            $required = max(1, (int) ($group['required'] ?? 0));

            // Selections are edition ids — collect the group's edition-backed
            // choices from each course post's attached trajectory_config.
            $groupEditionIds = [];
            foreach ($group['courses'] ?? [] as $coursePost) {
                $config = $coursePost->trajectory_config ?? [];
                $editionId = (int) ($config['edition_id'] ?? 0);
                if ($editionId > 0) {
                    $groupEditionIds[] = $editionId;
                }
            }

            // Pure-LD electives have no edition_id and are not selectable yet
            // (deferred to phased choices) — a group with nothing selectable
            // must not block the submission.
            if ($groupEditionIds === []) {
                continue;
            }

            $chosenInGroup = count(array_intersect($editionIds, $groupEditionIds));

            if ($chosenInGroup < $required) {
                return new WP_Error(
                    'incomplete_choices',
                    sprintf('Group "%s" requires %d selection(s), got %d', $groupName, $required, $chosenInGroup),
                );
            }

            if ($chosenInGroup > $required) {
                return new WP_Error(
                    'too_many_choices',
                    sprintf('Group "%s" allows %d selection(s), got %d', $groupName, $required, $chosenInGroup),
                );
            }
        }

        return true;
    }

    // === Queries ===

    // === Helpers ===

    /**
     * Return the mandatory edition IDs configured on a trajectory.
     *
     * Mandatory = `required: true` AND `type: edition` AND `edition_id > 0`.
     * Used by the initial_selection snapshot in enroll(). If the trajectory has
     * no mandatory editions (or cannot be loaded), returns an empty array — the
     * snapshot still records `type=trajectory` + the `enrollment` phase.
     *
     * @return array<int>
     */
    private function getMandatoryEditionIds(int $trajectoryId): array
    {
        $trajectory = $this->trajectories->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return [];
        }

        $ids = [];
        foreach ($trajectory['courses'] ?? [] as $course) {
            if (
                ($course['required'] ?? false) === true
                && ($course['type'] ?? '') === 'edition'
                && !empty($course['edition_id'])
            ) {
                $ids[] = (int) $course['edition_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * Get days until choice deadline.
     */
    public function getDaysUntilChoiceDeadline(int $trajectoryId): ?int
    {
        $deadline = $this->trajectoryRepo->getField($trajectoryId, 'choice_deadline');

        if (empty($deadline)) {
            return null;
        }

        $diff = strtotime($deadline) - time();

        return (int) floor($diff / DAY_IN_SECONDS);
    }
}
