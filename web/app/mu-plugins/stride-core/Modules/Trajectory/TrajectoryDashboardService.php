<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\TrajectoryMode;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Post;

/**
 * Trajectory Dashboard
 *
 * Aggregates data for personal trajectory dashboard display.
 *
 * Plain class — owned by TrajectoryService.
 */
final class TrajectoryDashboardService
{
    public function __construct(
        private readonly TrajectoryRepository $repository,
        private readonly TrajectoryService $trajectoryService,
        private readonly RegistrationRepository $registrationRepo,
        private readonly EditionService $editionService,
        private readonly LMSAdapterInterface $lmsAdapter,
    ) {
    }

    /**
     * Get trajectory by slug.
     */
    public function getTrajectoryBySlug(string $slug): ?WP_Post
    {
        return $this->repository->findBySlug($slug);
    }

    /**
     * Get user's enrollment for a trajectory.
     *
     * @return object|null Registration row or null
     */
    public function getEnrollmentForUser(int $userId, int $trajectoryId): ?object
    {
        return $this->registrationRepo->findByUserAndTrajectory($userId, $trajectoryId);
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

        $requiredCourses = $this->repository->getRequiredCourses($trajectoryId);
        $electiveGroups = $this->repository->getElectiveGroups($trajectoryId);

        // Calculate required count
        $totalRequired = count($requiredCourses);
        foreach ($electiveGroups as $group) {
            $totalRequired += (int) ($group['required'] ?? 0);
        }

        // Get user's edition registrations for this trajectory
        $editionRegs = $this->registrationRepo->findEditionsByTrajectory($userId, $trajectoryId);

        // Pre-build edition → courseId map to avoid N+1 getCourseId calls
        $editionCourseMap = [];
        foreach ($editionRegs as $edReg) {
            $edId = (int) $edReg->edition_id;
            $editionCourseMap[$edId] = $this->editionService->getCourseId($edId);
        }

        // Calculate completion
        $completedCourses = [];
        $inProgressCourses = [];

        foreach ($requiredCourses as $course) {
            $this->checkCourseStatus(
                $userId,
                $course->ID,
                $editionRegs,
                $editionCourseMap,
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
                    $editionCourseMap,
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
        array $editionCourseMap,
        array &$completedCourses,
        array &$inProgressCourses
    ): void {
        if ($this->lmsAdapter->isComplete($userId, $courseId)) {
            $completedCourses[] = $courseId;

            return;
        }

        // Check if enrolled in any edition for this course
        foreach ($editionRegs as $edReg) {
            $edCourseId = $editionCourseMap[(int) $edReg->edition_id] ?? 0;
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
        $requiredCourses = $this->repository->getRequiredCourses($trajectoryId);
        $electiveGroups = $this->repository->getElectiveGroups($trajectoryId);

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

            // LearnDash course materials - external data, not our model
            $courseMeta = \get_post_meta($course->ID, '_sfwd-courses', true);
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
        return $this->repository->getMessages($trajectoryId);
    }
}
