<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Infrastructure\AbstractService;
use WP_Error;

/**
 * Trajectory elective selection with deadline enforcement.
 *
 * Handles user picking elective courses within a trajectory.
 */
final class TrajectorySelectionService extends AbstractService
{
    public function __construct(
        private readonly TrajectoryService $trajectories,
        private readonly TrajectoryEnrollmentRepository $enrollments,
        private readonly TrajectoryRepository $trajectoryRepo,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Selection Service',
            'description' => 'Handles elective selection with deadlines',
            'priority' => 21,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'trajectory_selection';
    }

    protected function init(): void
    {
        // Future: lock expired choices
    }

    // === Enrollment Actions ===

    /**
     * Enroll user in trajectory.
     */
    public function enroll(int $userId, int $trajectoryId): int|WP_Error
    {
        // Check trajectory allows enrollment
        if (!$this->trajectories->isEnrollmentOpen($trajectoryId)) {
            return new WP_Error('enrollment_closed', 'Enrollment is not open for this trajectory');
        }

        // Check capacity
        if (!$this->hasCapacity($trajectoryId)) {
            return new WP_Error('no_capacity', 'Trajectory is full');
        }

        // Check not already enrolled
        if ($this->enrollments->isEnrolled($userId, $trajectoryId)) {
            return new WP_Error('already_enrolled', 'Already enrolled in this trajectory');
        }

        // Create enrollment
        $enrollmentId = $this->enrollments->create([
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'status' => 'enrolled',
        ]);

        if (is_wp_error($enrollmentId)) {
            return $enrollmentId;
        }

        do_action('stride/trajectory/enrolled', [
            'enrollment_id' => $enrollmentId,
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
        ]);

        return $enrollmentId;
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

        $enrolled = $this->enrollments->countByTrajectory($trajectoryId);

        return $enrolled < $trajectory['capacity'];
    }

    // === Elective Selection ===

    /**
     * Set elective choices for enrollment.
     *
     * @param array<string, array<int>> $choices Group => [course_ids]
     */
    public function setElectiveChoices(int $enrollmentId, array $choices): true|WP_Error
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return new WP_Error('enrollment_not_found', 'Enrollment not found');
        }

        $trajectoryId = (int) $enrollment['trajectory_id'];

        // Check choice window is open
        if (!$this->trajectories->isChoiceWindowOpen($trajectoryId)) {
            return new WP_Error('choice_window_closed', 'Choice window is not open');
        }

        // Validate choices meet requirements
        $validation = $this->validateChoices($trajectoryId, $choices);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Use transaction for atomic update
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $result = $this->enrollments->update($enrollmentId, [
                'elective_choices' => $choices,
            ]);

            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
                return $result;
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Failed to save choices');
        }

        do_action('stride/trajectory/choices_updated', [
            'enrollment_id' => $enrollmentId,
            'trajectory_id' => $trajectoryId,
            'choices' => $choices,
        ]);

        return true;
    }

    /**
     * Get user's elective choices.
     *
     * @return array<string, array<int>>
     */
    public function getElectiveChoices(int $enrollmentId): array
    {
        return $this->enrollments->getElectiveChoices($enrollmentId);
    }

    /**
     * Lock elective choices for enrollment.
     */
    public function lockChoices(int $enrollmentId): true|WP_Error
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return new WP_Error('enrollment_not_found', 'Enrollment not found');
        }

        // Already locked
        if (!empty($enrollment['choices_locked_at'])) {
            return true;
        }

        return $this->enrollments->update($enrollmentId, [
            'choices_locked_at' => current_time('mysql'),
        ]);
    }

    /**
     * Check if choices are locked for enrollment.
     */
    public function areChoicesLocked(int $enrollmentId): bool
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return false;
        }

        // Manually locked
        if (!empty($enrollment['choices_locked_at'])) {
            return true;
        }

        // Deadline passed
        return $this->trajectories->areChoicesLocked((int) $enrollment['trajectory_id']);
    }

    // === Validation ===

    /**
     * Validate elective choices meet trajectory requirements.
     *
     * @param array<string, array<int>> $choices
     */
    public function validateChoices(int $trajectoryId, array $choices): true|WP_Error
    {
        $electiveGroups = $this->trajectoryRepo->getElectiveGroups($trajectoryId);

        foreach ($electiveGroups as $groupName => $courses) {
            $courseIds = array_column($courses, 'course_id');
            $pickCount = $courses[0]['pick_count'] ?? 1;

            $chosenForGroup = $choices[$groupName] ?? [];

            // Validate chosen courses belong to this group
            $validChoices = array_intersect($chosenForGroup, $courseIds);

            if (count($validChoices) < $pickCount) {
                return new WP_Error(
                    'incomplete_choices',
                    sprintf('Group "%s" requires %d selection(s), got %d', $groupName, $pickCount, count($validChoices))
                );
            }

            if (count($validChoices) > $pickCount) {
                return new WP_Error(
                    'too_many_choices',
                    sprintf('Group "%s" allows %d selection(s), got %d', $groupName, $pickCount, count($validChoices))
                );
            }
        }

        return true;
    }

    // === Queries ===

    /**
     * Get enrollment with details.
     *
     * @return array<string, mixed>|null
     */
    public function getEnrollment(int $enrollmentId): ?array
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return null;
        }

        $trajectory = $this->trajectories->getTrajectory((int) $enrollment['trajectory_id']);

        return [
            'id' => (int) $enrollment['id'],
            'user_id' => (int) $enrollment['user_id'],
            'trajectory_id' => (int) $enrollment['trajectory_id'],
            'trajectory' => $trajectory,
            'status' => $enrollment['status'],
            'elective_choices' => $this->enrollments->getElectiveChoices((int) $enrollment['id']),
            'choices_locked' => $this->areChoicesLocked((int) $enrollment['id']),
            'enrolled_at' => $enrollment['enrolled_at'],
            'completed_at' => $enrollment['completed_at'],
        ];
    }

    /**
     * Get user's trajectory enrollments.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserEnrollments(int $userId): array
    {
        $enrollments = $this->enrollments->findActiveByUser($userId);

        return array_map(function ($e) {
            return $this->getEnrollment((int) $e['id']);
        }, $enrollments);
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
