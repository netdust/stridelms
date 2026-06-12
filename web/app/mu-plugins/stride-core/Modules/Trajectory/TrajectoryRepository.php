<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\OfferingStatus;
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
            ->whereIn('status', [OfferingStatus::Announcement->value, OfferingStatus::Open->value, OfferingStatus::InProgress->value])
            ->orderBy('post_title', 'ASC')
            ->limit(-1) // catalog shows ALL active trajectories — not the default page cap
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
            ->where('status', OfferingStatus::Open->value)
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
            $status = OfferingStatus::tryFrom($data['status']);
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
            $data['status'] = OfferingStatus::Draft->value;
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
     * Returns actual WP_Post objects for each required course, with config data attached.
     *
     * @return array<WP_Post>
     */
    public function getRequiredCourses(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);
        $required = array_filter($courses, fn($c) => ($c['required'] ?? false) === true);

        return $this->resolveCoursePosts($required);
    }

    /**
     * Get elective groups for trajectory.
     *
     * Returns groups with actual WP_Post objects, with config data attached.
     *
     * @return array<array{name: string, required: int, courses: array<WP_Post>}>
     */
    public function getElectiveGroups(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);
        $electives = array_filter($courses, fn($c) => ($c['required'] ?? false) === false);

        $groups = [];
        foreach ($electives as $config) {
            $groupName = $config['group'] ?? 'Keuze';
            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'name' => $groupName,
                    'required' => (int) ($config['min_choices'] ?? 0),
                    'courses' => [],
                ];
            }
            $groups[$groupName]['courses'][] = $config;
        }

        // Resolve course posts for each group
        foreach ($groups as $groupName => &$group) {
            $group['courses'] = $this->resolveCoursePosts($group['courses']);
        }

        return array_values($groups);
    }

    /**
     * Find trajectory by slug.
     */
    public function findBySlug(string $slug): ?WP_Post
    {
        $results = $this->model()
            ->where('post_name', $slug)
            ->where('post_status', 'publish')
            ->limit(1)
            ->get();

        if (empty($results)) {
            return null;
        }

        $id = (int) ($results[0]['id'] ?? $results[0]['ID'] ?? 0);

        return $id > 0 ? get_post($id) : null;
    }

    /**
     * Get messages for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getMessages(int $trajectoryId): array
    {
        $messages = $this->getField($trajectoryId, 'trajectory_messages', []);

        if (empty($messages) || !is_array($messages)) {
            return [];
        }

        // Filter deleted and sort by date descending
        $messages = array_filter($messages, fn($m) => empty($m['_deleted']));
        usort($messages, fn($a, $b) => strtotime($b['date'] ?? '') - strtotime($a['date'] ?? ''));

        return $messages;
    }

    /**
     * Resolve course configurations to WP_Post objects.
     *
     * @param array<array<string, mixed>> $courseConfigs
     * @return array<WP_Post>
     */
    private function resolveCoursePosts(array $courseConfigs): array
    {
        // Sort by order
        usort($courseConfigs, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

        $posts = [];
        foreach ($courseConfigs as $config) {
            $courseId = (int) ($config['course_id'] ?? 0);
            if ($courseId <= 0) {
                continue;
            }

            $post = get_post($courseId);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            // Attach config data to post object for template use
            $post->trajectory_config = $config;
            $posts[] = $post;
        }

        return $posts;
    }
}
