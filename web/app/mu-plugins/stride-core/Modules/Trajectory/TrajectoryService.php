<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\OfferingStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;
use WP_Post;

/**
 * Trajectory business logic.
 */
final class TrajectoryService extends AbstractService
{
    public function __construct(
        private readonly TrajectoryRepository $repository,
        private readonly RegistrationRepository $registrations,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Service',
            'description' => 'Manages multi-course programs',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'trajectory';
    }

    protected function init(): void
    {
        TrajectoryCPT::register();

        // Register sub-components
        ntdst_set(TrajectoryDashboardService::class, fn() => new TrajectoryDashboardService(
            $this->repository,
            $this,
            $this->registrations,
            ntdst_get(\Stride\Modules\Edition\EditionService::class),
            ntdst_get(\Stride\Contracts\LMSAdapterInterface::class),
        ));

        new Admin\TrajectoryAdminController(
            $this,
            $this->repository,
            $this->registrations,
            ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
        );
    }

    // === CRUD ===

    /**
     * Create a new trajectory.
     */
    public function createTrajectory(array $data): int|WP_Error
    {
        $result = $this->repository->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/trajectory/created', [
            'trajectory_id' => $result->ID,
        ]);

        return $result->ID;
    }

    /**
     * Get trajectory by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getTrajectory(int $trajectoryId): ?array
    {
        $post = $this->repository->find($trajectoryId);

        if (is_wp_error($post)) {
            return null;
        }

        return $this->formatTrajectory($post);
    }

    /**
     * Update trajectory.
     */
    public function updateTrajectory(int $trajectoryId, array $data): true|WP_Error
    {
        $result = $this->repository->update($trajectoryId, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/trajectory/updated', [
            'trajectory_id' => $trajectoryId,
        ]);

        return true;
    }

    // === Queries ===

    /**
     * Get all active trajectories.
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveTrajectories(): array
    {
        $trajectories = $this->repository->findActive();

        return array_map([$this, 'formatTrajectoryArray'], $trajectories);
    }

    /**
     * Get trajectories open for enrollment.
     *
     * @return array<array<string, mixed>>
     */
    public function getOpenTrajectories(): array
    {
        $trajectories = $this->repository->findOpen();

        return array_map([$this, 'formatTrajectoryArray'], $trajectories);
    }

    /**
     * Get course configuration for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getCourses(int $trajectoryId): array
    {
        return $this->repository->getCourses($trajectoryId);
    }

    /**
     * Get required courses.
     *
     * @return array<array<string, mixed>>
     */
    public function getRequiredCourses(int $trajectoryId): array
    {
        return $this->repository->getRequiredCourses($trajectoryId);
    }

    /**
     * Get elective groups.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    public function getElectiveGroups(int $trajectoryId): array
    {
        return $this->repository->getElectiveGroups($trajectoryId);
    }

    /**
     * Get total course count.
     */
    public function getCourseCount(int $trajectoryId): int
    {
        return count($this->repository->getCourses($trajectoryId));
    }

    /**
     * Get required course count.
     */
    public function getRequiredCourseCount(int $trajectoryId): int
    {
        return count($this->repository->getRequiredCourses($trajectoryId));
    }

    // === User Enrollment ===

    /**
     * Check if user is enrolled in trajectory.
     */
    public function isUserEnrolled(int $userId, int $trajectoryId): bool
    {
        return $this->registrations->existsForTrajectory($userId, $trajectoryId);
    }

    /**
     * Check if trajectory requires admin approval for enrollment.
     */
    public function requiresApproval(int $trajectoryId): bool
    {
        return (bool) $this->repository->getField($trajectoryId, 'requires_approval', false);
    }

    // === Deadline Checks ===

    /**
     * Check if enrollment is open.
     */
    public function isEnrollmentOpen(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        // Check status
        if (!$trajectory['status_enum']->allowsEnrollment()) {
            return false;
        }

        // Check enrollment deadline if set
        $deadline = $trajectory['enrollment_deadline'];
        if (!empty($deadline) && strtotime($deadline) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Check if choice window is open.
     */
    public function isChoiceWindowOpen(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        $now = time();

        // Check choice available date
        $availableDate = $trajectory['choice_available_date'];
        if (!empty($availableDate) && strtotime($availableDate) > $now) {
            return false;
        }

        // Check choice deadline
        $deadline = $trajectory['choice_deadline'];
        if (!empty($deadline) && strtotime($deadline) < $now) {
            return false;
        }

        return true;
    }

    /**
     * Check if choices are locked.
     */
    public function areChoicesLocked(int $trajectoryId): bool
    {
        $deadline = $this->repository->getField($trajectoryId, 'choice_deadline');

        if (empty($deadline)) {
            return false;
        }

        return strtotime($deadline) < time();
    }

    // === Helpers ===

    /**
     * Format WP_Post to trajectory array.
     */
    private function formatTrajectory(WP_Post $post): array
    {
        $modeValue = $this->repository->getField($post->ID, 'mode', 'cohort');
        $mode = TrajectoryMode::tryFrom($modeValue) ?? TrajectoryMode::Cohort;

        $statusValue = $this->repository->getField($post->ID, 'status', 'draft');
        $status = OfferingStatus::tryFrom($statusValue) ?? OfferingStatus::Draft;

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'mode' => $mode->value,
            'mode_enum' => $mode,
            'status' => $status->value,
            'status_enum' => $status,
            'enrollment_deadline' => $this->repository->getField($post->ID, 'enrollment_deadline', ''),
            'choice_available_date' => $this->repository->getField($post->ID, 'choice_available_date', ''),
            'choice_deadline' => $this->repository->getField($post->ID, 'choice_deadline', ''),
            'capacity' => (int) $this->repository->getField($post->ID, 'capacity', 0),
            'price' => (float) $this->repository->getField($post->ID, 'price', 0),
            'price_non_member' => (float) $this->repository->getField($post->ID, 'price_non_member', 0),
            'requires_approval' => (bool) $this->repository->getField($post->ID, 'requires_approval', false),
            'courses' => $this->repository->getCourses($post->ID),
        ];
    }

    /**
     * Format array result to trajectory array.
     */
    private function formatTrajectoryArray(array $data): array
    {
        $modeValue = $data['meta']['mode'] ?? $data['mode'] ?? 'cohort';
        $mode = TrajectoryMode::tryFrom($modeValue) ?? TrajectoryMode::Cohort;

        $statusValue = $data['meta']['status'] ?? $data['status'] ?? 'draft';
        $status = OfferingStatus::tryFrom($statusValue) ?? OfferingStatus::Draft;

        $courses = $data['meta']['courses'] ?? $data['courses'] ?? [];
        if (is_string($courses)) {
            $courses = json_decode($courses, true) ?: [];
        }

        return [
            'id' => (int) ($data['id'] ?? $data['ID'] ?? 0),
            'title' => $data['title'] ?? $data['post_title'] ?? '',
            'description' => $data['content'] ?? $data['post_content'] ?? '',
            'mode' => $mode->value,
            'mode_enum' => $mode,
            'status' => $status->value,
            'status_enum' => $status,
            'enrollment_deadline' => $data['meta']['enrollment_deadline'] ?? $data['enrollment_deadline'] ?? '',
            'choice_available_date' => $data['meta']['choice_available_date'] ?? $data['choice_available_date'] ?? '',
            'choice_deadline' => $data['meta']['choice_deadline'] ?? $data['choice_deadline'] ?? '',
            'capacity' => (int) ($data['meta']['capacity'] ?? $data['capacity'] ?? 0),
            'price' => (float) ($data['meta']['price'] ?? $data['price'] ?? 0),
            'price_non_member' => (float) ($data['meta']['price_non_member'] ?? $data['price_non_member'] ?? 0),
            'courses' => $courses,
        ];
    }
}
