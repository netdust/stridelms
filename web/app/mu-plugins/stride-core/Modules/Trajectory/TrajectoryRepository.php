<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Infrastructure\AbstractRepository;
use WP_Error;
use WP_Post;

/**
 * Trajectory repository for CRUD operations.
 */
final class TrajectoryRepository extends AbstractRepository
{
    protected string $postType = TrajectoryCPT::POST_TYPE;

    /**
     * Get a single field value.
     */
    public function getField(int $id, string $field, mixed $default = null): mixed
    {
        $value = $this->model()->getMeta($id, $field);

        return $value !== null ? $value : $default;
    }

    /**
     * Find active trajectories.
     *
     * @return array<array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->model()
            ->whereIn('status', [TrajectoryStatus::Open->value, TrajectoryStatus::InProgress->value])
            ->orderBy('post_title', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Find trajectories open for enrollment.
     *
     * @return array<array<string, mixed>>
     */
    public function findOpen(): array
    {
        return $this->model()
            ->where('status', TrajectoryStatus::Open->value)
            ->orderBy('post_title', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Validate trajectory data before create/update.
     */
    public function validate(array $data): true|WP_Error
    {
        // Validate mode if provided
        if (!empty($data['mode'])) {
            $mode = TrajectoryMode::tryFrom($data['mode']);
            if ($mode === null) {
                return new WP_Error('invalid_mode', 'Invalid trajectory mode');
            }
        }

        // Validate status if provided
        if (!empty($data['status'])) {
            $status = TrajectoryStatus::tryFrom($data['status']);
            if ($status === null) {
                return new WP_Error('invalid_status', 'Invalid trajectory status');
            }
        }

        // Validate deadline order if both provided
        if (!empty($data['choice_available_date']) && !empty($data['choice_deadline'])) {
            if (strtotime($data['choice_deadline']) <= strtotime($data['choice_available_date'])) {
                return new WP_Error('invalid_deadlines', 'Choice deadline must be after choice available date');
            }
        }

        return true;
    }

    /**
     * Create trajectory with validation.
     */
    public function create(array $data): WP_Post|WP_Error
    {
        $validation = $this->validate($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Set defaults
        if (empty($data['mode'])) {
            $data['mode'] = TrajectoryMode::Cohort->value;
        }
        if (empty($data['status'])) {
            $data['status'] = TrajectoryStatus::Draft->value;
        }

        return parent::create($data);
    }

    /**
     * Get course configuration for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getCourses(int $trajectoryId): array
    {
        $courses = $this->getField($trajectoryId, 'courses', []);

        if (empty($courses)) {
            return [];
        }

        return is_array($courses) ? $courses : (json_decode($courses, true) ?: []);
    }

    /**
     * Get required courses for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getRequiredCourses(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);

        return array_filter($courses, fn($c) => ($c['required'] ?? false) === true);
    }

    /**
     * Get elective groups for trajectory.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    public function getElectiveGroups(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);
        $electives = array_filter($courses, fn($c) => ($c['required'] ?? false) === false);

        $groups = [];
        foreach ($electives as $course) {
            $group = $course['group'] ?? 'Keuze';
            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }
            $groups[$group][] = $course;
        }

        return $groups;
    }
}
