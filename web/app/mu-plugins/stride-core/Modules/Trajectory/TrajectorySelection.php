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
        $this->registrations->appendInitialSelectionPhase(
            $registrationId,
            [
                'phase'       => 'enrollment',
                'edition_ids' => array_values(array_map('intval', $editionIds)),
            ],
            'trajectory',
        );

        do_action('stride/trajectory/choices_updated', [
            'registration_id' => $registrationId,
            'trajectory_id' => $trajectoryId,
            'edition_ids' => $editionIds,
        ]);

        return true;
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
