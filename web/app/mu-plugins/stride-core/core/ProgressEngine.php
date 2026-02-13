<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use ntdst\Stride\FieldRegistry;
use WP_Error;

/**
 * Progress Engine
 *
 * Tracks user progress across multiple courses in a trajectory (learning path).
 * Handles graduation when all requirements are met.
 *
 * A trajectory has:
 * - Required courses (basis modules) - all must be completed
 * - Elective courses (keuze modules) - X out of Y must be completed
 * - Optional deadline for completion
 *
 * Available hooks:
 * - stride/trajectory/requirements_met (action) - When user completes all requirements
 * - stride/trajectory/graduated (action) - After graduation processed
 * - stride/trajectory/expired (action) - When deadline passed without completion
 *
 * @package stride\services\core
 */
class ProgressEngine implements \NTDST_Service_Meta
{
    private TrajectoryService $trajectoryService;
    private TrajectoryEnrollmentRepository $enrollmentRepo;
    private CourseService $courseService;
    private RegistrationRepository $registrationRepo;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Progress Engine',
            'description' => 'Trajectory progress tracking and graduation',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 16, // After CompletionEngine (15)
        ];
    }

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        ?TrajectoryService $trajectoryService = null,
        ?TrajectoryEnrollmentRepository $enrollmentRepo = null,
        ?CourseService $courseService = null,
        ?RegistrationRepository $registrationRepo = null
    ) {
        $this->trajectoryService = $trajectoryService ?? $this->resolveService(TrajectoryService::class);
        $this->enrollmentRepo = $enrollmentRepo ?? new TrajectoryEnrollmentRepository();
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->registrationRepo = $registrationRepo ?? $this->resolveService(RegistrationRepository::class);

        // Ensure trajectory enrollment table exists
        add_action('init', [$this, 'ensureTable'], 6);

        // Hook into edition completion to check trajectory progress
        add_action('stride/edition/completed', [$this, 'onEditionCompleted'], 10, 3);

        // Daily cron to check expired trajectories
        add_action('stride/cron/daily', [$this, 'processExpiredEnrollments']);
    }

    /**
     * Resolve service from DI container or create new instance
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }
        return new $class();
    }

    /**
     * Ensure trajectory enrollment table exists
     */
    public function ensureTable(): void
    {
        if (!$this->enrollmentRepo->tableExists()) {
            $this->enrollmentRepo->createTable();
        }
    }

    // ========================================
    // AUTHORIZATION
    // ========================================

    /**
     * Check if current user can manage any trajectory enrollment
     */
    public function currentUserCanManage(): bool
    {
        return current_user_can('manage_options') || current_user_can('edit_others_posts');
    }

    /**
     * Check if current user can access another user's data
     *
     * @param int $targetUserId User ID to access
     * @return bool
     */
    private function canAccessUserData(int $targetUserId): bool
    {
        $currentUserId = get_current_user_id();

        // Own data always accessible
        if ($currentUserId > 0 && $currentUserId === $targetUserId) {
            return true;
        }

        // Admins can access all data
        if ($this->currentUserCanManage()) {
            return true;
        }

        // Allow filter for custom access rules (e.g., group leaders)
        return apply_filters(
            'stride/trajectory/can_access_user',
            false,
            $currentUserId,
            $targetUserId
        );
    }

    /**
     * Authorization check for enrollment modification
     *
     * @param int $targetUserId User to modify
     * @return true|WP_Error
     */
    private function authorizeEnrollmentChange(int $targetUserId): true|WP_Error
    {
        if ($this->canAccessUserData($targetUserId)) {
            return true;
        }

        return new WP_Error(
            'unauthorized',
            __('Je hebt geen toestemming om deze inschrijving te wijzigen.', 'stride'),
            ['status' => 403]
        );
    }

    // ========================================
    // PROGRESS TRACKING
    // ========================================

    /**
     * Get trajectory progress for a user
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $userId WordPress user ID
     * @param array|null $completedCourseIds Pre-loaded completed course IDs (for testing/optimization)
     * @return array Progress data
     */
    public function getProgress(int $trajectoryId, int $userId, ?array $completedCourseIds = null): array
    {
        $trajectory = $this->trajectoryService->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return [
                'error' => 'Trajectory not found',
                'completed_courses' => [],
                'remaining_courses' => [],
                'elective_groups' => [],
                'percentage' => 0,
                'is_complete' => false,
            ];
        }

        $enrollment = $this->enrollmentRepo->findByUserAndTrajectory($userId, $trajectoryId);
        $requirements = $trajectory['courses'] ?? [];

        // Get completed courses for this user (use provided or fetch from LearnDash)
        if ($completedCourseIds === null) {
            $completedCourseIds = $this->getCompletedCourseIds($userId, $requirements);
        }

        // Calculate progress for each requirement group
        $groupProgress = $this->calculateGroupProgress($requirements, $completedCourseIds);

        // Calculate overall progress
        $totalRequired = 0;
        $totalCompleted = 0;

        foreach ($groupProgress as $group) {
            $totalRequired += $group['required'];
            $totalCompleted += min($group['completed'], $group['required']);
        }

        $percentage = $totalRequired > 0 ? round(($totalCompleted / $totalRequired) * 100, 1) : 100;
        $isComplete = $this->meetsRequirements($trajectoryId, $userId, $completedCourseIds);

        return [
            'trajectory_id' => $trajectoryId,
            'user_id' => $userId,
            'enrollment' => $enrollment,
            'completed_courses' => $completedCourseIds,
            'groups' => $groupProgress,
            'total_required' => $totalRequired,
            'total_completed' => $totalCompleted,
            'percentage' => $percentage,
            'is_complete' => $isComplete,
            'deadline_at' => $enrollment['deadline_at'] ?? null,
            'is_expired' => $this->isExpired($enrollment),
        ];
    }

    /**
     * Check if all requirements are met
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $userId WordPress user ID
     * @param array|null $completedCourseIds Pre-loaded completed course IDs (optimization)
     * @return bool
     */
    public function meetsRequirements(int $trajectoryId, int $userId, ?array $completedCourseIds = null): bool
    {
        $trajectory = $this->trajectoryService->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return false;
        }

        $requirements = $trajectory['courses'] ?? [];
        if (empty($requirements)) {
            return true; // No requirements = complete
        }

        // Get completed courses if not provided
        if ($completedCourseIds === null) {
            $completedCourseIds = $this->getCompletedCourseIds($userId, $requirements);
        }

        // Group requirements by group name to handle electives
        $groups = $this->groupRequirements($requirements);

        foreach ($groups as $groupName => $groupReqs) {
            $pickCount = $this->getPickCountForGroup($groupReqs);
            $completed = $this->countCompletedInGroup($groupReqs, $completedCourseIds);

            if ($completed < $pickCount) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get completed course IDs from requirements
     *
     * Uses batch query for performance (avoids N+1).
     *
     * @param int $userId WordPress user ID
     * @param array $requirements Course requirements
     * @return array Completed course IDs
     */
    private function getCompletedCourseIds(int $userId, array $requirements): array
    {
        $courseIds = array_map(fn($req) => (int) ($req['course_id'] ?? 0), $requirements);
        $courseIds = array_filter($courseIds);

        if (empty($courseIds)) {
            return [];
        }

        // Use batch completion check to avoid N+1 queries
        return $this->courseService->getBatchCompletionStatus($userId, $courseIds);
    }

    /**
     * Calculate progress for each requirement group
     *
     * Uses batch title loading for performance.
     */
    private function calculateGroupProgress(array $requirements, array $completedCourseIds): array
    {
        $groups = $this->groupRequirements($requirements);

        // Batch load all course titles to avoid N+1 queries
        $allCourseIds = array_map(fn($req) => (int) ($req['course_id'] ?? 0), $requirements);
        $allCourseIds = array_filter($allCourseIds);
        $courseTitles = $this->batchLoadCourseTitles($allCourseIds);

        $progress = [];

        foreach ($groups as $groupName => $groupReqs) {
            $pickCount = $this->getPickCountForGroup($groupReqs);
            $completed = $this->countCompletedInGroup($groupReqs, $completedCourseIds);
            $courseCount = count($groupReqs);

            $courses = [];
            foreach ($groupReqs as $req) {
                $courseId = (int) ($req['course_id'] ?? 0);
                $isCompleted = in_array($courseId, $completedCourseIds, true);
                $courses[] = [
                    'course_id' => $courseId,
                    'title' => $courseTitles[$courseId] ?? __('Onbekende cursus', 'stride'),
                    'is_completed' => $isCompleted,
                ];
            }

            $progress[$groupName] = [
                'name' => $groupName,
                'courses' => $courses,
                'total' => $courseCount,
                'completed' => $completed,
                'required' => $pickCount,
                'is_complete' => $completed >= $pickCount,
                'is_elective' => $pickCount < $courseCount,
            ];
        }

        return $progress;
    }

    /**
     * Batch load course titles
     *
     * @param array $courseIds Array of course IDs
     * @return array Map of course_id => title
     */
    private function batchLoadCourseTitles(array $courseIds): array
    {
        if (empty($courseIds)) {
            return [];
        }

        // Use single query to get all posts
        $posts = get_posts([
            'post_type' => 'sfwd-courses',
            'post__in' => $courseIds,
            'posts_per_page' => count($courseIds),
            'post_status' => 'any',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
        ]);

        $titles = [];
        foreach ($posts as $post) {
            $titles[$post->ID] = $post->post_title;
        }

        return $titles;
    }

    /**
     * Group requirements by group name
     */
    private function groupRequirements(array $requirements): array
    {
        $grouped = [];
        foreach ($requirements as $req) {
            $group = $req['group'] ?? __('Overige', 'stride');
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $req;
        }
        return $grouped;
    }

    /**
     * Get the required count for a group
     *
     * If any requirement in group has pick_count, use that.
     * Otherwise, all courses in group are required.
     */
    private function getPickCountForGroup(array $groupReqs): int
    {
        foreach ($groupReqs as $req) {
            if (isset($req['pick_count']) && $req['pick_count'] > 0) {
                return (int) $req['pick_count'];
            }
        }
        return count($groupReqs); // All required
    }

    /**
     * Count completed courses in a group
     */
    private function countCompletedInGroup(array $groupReqs, array $completedCourseIds): int
    {
        $count = 0;
        foreach ($groupReqs as $req) {
            $courseId = (int) ($req['course_id'] ?? 0);
            if (in_array($courseId, $completedCourseIds, true)) {
                $count++;
            }
        }
        return $count;
    }

    // ========================================
    // ELECTIVES
    // ========================================

    /**
     * Get available electives (not yet completed)
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $userId WordPress user ID
     * @param array|null $completedCourseIds Pre-loaded completed course IDs (for testing/optimization)
     * @return array Available elective courses
     */
    public function getAvailableElectives(int $trajectoryId, int $userId, ?array $completedCourseIds = null): array
    {
        $progress = $this->getProgress($trajectoryId, $userId, $completedCourseIds);
        $available = [];

        foreach ($progress['groups'] as $group) {
            if (!$group['is_elective']) {
                continue;
            }

            // If group is already complete, skip
            if ($group['is_complete']) {
                continue;
            }

            foreach ($group['courses'] as $course) {
                if (!$course['is_completed']) {
                    $available[] = array_merge($course, [
                        'group' => $group['name'],
                    ]);
                }
            }
        }

        return $available;
    }

    /**
     * Get remaining required courses
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $userId WordPress user ID
     * @param array|null $completedCourseIds Pre-loaded completed course IDs (for testing/optimization)
     * @return array Remaining required courses
     */
    public function getRemainingCourses(int $trajectoryId, int $userId, ?array $completedCourseIds = null): array
    {
        $progress = $this->getProgress($trajectoryId, $userId, $completedCourseIds);
        $remaining = [];

        foreach ($progress['groups'] as $group) {
            foreach ($group['courses'] as $course) {
                if (!$course['is_completed']) {
                    $remaining[] = array_merge($course, [
                        'group' => $group['name'],
                        'is_elective' => $group['is_elective'],
                    ]);
                }
            }
        }

        return $remaining;
    }

    // ========================================
    // GRADUATION
    // ========================================

    /**
     * Check and process graduation for a user
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $userId WordPress user ID
     * @return bool True if graduated
     */
    public function checkGraduation(int $trajectoryId, int $userId): bool
    {
        $enrollment = $this->enrollmentRepo->findByUserAndTrajectory($userId, $trajectoryId);
        if (!$enrollment) {
            return false;
        }

        // Already graduated
        if ($enrollment['status'] === TrajectoryEnrollmentRepository::STATUS_COMPLETED) {
            return true;
        }

        // Check if expired
        if ($this->isExpired($enrollment)) {
            $this->enrollmentRepo->expire($enrollment['id']);
            do_action('stride/trajectory/expired', $trajectoryId, $userId, $enrollment['id']);
            return false;
        }

        // Check requirements
        if (!$this->meetsRequirements($trajectoryId, $userId)) {
            return false;
        }

        // Fire requirements met action (before graduation)
        do_action('stride/trajectory/requirements_met', $trajectoryId, $userId, $enrollment['id']);

        // Graduate the user
        $result = $this->enrollmentRepo->complete($enrollment['id']);
        if (is_wp_error($result)) {
            return false;
        }

        // Fire graduation action
        do_action('stride/trajectory/graduated', $trajectoryId, $userId, $enrollment['id']);

        return true;
    }

    /**
     * Check if enrollment is expired
     */
    private function isExpired(?array $enrollment): bool
    {
        if (!$enrollment || empty($enrollment['deadline_at'])) {
            return false;
        }

        if ($enrollment['status'] === TrajectoryEnrollmentRepository::STATUS_COMPLETED) {
            return false; // Can't expire if already complete
        }

        return strtotime($enrollment['deadline_at']) < time();
    }

    // ========================================
    // ENROLLMENT
    // ========================================

    /**
     * Enroll user in a trajectory
     *
     * For cohort trajectories, this also:
     * - Auto-enrolls user in all linked editions
     * - Fires stride/trajectory/edition_enrolled for each edition
     *
     * @param int $userId WordPress user ID
     * @param int $trajectoryId Trajectory post ID
     * @param array $options Additional options:
     *                       - notes: Optional notes
     *                       - skip_edition_enrollment: Skip auto-enrolling in editions (default: false)
     *                       - billing_data: Billing data for quotes
     * @return int|WP_Error Enrollment ID or error
     */
    public function enrollInTrajectory(int $userId, int $trajectoryId, array $options = []): int|WP_Error
    {
        // Authorization check
        $authCheck = $this->authorizeEnrollmentChange($userId);
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        $trajectory = $this->trajectoryService->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return new WP_Error('not_found', __('Traject niet gevonden.', 'stride'));
        }

        if (!$this->trajectoryService->isOpen($trajectoryId)) {
            return new WP_Error('closed', __('Dit traject is niet open voor inschrijving.', 'stride'));
        }

        // Check for existing enrollment
        $existing = $this->enrollmentRepo->findByUserAndTrajectory($userId, $trajectoryId);
        if ($existing && $existing['status'] === TrajectoryEnrollmentRepository::STATUS_ACTIVE) {
            return new WP_Error('already_enrolled', __('Je bent al ingeschreven voor dit traject.', 'stride'));
        }

        // Calculate deadline based on mode
        $deadlineAt = null;
        $mode = $trajectory['mode'] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED;

        if ($mode === FieldRegistry::TRAJECTORY_MODE_SELF_PACED) {
            // Self-paced: deadline from enrollment date
            $deadlineMonths = $trajectory['deadline_months'] ?? null;
            if ($deadlineMonths && $deadlineMonths > 0) {
                $deadlineAt = wp_date('Y-m-d H:i:s', strtotime("+{$deadlineMonths} months"));
            }
        }
        // Cohort mode: no individual deadline (trajectory has fixed dates)

        // Create trajectory enrollment
        $enrollmentId = $this->enrollmentRepo->create([
            'trajectory_id' => $trajectoryId,
            'user_id' => $userId,
            'deadline_at' => $deadlineAt,
            'notes' => $options['notes'] ?? null,
        ]);

        if (is_wp_error($enrollmentId)) {
            return $enrollmentId;
        }

        // For cohort mode: auto-enroll in linked editions
        if ($mode === FieldRegistry::TRAJECTORY_MODE_COHORT && empty($options['skip_edition_enrollment'])) {
            $this->autoEnrollInLinkedEditions($userId, $trajectory, $enrollmentId);
        }

        do_action('stride/trajectory/enrolled', $trajectoryId, $userId, $enrollmentId, [
            'mode' => $mode,
            'options' => $options,
        ]);

        return $enrollmentId;
    }

    /**
     * Auto-enroll user in all linked editions for a cohort trajectory
     *
     * @param int $userId WordPress user ID
     * @param array $trajectory Trajectory data
     * @param int $trajectoryEnrollmentId Trajectory enrollment ID
     */
    private function autoEnrollInLinkedEditions(int $userId, array $trajectory, int $trajectoryEnrollmentId): void
    {
        $linkedEditions = $trajectory['linked_editions'] ?? [];
        if (empty($linkedEditions)) {
            return;
        }

        foreach ($linkedEditions as $link) {
            $editionId = (int) ($link['edition_id'] ?? 0);
            $courseId = (int) ($link['course_id'] ?? 0);

            if ($editionId <= 0) {
                continue;
            }

            // Check if already registered for this edition
            $existingReg = $this->registrationRepo->findByUserAndEdition($userId, $editionId);
            if ($existingReg) {
                continue; // Already registered
            }

            // Create registration for linked edition
            $registrationId = $this->registrationRepo->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'status' => RegistrationRepository::STATUS_CONFIRMED,
                'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
                'notes' => sprintf(
                    __('Auto-ingeschreven via traject #%d', 'stride'),
                    $trajectory['id']
                ),
            ]);

            if (!is_wp_error($registrationId)) {
                // Grant LearnDash course access
                if ($courseId > 0) {
                    $this->courseService->grantAccess($userId, $courseId);
                }

                do_action('stride/trajectory/edition_enrolled', $editionId, $userId, $registrationId, [
                    'trajectory_id' => $trajectory['id'],
                    'trajectory_enrollment_id' => $trajectoryEnrollmentId,
                    'course_id' => $courseId,
                ]);
            }
        }
    }

    /**
     * Cancel trajectory enrollment
     *
     * @param int $userId WordPress user ID
     * @param int $trajectoryId Trajectory post ID
     * @return true|WP_Error
     */
    public function cancelEnrollment(int $userId, int $trajectoryId): true|WP_Error
    {
        // Authorization check
        $authCheck = $this->authorizeEnrollmentChange($userId);
        if (is_wp_error($authCheck)) {
            return $authCheck;
        }

        $enrollment = $this->enrollmentRepo->findByUserAndTrajectory($userId, $trajectoryId);
        if (!$enrollment) {
            return new WP_Error('not_found', __('Traject-inschrijving niet gevonden.', 'stride'));
        }

        return $this->enrollmentRepo->cancel($enrollment['id']);
    }

    /**
     * Get user's active trajectory enrollments
     *
     * @param int $userId WordPress user ID
     * @return array Array of enrollments with trajectory data
     */
    public function getUserTrajectories(int $userId): array
    {
        $enrollments = $this->enrollmentRepo->getByUser($userId);
        $result = [];

        foreach ($enrollments as $enrollment) {
            $trajectory = $this->trajectoryService->getTrajectory($enrollment['trajectory_id']);
            if ($trajectory) {
                $result[] = array_merge($enrollment, [
                    'trajectory' => $trajectory,
                    'progress' => $this->getProgress($enrollment['trajectory_id'], $userId),
                ]);
            }
        }

        return $result;
    }

    // ========================================
    // HOOKS
    // ========================================

    /**
     * Hook: After edition is completed
     *
     * Check if this triggers any trajectory graduation.
     */
    public function onEditionCompleted(int $editionId, int $userId, int $courseId): void
    {
        // Find trajectories that include this course
        $trajectories = $this->trajectoryService->getTrajectoriesForCourse($courseId);

        foreach ($trajectories as $trajectory) {
            // Check if user is enrolled in this trajectory
            $enrollment = $this->enrollmentRepo->findByUserAndTrajectory($userId, $trajectory['id']);
            if ($enrollment && $enrollment['status'] === TrajectoryEnrollmentRepository::STATUS_ACTIVE) {
                // Check for graduation
                $this->checkGraduation($trajectory['id'], $userId);
            }
        }
    }

    /**
     * Process expired enrollments (called via cron)
     */
    public function processExpiredEnrollments(): void
    {
        $expired = $this->enrollmentRepo->getExpiredEnrollments();

        foreach ($expired as $enrollment) {
            $this->enrollmentRepo->expire($enrollment['id']);
            do_action('stride/trajectory/expired', $enrollment['trajectory_id'], $enrollment['user_id'], $enrollment['id']);
        }
    }

    // ========================================
    // STATISTICS
    // ========================================

    /**
     * Get trajectory statistics
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array Statistics
     */
    public function getTrajectoryStatistics(int $trajectoryId): array
    {
        return [
            'total_enrolled' => $this->enrollmentRepo->countByTrajectory($trajectoryId),
            'active' => $this->enrollmentRepo->countByTrajectory($trajectoryId, TrajectoryEnrollmentRepository::STATUS_ACTIVE),
            'completed' => $this->enrollmentRepo->countByTrajectory($trajectoryId, TrajectoryEnrollmentRepository::STATUS_COMPLETED),
            'expired' => $this->enrollmentRepo->countByTrajectory($trajectoryId, TrajectoryEnrollmentRepository::STATUS_EXPIRED),
            'cancelled' => $this->enrollmentRepo->countByTrajectory($trajectoryId, TrajectoryEnrollmentRepository::STATUS_CANCELLED),
        ];
    }
}
