<?php
/**
 * Trajectory Dashboard Service
 *
 * Aggregates data for personal trajectory dashboard display.
 * Frontend-only service in theme.
 *
 * @package stridence
 */

declare(strict_types=1);

namespace stridence\services\frontend;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\TrajectoryMode;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use WP_Post;

class TrajectoryDashboardService implements \NTDST_Service_Meta
{
    public function __construct(
        private readonly TrajectoryService $trajectoryService,
        private readonly RegistrationRepository $registrationRepo,
        private readonly EditionService $editionService,
        private readonly LMSAdapterInterface $lmsAdapter,
    ) {
        $this->init();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Dashboard',
            'description' => 'Data aggregation for personal trajectory dashboard',
            'priority' => 20,
        ];
    }

    private function init(): void
    {
        // No hooks needed - pure data service
    }

    /**
     * Get trajectory by slug.
     *
     * @return WP_Post|null
     */
    public function getTrajectoryBySlug(string $slug): ?WP_Post
    {
        $posts = get_posts([
            'post_type' => 'vad_trajectory',
            'name' => $slug,
            'post_status' => 'publish',
            'posts_per_page' => 1,
        ]);

        return $posts[0] ?? null;
    }

    /**
     * Get user's enrollment for a trajectory.
     *
     * @return object|null Registration row or null
     */
    public function getEnrollmentForUser(int $userId, int $trajectoryId): ?object
    {
        $enrollments = $this->registrationRepo->findTrajectoryEnrollmentsByUser($userId);

        foreach ($enrollments as $enrollment) {
            if ((int) $enrollment->trajectory_id === $trajectoryId) {
                return $enrollment;
            }
        }

        return null;
    }

    /**
     * Get progress data for a user's trajectory enrollment.
     *
     * @return array{
     *   required_courses: array,
     *   elective_groups: array,
     *   completed_count: int,
     *   in_progress_count: int,
     *   total_required: int,
     *   mode: TrajectoryMode,
     *   edition_registrations: array,
     *   completed_courses: array,
     *   in_progress_courses: array
     * }
     */
    public function getProgressData(int $userId, int $trajectoryId): array
    {
        $trajectory = $this->trajectoryService->getTrajectory($trajectoryId);
        $mode = TrajectoryMode::tryFrom($trajectory['mode'] ?? '') ?? TrajectoryMode::Cohort;

        $requiredCourses = $this->trajectoryService->getRequiredCourses($trajectoryId);
        $electiveGroups = $this->trajectoryService->getElectiveGroups($trajectoryId);

        // Calculate required count
        $totalRequired = count($requiredCourses);
        foreach ($electiveGroups as $group) {
            $totalRequired += (int) ($group['required'] ?? 0);
        }

        // Get user's edition registrations for this trajectory
        $editionRegs = $this->registrationRepo->findEditionsByTrajectory($userId, $trajectoryId);

        // Calculate completion
        $completedCourses = [];
        $inProgressCourses = [];

        foreach ($requiredCourses as $course) {
            $this->checkCourseStatus(
                $userId,
                $course->ID,
                $editionRegs,
                $completedCourses,
                $inProgressCourses
            );
        }

        foreach ($electiveGroups as $group) {
            foreach ($group['courses'] ?? [] as $course) {
                $this->checkCourseStatus(
                    $userId,
                    $course->ID,
                    $editionRegs,
                    $completedCourses,
                    $inProgressCourses
                );
            }
        }

        return [
            'required_courses' => $requiredCourses,
            'elective_groups' => $electiveGroups,
            'completed_count' => count(array_unique($completedCourses)),
            'in_progress_count' => count(array_unique($inProgressCourses)),
            'total_required' => $totalRequired,
            'mode' => $mode,
            'edition_registrations' => $editionRegs,
            'completed_courses' => $completedCourses,
            'in_progress_courses' => $inProgressCourses,
        ];
    }

    /**
     * Check course completion/progress status.
     */
    private function checkCourseStatus(
        int $userId,
        int $courseId,
        array $editionRegs,
        array &$completedCourses,
        array &$inProgressCourses
    ): void {
        if ($this->lmsAdapter->isComplete($userId, $courseId)) {
            $completedCourses[] = $courseId;
            return;
        }

        // Check if enrolled in any edition for this course
        foreach ($editionRegs as $edReg) {
            $edCourseId = $this->editionService->getCourseId((int) $edReg->edition_id);
            if ($edCourseId === $courseId) {
                $inProgressCourses[] = $courseId;
                return;
            }
        }
    }

    /**
     * Get course materials for trajectory.
     *
     * @return array Array of course data with materials
     */
    public function getMaterials(int $trajectoryId, int $userId): array
    {
        $requiredCourses = $this->trajectoryService->getRequiredCourses($trajectoryId);
        $electiveGroups = $this->trajectoryService->getElectiveGroups($trajectoryId);

        $allCourses = $requiredCourses;
        foreach ($electiveGroups as $group) {
            foreach ($group['courses'] ?? [] as $course) {
                $allCourses[] = $course;
            }
        }

        $materials = [];
        foreach ($allCourses as $course) {
            // Check if user has access (via any edition enrollment)
            // Note: LMSAdapterInterface has no hasAccess(), use LearnDashHelper
            if (!LearnDashHelper::hasAccess($course->ID, $userId)) {
                continue;
            }

            $courseMeta = get_post_meta($course->ID, '_sfwd-courses', true);
            $courseMaterials = $courseMeta['sfwd-courses_course_materials'] ?? '';

            if (empty($courseMaterials)) {
                continue;
            }

            $materials[] = [
                'course_id' => $course->ID,
                'title' => $course->post_title,
                'materials' => $courseMaterials,
            ];
        }

        return $materials;
    }

    /**
     * Get messages/announcements for trajectory.
     *
     * @return array Array of message objects
     */
    public function getMessages(int $trajectoryId): array
    {
        $messages = get_post_meta($trajectoryId, 'trajectory_messages', true);

        if (empty($messages) || !is_array($messages)) {
            return [];
        }

        // Filter out deleted messages and sort by date descending
        $messages = array_filter($messages, fn($m) => empty($m['_deleted']));
        usort($messages, fn($a, $b) => strtotime($b['date'] ?? '') - strtotime($a['date'] ?? ''));

        return $messages;
    }
}
