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

    /**
     * Check if user is enrolled in trajectory.
     */
    public function isEnrolled(int $userId, int $trajectoryId): bool
    {
        return $this->registrations->existsForTrajectory($userId, $trajectoryId);
    }

    /**
     * Get user's trajectory enrollment.
     */
    public function getEnrollment(int $userId, int $trajectoryId): ?object
    {
        return $this->registrations->findByUserAndTrajectory($userId, $trajectoryId);
    }

    // === Elective Selection ===

    /**
     * Get user's elective choices (edition IDs).
     *
     * @return array<int>
     */
    public function getSelections(int $registrationId): array
    {
        return $this->registrations->getSelections($registrationId);
    }

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
        $electiveGroups = $this->trajectoryRepo->getElectiveGroups($trajectoryId);

        foreach ($electiveGroups as $groupName => $courses) {
            $courseIds = array_column($courses, 'course_id');
            $pickCount = $courses[0]['pick_count'] ?? 1;

            // Count how many chosen from this group
            $chosenInGroup = count(array_intersect($editionIds, $courseIds));

            if ($chosenInGroup < $pickCount) {
                return new WP_Error(
                    'incomplete_choices',
                    sprintf('Group "%s" requires %d selection(s), got %d', $groupName, $pickCount, $chosenInGroup)
                );
            }

            if ($chosenInGroup > $pickCount) {
                return new WP_Error(
                    'too_many_choices',
                    sprintf('Group "%s" allows %d selection(s), got %d', $groupName, $pickCount, $chosenInGroup)
                );
            }
        }

        return true;
    }

    // === Queries ===

    /**
     * Get user's trajectory enrollments.
     *
     * @return array<object>
     */
    public function getUserEnrollments(int $userId): array
    {
        return $this->registrations->findTrajectoryEnrollmentsByUser($userId);
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
